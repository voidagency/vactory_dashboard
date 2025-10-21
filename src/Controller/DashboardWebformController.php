<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Database\Connection;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Url;
use Drupal\vactory_dashboard\Service\FormSearchService;
use Drupal\vactory_dashboard\Service\WebformService;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform\Entity\Webform;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\file\Entity\File;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for the webform dashboard.
 */
class DashboardWebformController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The form search service.
   *
   * Provides functionality to search or filter webform submissions.
   *
   * @var \Drupal\vactory_dashboard\Service\FormSearchService
   */
  protected $formSearchService;

  /**
   * The webform service.
   *
   * @var \Drupal\vactory_dashboard\Service\WebformService
   */
  protected $webformService;

  /**
   * Constructs a new DashboardMediaController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, FormSearchService $formSearchService, WebformService $webformService) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->formSearchService = $formSearchService;
    $this->webformService = $webformService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('vactory_dashboard.form_search'),
      $container->get('vactory_dashboard.webform.service')
    );
  }

  /**
   * Returns the webform dashboard page.
   *
   * @return array
   *   A render array for the webform dashboard.
   */
  public function content($id) {
    $webform = Webform::load($id);
    if (!$webform instanceof WebformInterface) {
      throw new NotFoundHttpException();
    }

    $shouldHideExportButton = FALSE;
    if ($this->moduleHandler()->moduleExists('vactory_webform_anonymize')) {
      if (\Drupal::service('vactory_webform_anonymize.helper')->shouldAnonymize($webform)) {
        $shouldHideExportButton = TRUE;
      }
    }
    return [
      '#theme' => 'vactory_dashboard_webform',
      '#id' => $id,
      '#hideExportButton' => $shouldHideExportButton,
      '#attached' => [
        'library' => ['vactory_dashboard/alpine-webform'],
        'drupalSettings' => [
          'vactoryDashboard' => [
            'deletePath' => Url::fromRoute('vactory_dashboard.webform.delete')
              ->toString(),
            'dataPath' => Url::fromRoute('vactory_dashboard.webform.data', ['id' => $id])
              ->toString(),
            'searchPath' => Url::fromRoute('vactory_dashboard.webform.search', ['id' => $id])
              ->toString(),
          ],
        ],
      ],
    ];
  }

  /**
   * Get paginated submissions for a webform.
   *
   * @param string $id
   *   The webform ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing paginated submissions.
   */
  public function getSubmissions($id, Request $request) {
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = (int) $request->query->get('limit', 10);
    $offset = ($page - 1) * $limit;

    $webform = Webform::load($id);
    $data = [];
    $total = 0;

    $cacheMetadata = new CacheableMetadata();
    $cacheMetadata->addCacheableDependency($webform);

    if ($webform && $webform->hasSubmissions()) {
      $count_query = \Drupal::entityQuery('webform_submission')
        ->accessCheck(FALSE)
        ->condition('webform_id', $id);
      $total = $count_query->count()->execute();

      $query = \Drupal::entityQuery('webform_submission')
        ->accessCheck(FALSE)
        ->condition('webform_id', $id)
        ->sort('created', 'DESC')
        ->range($offset, $limit);

      $result = $query->execute();
      $settings = $webform->getThirdPartySetting('vactory_webform_anonymize', 'settings', []);

      $renderer = \Drupal::service('renderer');

      foreach ($result as $item) {
        $submission = WebformSubmission::load($item);
        if ($submission) {
          // Add submission to cache metadata
          $cacheMetadata->addCacheableDependency($submission);

          $submission_data = $submission->getData();

          foreach ($submission_data as $field_name => $field_value) {
            $element = $webform->getElement($field_name);
            $type = $element['#type'] ?? 'textfield';

            if ($type === 'webform_file' || $type === 'webform_document_file') {
              $files = [];
              $file_ids = is_array($field_value) ? $field_value : [$field_value];

              foreach ($file_ids as $fid) {
                $file = File::load($fid);
                if ($file) {
                  // Add file to cache metadata
                  $cacheMetadata->addCacheableDependency($file);

                  // Execute file URL generation in a render context to capture metadata
                  $context = new RenderContext();
                  $file_url = $renderer->executeInRenderContext($context, function() use ($file) {
                    return \Drupal::service('file_url_generator')
                      ->generateAbsoluteString($file->getFileUri());
                  });

                  // Merge any captured metadata
                  if (!$context->isEmpty()) {
                    $cacheMetadata = $cacheMetadata->merge($context->pop());
                  }

                  $files[] = [
                    'url' => $this->webformService->anonymizeData($webform, $settings, $file_url),
                    'filename' => $this->webformService->anonymizeData($webform, $settings, $file->getFilename()),
                  ];
                }
              }
              $submission_data[$field_name] = [
                'value' => count($files) === 1 ? $files[0] : $files,
                'type' => "file",
              ];
            }
            else {
              if (is_array($field_value)) {
                $field_value = array_map(function($item) use ($webform, $settings) {
                  return $this->webformService->anonymizeData($webform, $settings, $item);
                }, $field_value);
              }
              $submission_data[$field_name] = is_array($field_value)
                ? implode(', ', $field_value)
                : $this->webformService->anonymizeData($webform, $settings, $field_value);
            }
          }

          $data[] = [
            'id' => $submission->id(),
            'created' => $submission->getCreatedTime(),
            'completed' => $submission->getCompletedTime(),
            'remote_addr' => $submission->getRemoteAddr(),
            'uid' => $submission->getOwnerId(),
            'data' => $submission_data,
          ];
        }
      }
    }

    $response = new CacheableJsonResponse([
      'data' => $data,
      'form_id' => $id,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => ceil($total / $limit),
    ]);

    $response->addCacheableDependency($cacheMetadata);

    return $response;
  }

  /**
   * Searches webform submissions with optional filters and pagination.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing query parameters and search keys.
   * @param string $id
   *   The webform ID to search submissions from.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing paginated search results.
   */
  public function searchWebformSubmissions(Request $request, $id): JsonResponse {
    $operator = 'CONTAINS';
    $q = $request->query->get('q', '');

    // Pagination
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = (int) $request->query->get('limit', 10);
    $offset = ($page - 1) * $limit;

    $reqBody = json_decode($request->getContent(), TRUE);
    $keys = $reqBody["keys"] ?? [];

    $sids = $this->formSearchService->getMatchingSubmissionIds($id, $q, $keys, $operator);

    $total = count($sids);

    $paged_sids = array_slice($sids, $offset, $limit);

    $submissions = $this->formSearchService->getSubmissionsByIds($paged_sids, $id);

    return new JsonResponse([
      'data' => $submissions,
      'form_id' => $id,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => ceil($total / $limit),
    ]);
  }

}
