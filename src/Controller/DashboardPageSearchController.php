<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Url;
use Drupal\search_api\Entity\Index;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\vactory_dashboard\Service\ContentSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the webform dashboard.
 */
class DashboardPageSearchController extends ControllerBase {

  /**
   * The search service.
   *
   * @var \Drupal\vactory_dashboard\Service\ContentSearchService
   */
  protected $searchService;

  /**
   * Constructs the controller.
   *
   * @param \Drupal\vactory_dashboard\Service\ContentSearchService $searchService
   *   The content search service.
   */
  public function __construct(ContentSearchService $searchService) {
    $this->searchService = $searchService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('vactory_dashboard.content_search')
    );
  }

  public function crossSearch(Request $request) {
    $query = $request->query->get('q');
    $bundle = $request->query->get('bundle');
    return new JsonResponse($this->searchService->crossSearch($query, $bundle));
  }

  /**
   * Endpoint for dashboard search.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   */
  public function search(Request $request) {
    $query = $request->query->get('q');
    return $this->searchService->search($query);
  }

  /**
   * Perform a global search across indexed entities.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request containing the search query.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the search results or an error message.
   */
  public function globalSearch(Request $request) {
    $queryString = $request->query->get('q');
    $entityType = $request->query->get('type');

    if (empty($queryString)) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Query parameter "q" is required.',
      ], 400);
    }

    if (str_starts_with($queryString, '/') || str_starts_with($queryString, 'http') || str_starts_with($queryString, 'https')) {
      $result = $this->searchService->search($queryString);

      return new JsonResponse([
        'status' => 'success',
        'query' => $queryString,
        'results' => $result,
      ]);
    }

    $index = Index::load('vactory_dashboard_search');
    if (!$index) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Search index not found.',
      ], 500);
    }

    $query = $index->query();
    $query->keys($queryString);

    // Add entity type filter if specified by limiting datasources
    if (!empty($entityType) && in_array($entityType, ['node', 'media', 'taxonomy_term'])) {
      // Filter by datasource using the correct Search API method
      $datasources = $query->getIndex()->getDatasources();
      $targetDatasource = 'entity:' . $entityType;
      if (isset($datasources[$targetDatasource])) {
        $query->setOption('search_api_datasource', [$targetDatasource]);
      }
    }

    $range = !empty($entityType) ? 30 : 10;
    $query->range(0, $range);

    $results = $query->execute();
    $items = [];

    // Define entity type priority for sorting
    $entityTypePriority = [
      'node' => 1,
      'media' => 2,
      'taxonomy_term' => 3,
      'user' => 4,
      'webform_submission' => 5,
    ];

    foreach ($results as $item) {
      $original = $item->getOriginalObject()->getValue();

      $isImage = FALSE;
      $id = $original->id();
      $label = $original->label();
      $bundle = $original->bundle();
      $url = $original->toUrl()->toString();
      $entity_type = $original->getEntityTypeId();

      // Skip this item if we're filtering by entity type and it doesn't match
      if (!empty($entityType) && $entity_type !== $entityType) {
        continue;
      }

      $entity_label = \Drupal::entityTypeManager()
        ->getDefinition($entity_type)
        ->getLabel();

      switch ($entity_type) {
        case 'user':
          $url = Url::fromRoute('vactory_dashboard.users')
            ->setAbsolute()
            ->toString();
          break;
        case 'webform_submission':
          $formID = $original->getWebform()->id();
          $formLabel = $original->getWebform()
            ->label(); /* recupere le human readble name */
          $label = "$formLabel - submission id: $id";
          $url = Url::fromRoute('vactory_dashboard.webform.submission', [
            'id' => $formID,
            'submission_id' => $id,
          ])->setAbsolute()->toString();
          break;
        case 'media':
          /* nom du fichier - fallback */
          $label = $original->label();
          $media_bundle = $original->bundle(); /* bundle: image, video, audio, file */

          switch ($media_bundle) {
            case 'image':
              $isImage = TRUE;
              $field = 'field_media_image';
              break;

            case 'video':
              $field = 'field_media_video';
              break;

            case 'audio':
              $field = 'field_media_audio';
              break;

            case 'file':
              $field = 'field_media_file';
              break;

            default:
              $field = NULL;
          }

          /* recupere le nom du fichier et son chemin */
          if ($field && $original->hasField($field) && !$original->get($field)
              ->isEmpty()) {
            $file = $original->get($field)->entity;
            if ($file) {
              $label = $file->getFilename();
              $url = \Drupal::service('file_url_generator')
                ->generateAbsoluteString($file->getFileUri());
            }
          }
          break;
        case 'taxonomy_term':
          $url = Url::fromRoute('vactory_dashboard.taxonomies', ['vid' => $bundle])
            ->setAbsolute()
            ->toString();
          break;
        case 'node':
          $url = Url::fromRoute('vactory_dashboard.node.edit', [
            'bundle' => $bundle,
            'nid' => $id,
          ])->toString();
          if ($bundle == "vactory_page") {
            $url = Url::fromRoute('vactory_dashboard.vactory_page.edit', ['id' => $id])
              ->toString();
          }
          break;

        default:
          break;
      }

      $items[] = [
        'id' => $id,
        'url' => $url,
        'label' => $label,
        'bundle' => $bundle,
        'entity_type' => $entity_type,
        'entity_label' => $entity_label,
        'isImage' => $isImage,
        'priority' => $entityTypePriority[$entity_type] ?? 999,
      ];
    }

    // Sort results by entity type priority, then by label
    usort($items, function($a, $b) {
      if ($a['priority'] === $b['priority']) {
        return strcasecmp($a['label'], $b['label']);
      }
      return $a['priority'] <=> $b['priority'];
    });

    // Remove priority from final results as it's not needed in frontend
    $items = array_map(function($item) {
      unset($item['priority']);
      return $item;
    }, $items);

    // Limit final results to 10 items
    $items = array_slice($items, 0, 10);

    return new JsonResponse([
      'status' => 'success',
      'query' => $queryString,
      'type' => $entityType,
      'results' => $items,
    ]);
  }

}
