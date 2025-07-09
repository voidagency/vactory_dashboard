<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the taxonomy calls dashboard.
 */
class DashboardTaxonomiesController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new DashboardTendersController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Returns the tender calls dashboard page.
   *
   * @return array
   *   A render array for the tender calls dashboard.
   */
  public function content($vid) {
    if (!$this->entityTypeManager->getStorage('taxonomy_vocabulary')
      ->load($vid)) {
      throw new NotFoundHttpException('Taxonomy vocabulary not found.');
    }

    $languages = \Drupal::languageManager()->getLanguages();
    $langs = [];
    foreach ($languages as $lang) {
      $langs[$lang->getId()] = $lang->getName();
    }

    return [
      '#theme' => 'vactory_dashboard_taxonomies',
      '#taxonomy_vid' => $vid,
      '#langs' => $langs,
      '#default_lang' => \Drupal::languageManager()
        ->getDefaultLanguage()
        ->getId(),
    ];
  }

  /**
   * Returns paginated taxonomy data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $vid
   *   The vocabulary ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with taxonomy data.
   */
  public function getTaxonomyData(Request $request, string $vid) {
    $page = $request->query->get('page', 1);
    $limit = $request->query->get('limit', 10);
    $search = $request->query->get('search', '');
    $status = $request->query->get('status', '');

    // Get current language
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();
    $default_language = \Drupal::languageManager()
      ->getDefaultLanguage()
      ->getId();

    // Create a clone of the query for counting.
    $count_query = $this->entityTypeManager->getStorage('taxonomy_term')
      ->getQuery();
    $count_query->accessCheck(FALSE);
    $count_query->condition('vid', $vid);

    // Create the main query for fetching taxonomy terms
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('vid', $vid);

    // Apply filters to both queries.
    if (!empty($search)) {
      $query->condition('name', $search, 'CONTAINS');
      $count_query->condition('name', $search, 'CONTAINS');
    }

    if (!empty($status)) {
      $query->condition('status', $status === 'active' ? 1 : 0, '=');
      $count_query->condition('status', $status === 'active' ? 1 : 0, '=');
    }

    $this->addFilters($request, $query, $count_query, $vid);

    // Sort the result by created date.
    $query->sort('changed', 'DESC');
    $query->sort('tid', 'DESC');

    // Get total count for pagination.
    $total = $count_query->count()->execute();

    // Add pager to the main query.
    $query->range(($page - 1) * $limit, $limit);

    // Get taxonomy term IDs.
    $tids = $query->execute();

    // Load taxonomy terms with translations
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadMultiple($tids);

    $data = [];
    foreach ($terms as $term) {
      /** @var \Drupal\taxonomy\Entity\Term $term */
      // Get the translated version if available
      if ($term->hasTranslation($current_language)) {
        $term = $term->getTranslation($current_language);
      }

      $item = [
        'id' => $term->id(),
        'name' => $term->getName(),
        'description' => $term->getDescription(),
        'status' => $term->status->value,
        'langcode' => $term->language()->getId(),
        'create' => $this->currentUser()->hasPermission("create terms in $vid"),
        'edit' => $this->currentUser()->hasPermission("edit terms in $vid"),
        'delete' => $this->currentUser()->hasPermission("delete terms in $vid"),
      ];
      $this->retrieveCustomFields($term, $vid, $item);
      $data[] = $item;
    }

    return new JsonResponse([
      'data' => $data,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => ceil($total / $limit),
    ]);
  }

  /**
   * Retrieves custom fields for a taxonomy term.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The taxonomy term entity.
   * @param string $vid
   *   The vocabulary ID.
   * @param array &$item
   *   The item array to modify.
   */
  protected function retrieveCustomFields($term, $vid, &$item) {
    if ($vid === "modele") {
      $item['marque'] = $term->field_marque->target_id;
    }
  }

  /**
   * Adds additional filters to the query based on the request parameters.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The query object to modify.
   * @param \Drupal\Core\Entity\Query\QueryInterface $count_query
   *   The query object to modify.
   * @param string $vid
   *   The vocabulary ID.
   */
  protected function addFilters(Request $request, $query, $count_query, string $vid) {
    // Add custom filters.
  }

  /**
   * Deletes multiple taxonomies calls.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */

  public function delete(Request $request, $vid) {
    $content = json_decode($request->getContent(), TRUE);
    $ids = $content['ids'] ?? [];

    if (empty($ids)) {
      return new JsonResponse(['message' => 'No terms specified'], Response::HTTP_BAD_REQUEST);
    }

    try {
      $storage = $this->entityTypeManager->getStorage('taxonomy_term');
      $terms = $storage->loadMultiple($ids);

      // Vérifiez que les termes appartiennent au vocabulaire spécifié.
      foreach ($terms as $term) {
        if ($term->bundle() !== $vid) {
          return new JsonResponse(['message' => 'Invalid term for this vocabulary'], Response::HTTP_BAD_REQUEST);
        }
      }

      $storage->delete($terms);

      return new JsonResponse(['message' => 'Terms deleted successfully']);
    }
    catch (\Exception $e) {
      return new JsonResponse(['message' => 'Error deleting terms: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Adds a new taxonomy term.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $vid
   *   The vocabulary ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function add(Request $request, string $vid) {
    // Check if user has permission to create terms in this vocabulary.
    if (
      !$this->currentUser()->hasPermission('create terms in ' . $vid)
    ) {
      return new JsonResponse(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
    }

    $content = json_decode($request->getContent(), TRUE);
    $name = $content['name'] ?? '';
    $description = $content['description'] ?? '';
    $lang = $content['lang'] ?? '';

    if (empty($name)) {
      return new JsonResponse(['message' => 'Name is required'], Response::HTTP_BAD_REQUEST);
    }

    try {
      // Create new term.
      /** @var \Drupal\taxonomy\Entity\Term $term */
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
        'vid' => $vid,
        'name' => $name,
        'langcode' => $lang,
        'description' => $description,
        'status' => 1,
      ]);

      $this->addCustomFields($term, $vid, $content);

      $term->save();

      return new JsonResponse([
        'message' => 'Term created successfully',
        'term' => [
          'id' => $term->id(),
          'name' => $term->getName(),
          'description' => $term->getDescription(),
        ],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['message' => 'Error creating term'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Edits a taxonomy term.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $vid
   *   The vocabulary ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function edit(Request $request, string $vid) {
    $content = json_decode($request->getContent(), TRUE);
    $id = $content['id'] ?? '';
    $name = $content['name'] ?? '';
    $description = $content['description'] ?? '';

    /** @var \Drupal\taxonomy\Entity\Term $term */
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($id);
    $term->setName($name);
    $term->setDescription($description);
    $this->addCustomFields($term, $vid, $content);
    $term->save();

    return new JsonResponse([
      'message' => 'Term updated successfully',
      'term' => [
        'id' => $term->id(),
      ],
    ]);
  }

  /**
   * Adds custom fields to a taxonomy term.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The taxonomy term entity.
   * @param string $vid
   *   The vocabulary ID.
   * @param array $content
   *   The content array.
   */
  protected function addCustomFields($term, $vid, $content) {
    // Add custom fields.
  }

}
