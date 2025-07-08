<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\user\Entity\Role;

/**
 * Controller for the profile dashboard.
 */
class DashboardProfileController extends ControllerBase {

  /**
   * The session manager.
   *
   * @var \Drupal\Core\Session\SessionManagerInterface
   */
  protected SessionManagerInterface $sessionManager;

  /**
   * Constructs the UserDashboardController object.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user service.
   * @param \Drupal\Core\Session\SessionManagerInterface $session_manager
   *   The session manager service.
   */
  public function __construct(AccountProxyInterface $current_user, SessionManagerInterface $session_manager) {
    $this->currentUser = $current_user;
    $this->sessionManager = $session_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('current_user'),
      $container->get('session_manager'),
    );
  }

  /**
   * Returns the current user's info as JSON.
   *
   * Used for frontend requests (e.g. via Alpine.js).
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with name, email, and roles.
   */
  public function getCurrentUserInfo(): JsonResponse {
    $account = $this->currentUser->getAccount();

    // Filtrer les rôles (exclure 'authenticated')
  $role_ids = array_filter($account->getRoles(), function ($role) {
    return $role !== 'authenticated';
  });

  // Charger les rôles avec leur label
  $roles = array_map(function ($role_id) {
    $role = Role::load($role_id);
    return [
      'id' => $role_id,
      'label' => $role->label(),
    ];
  }, $role_ids);

    return new JsonResponse([
      'name' => $account->getDisplayName(),
      'email' => $account->getEmail(),
      'roles' => $roles,
      'hasAdvancedRoleAccess' => $account->hasPermission('access drupal advanced mode'),
    ]);
  }

  /**
   * Logs out the current user and returns a redirect URL.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing a redirect URL.
   */
  public function logout(): JsonResponse {
    if ($this->currentUser->isAuthenticated()) {
      \Drupal::moduleHandler()->invokeAll('user_logout', [$this->currentUser]);
      $this->sessionManager->destroy();

      // Replace current user with anonymous.
      $this->currentUser->setAccount(new \Drupal\Core\Session\AnonymousUserSession());
    }

    return new JsonResponse([
      'redirect' => Url::fromRoute('<front>', [], ['absolute' => TRUE])
        ->toString(),
    ]);
  }

}
