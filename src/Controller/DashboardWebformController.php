<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\vactory_dashboard\Service\FormSearchService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\webform\Entity\Webform;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\file\Entity\File;

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
   * Constructs a new DashboardMediaController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, FormSearchService $formSearchService) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->formSearchService = $formSearchService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('vactory_dashboard.form_search'),
    );
  }

  /**
   * Returns the webform dashboard page.
   *
   * @return array
   *   A render array for the webform dashboard.
   */
  public function content($id) {

    return [
      '#theme' => 'vactory_dashboard_webform',
      '#id' => $id,
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

    if ($webform && $webform->hasSubmissions()) {
      // Get total count
      $count_query = \Drupal::entityQuery('webform_submission')
        ->accessCheck(FALSE)
        ->condition('webform_id', $id);
      $total = $count_query->count()->execute();

      // Get paginated submissions
      $query = \Drupal::entityQuery('webform_submission')
        ->accessCheck(FALSE)
        ->condition('webform_id', $id)
        ->sort('created', 'DESC')
        ->range($offset, $limit);

      $result = $query->execute();

      foreach ($result as $item) {
        $submission = \Drupal\webform\Entity\WebformSubmission::load($item);
        if ($submission) {
          $submission_data = $submission->getData();

          foreach ($submission_data as $field_name => $field_value) {
            $element = $webform->getElement($field_name);
            $type = $element['#type'] ?? 'textfield';

            if ($type === 'webform_file' || $type === 'webform_document_file') {
              $urls = [];
              $files = [];
              $file_ids = is_array($field_value) ? $field_value : [$field_value];
              foreach ($file_ids as $fid) {
                $file = File::load($fid);
                if ($file) {
                  $files[] = [
                    'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()),
                    'filename' => $file->getFilename(),
                  ];
                }
              }
              $submission_data[$field_name] = [
                'value' => count($files) === 1 ? $files[0] : $files,
                'type' => "file",
              ];
            }
            else {
              $submission_data[$field_name] = is_array($field_value) ? implode(', ', $field_value) : $field_value;
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

    return new JsonResponse([
      'data' => $data,
      'form_id' => $id,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => ceil($total / $limit),
    ]);
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
