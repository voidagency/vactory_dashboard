<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\user\UserInterface;
use Drupal\user\Entity\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Controller for the users dashboard.
 */
class DashboardUsersController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new DashboardUsersController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * @return bool
   *   TRUE if the user is an admin, FALSE otherwise.
   */
  protected function isCurrentUserAdmin() {
    return in_array('administrator', $this->currentUser->getRoles());
  }

  /**
   * Returns the users dashboard page.
   *
   * @return array
   *   A render array for the users dashboard.
   */
  public function content() {
    $roles = Role::loadMultiple();
    $role_options = [];
    foreach ($roles as $role_id => $role) {
      if ($role_id !== 'anonymous' && $role_id !== 'authenticated') {
        $role_options[$role_id] = $role->label();
      }
    }

    return [
      '#theme' => 'vactory_dashboard_users',
      '#title' => $this->t('Users'),
      '#attached' => [
        'library' => ['vactory_dashboard/alpine-users-list'],
        'drupalSettings' => [
          'vactoryDashboard' => [
            'deletePath' => Url::fromRoute('vactory_dashboard.users.delete')
              ->toString(),
            'dataPath' => Url::fromRoute('vactory_dashboard.users.data')
              ->toString(),
          ],
        ],
      ],
    ];
  }

  /**
   * Returns paginated users data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with users data.
   */
  public function getUsers(Request $request) {
    $page = $request->query->get('page', 1);
    $limit = $request->query->get('limit', 10);
    $search = $request->query->get('search', '');
    $role = $request->query->get('role', '');
    $status = $request->query->get('status', '');
    $sort_by = $request->query->get('sort_by', 'access');
    $sort_order = $request->query->get('sort_order', 'desc');

    // Create a clone of the query for counting.
    $count_query = $this->entityTypeManager->getStorage('user')->getQuery();
    $count_query->accessCheck(TRUE);
    $count_query->condition('uid', 1, '>');

    // Create the main query for fetching users.
    $query = $this->entityTypeManager->getStorage('user')->getQuery();
    $query->accessCheck(TRUE);
    $query->condition('uid', 1, '>');

    // Apply filters to both queries.
    if (!empty($search)) {
      $or = $query->orConditionGroup()
        ->condition('name', $search, 'CONTAINS')
        ->condition('mail', $search, 'CONTAINS');
      $query->condition($or);
      $count_query->condition($or);
    }

    if (!empty($role)) {
      $query->condition('roles', $role, '=');
      $count_query->condition('roles', $role, '=');
    }

    if (!empty($status)) {
      $query->condition('status', $status === 'active' ? 1 : 0, '=');
      $count_query->condition('status', $status === 'active' ? 1 : 0, '=');
    }

    // Get total count for pagination.
    $total = $count_query->count()->execute();

    // Add sorting to the main query.
    if (!empty($sort_by) && in_array(strtolower($sort_order), [
        'asc',
        'desc',
      ])) {
      $query->sort($sort_by, $sort_order);
    }

    // Add pager to the main query.
    $query->range(($page - 1) * $limit, $limit);

    // Get user IDs.
    $uids = $query->execute();

    // Load users.
    $users = $this->entityTypeManager->getStorage('user')->loadMultiple($uids);

    $data = [];
    $date_formatter = \Drupal::service('date.formatter');
    foreach ($users as $user) {
      /** @var \Drupal\user\UserInterface $user */
      $roles = $user->getRoles(TRUE);
      $role_names = array_map(function($role) {
        return $this->t($role);
      }, $roles);

      $roles = Role::loadMultiple();
      $role_options = [];
      foreach ($roles as $role_id => $role) {
        if ($role_id !== 'anonymous' && $role_id !== 'authenticated') {
          $role_options[$role_id] = $role->label();
        }
      }

      $currentUser = $this->currentUser;
      $user_roles = $currentUser->getRoles();

      $last_access = $user->getLastAccessedTime();
      $last_access_formatted = $last_access ? $date_formatter->format($last_access, 'short') : $this->t('Never');

      $data[] = [
        'id' => $user->id(),
        'name' => $user->getDisplayName(),
        'email' => $user->getEmail(),
        'roles' => implode(', ', $role_names),
        'status' => $user->isActive() ? 'active' : 'inactive',
        'status_label' => $user->isActive() ? $this->t('Active') : $this->t('Inactive'),
        'last_access' => $last_access_formatted,
      ];
    }

    // Only load roles if user is admin
    $role_options = [];
    if ($this->isCurrentUserAdmin()) {
      $roles = Role::loadMultiple();
      foreach ($roles as $role_id => $role) {
        if ($role_id !== 'anonymous' && $role_id !== 'authenticated') {
          $role_options[$role_id] = $role->label();
        }
      }
    }

    return new JsonResponse([
      'data' => $data,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => ceil($total / $limit),
      'roles' => $role_options,
      'current_user_role' => $user_roles,

    ]);
  }

  /**
   * Deletes multiple users.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function deleteUsers(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $userIds = $content['userIds'] ?? [];

    if (in_array(1, $userIds)) {
      return new JsonResponse(['message' => 'Action not permitted', 401]);
    }

    if (empty($userIds)) {
      return new JsonResponse(['message' => 'No users specified'], Response::HTTP_BAD_REQUEST);
    }

    try {
      $storage = $this->entityTypeManager->getStorage('user');
      $users = $storage->loadMultiple($userIds);
      $storage->delete($users);

      return new JsonResponse(['message' => 'Users deleted successfully']);
    }
    catch (\Exception $e) {
      return new JsonResponse(['message' => 'Error deleting users'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Returns the user update page.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return array
   *   A render array for the user update page.
   */
  public function pageUpdate($userId) {
    $roles = Role::loadMultiple();
    $role_options = [];
    foreach ($roles as $role_id => $role) {
      if ($role_id !== 'anonymous' && $role_id !== 'authenticated') {
        $role_options[$role_id] = $role->label();
      }
    }
    // Check if the user exists
    $user = $this->entityTypeManager->getStorage('user')->load($userId);
    if (!$user) {
      throw new NotFoundHttpException('User not found.');
    }

    $user_data = [
      'id' => $user->id(),
      'name' => $user->getDisplayName(),
      'email' => $user->getEmail(),
      'roles' => $user->getRoles(),
      'status' => $user->isActive(),
      'status_label' => $user->isActive() ? $this->t('Active') : $this->t('Inactive'),
    ];

    $currentUser = $this->entityTypeManager->getStorage('user')->load($userId);

    if (!$currentUser instanceof UserInterface) {
      throw new NotFoundHttpException();
    }

    // Render the page with the user ID
    return [
      '#theme' => 'vactory_dashboard_update_user',
      '#userId' => $userId, // Passing userId to the template
      '#user_data' => $user_data,
      '#roles' => $role_options,
      '#attached' => [
        'library' => ['vactory_dashboard/alpine-users-edit'],
        'drupalSettings' => [
          'vactoryDashboard' => [
            'editPath' => Url::fromRoute('vactory_dashboard.settings.user.edit', ['userId' => $userId])
              ->toString(),
            'listPath' => Url::fromRoute('vactory_dashboard.users')
              ->toString(),
          ],
        ],
      ],
    ];
  }

  /**
   * Returns the edit user page.
   *
   * @param int $userId
   *   The user ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with user data.
   */
  public function editUserPage($userId) {
    $user = $this->entityTypeManager->getStorage('user')->load($userId);

    if (!$user) {
      throw new NotFoundHttpException('Utilisateur non trouvé.');
    }

    $roles = Role::loadMultiple();
    // Only load roles if user is admin
    if ($userId == 1) {
      throw new NotFoundHttpException();
    }
    $role_options = [];
    if ($this->isCurrentUserAdmin()) {
      $roles = Role::loadMultiple();
      foreach ($roles as $role_id => $role) {
        if ($role_id !== 'anonymous' && $role_id !== 'authenticated') {
          $role_options[$role_id] = $role->label();
        }
      }
    }

    $user_data = [
      'id' => $user->id(),
      'name' => $user->getDisplayName(),
      'email' => $user->getEmail(),
      'roles' => $user->getRoles(),
      'status' => $user->isActive(),
      'status_label' => $user->isActive() ? $this->t('Active') : $this->t('Inactive'),
    ];

    return new JsonResponse([
      'user' => $user_data,
      'roles' => $role_options,
    ]);
  }

  /**
   * Edits a user.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param int $userId
   *   The user ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function editUser(Request $request, $userId) {
    $data = json_decode($request->getContent(), TRUE);

    if (!$data) {
      return new JsonResponse(['message' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
    }

    // Validation des champs
    $validator = Validation::createValidator();
    $violations = [];

    // Username : si défini
    if (isset($data['name'])) {
      $nameViolations = $validator->validate($data['name'], [
        new Assert\NotBlank(),
        new Assert\Length(['min' => 4, 'max' => 64]),
      ]);

      if (count($nameViolations)) {
        $violations['name'] = (string) $nameViolations[0]->getMessage();
      }
      elseif (!preg_match("/^[A-Za-z0-9 .@'_-]+$/", $data['name'])) {
        $violations['name'] = "Invalid username. Only letters, numbers, spaces, ., -, _, @, and apostrophes are allowed.";
      }
    }

    // Email : si défini
    if (isset($data['email'])) {
      $emailViolations = $validator->validate($data['email'], [
        new Assert\NotBlank(),
        new Assert\Email(),
      ]);

      if (count($emailViolations)) {
        $violations['email'] = (string) $emailViolations[0]->getMessage();
      }
    }

    // Si erreurs, retour immédiat
    if (!empty($violations)) {
      return new JsonResponse([
        'message' => 'Validation failed',
        'errors' => $violations,
      ], Response::HTTP_BAD_REQUEST);
    }

    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager->getStorage('user')->load($userId);
    if (!$user) {
      return new JsonResponse(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
    }

    // Mise à jour des champs si présents
    if (isset($data['name'])) {
      $user->setUsername($data['name']);
    }

    if (isset($data['email'])) {
      $user->setEmail($data['email']);
    }

    if (isset($data['roles'])) {
      // Security check: prevent non-admin users from modifying roles at all
      if (!$this->isCurrentUserAdmin()) {
        return new JsonResponse([
          'message' => 'Access denied: You cannot modify user roles',
          'errors' => ['roles' => 'You do not have permission to modify user roles'],
        ], Response::HTTP_FORBIDDEN);
      }

      $user->set('roles', $data['roles']);
    }

    if (isset($data['status'])) {
      $user->set('status', (bool) $data['status']);
    }

    $user->save();

    return new JsonResponse(['message' => 'User updated successfully']);
  }

}
