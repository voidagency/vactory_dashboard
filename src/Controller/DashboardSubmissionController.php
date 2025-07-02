<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\webform\Entity\Webform;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformInterface;
use Drupal\webform\WebformSubmissionExporterInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\Entity\File;

/**
 * Controller for the webform dashboard.
 */
class DashboardSubmissionController extends ControllerBase
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Webform submission exporter service.
   *
   * Used to generate export files for webform submissions.
   *
   * @var \Drupal\webform\WebformSubmissionExporterInterface
   */
  protected $submissionExporter;

  protected $fileUrlGenerator;

  /**
   * Constructs a new DashboardSubmissionController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebformSubmissionExporterInterface $submissionExporter, FileUrlGeneratorInterface $fileUrlGenerator)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->submissionExporter = $submissionExporter;
    $this->fileUrlGenerator = $fileUrlGenerator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('webform_submission.exporter'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Returns the webform submissions dashboard page.
   *
   * @return array
   *   A render array for the webform dashboard.
   */
  public function content($id, $submission_id)
  {
    return [
      '#theme' => 'vactory_dashboard_submission',
      '#id' => $id,
      '#submission_id' => $submission_id,
    ];
  }

  /**
   * Returns the submission detail page.
   *
   * @return array
   *   A render array for the webform dashboard.
   */
  public function edit($id, $submission_id)
  {
    return [
      '#theme' => 'vactory_dashboard_submission_edit',
      '#id' => $id,
      '#submission_id' => $submission_id,
    ];
  }

  private function human_filesize($bytes, $decimals = 2) {
    $factor = floor((strlen($bytes) - 1) / 3);
    if ($factor > 0) $sz = 'KMGT';
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$sz[$factor - 1] . 'B';
  }

  /**
   * Retrieves and formats a specific webform submission by ID.
   *
   * @param string $id
   *   The Webform ID.
   * @param int $submission_id
   *   The Webform submission ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the submission data or an error message.
   */
  public function getSubmission($id, $submission_id)
  {
    $webform = Webform::load($id);
    $formLabel = $webform->label();
    $submission = WebformSubmission::load($submission_id);

    if (!$webform || !$submission || $submission->getWebform()->id() !== $id) {
      return new JsonResponse(['error' => 'Submission not found or does not belong to the given webform.'], 404);
    }

    $formatted_data = [];
    $data = $submission->getData();

    foreach ($data as $field_name => $field_value) {
      $field_definition = $webform->getElement($field_name);
      $field_type = $field_definition['#type'] ?? 'textfield';
      $label = $field_definition['#title'] ?? $field_name;

      // Handle file fields
      if (in_array($field_type, ['webform_file', 'webform_document_file'])) {
        $files = [];
        $file_ids = is_array($field_value) ? $field_value : [$field_value];

        foreach ($file_ids as $fid) {
          $file = File::load($fid);
          if ($file) {
            $files[] = [
              'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()),
              'filename' => $file->getFilename(),
              'filesize' => $this->human_filesize($file->getSize()),

            ];
          }
        }

        $formatted_data[$field_name] = [
          'name' => $label,
          'value' => count($files) === 1 ? $files[0] : $files,
          'type' => 'file',
        ];
      } else {
        // Handle regular fields
        $formatted_data[$field_name] = [
          'name' => $label,
          'value' => is_array($field_value) ? implode(', ', $field_value) : $field_value,
          'type' => $field_type,
        ];
      }
    }

    $formatted_data['id'] = $submission->id();
    $formatted_data['webform_id'] = $id;
    $formatted_data['label'] = $formLabel;
    $formatted_data['created'] = $submission->getCreatedTime();
    $formatted_data['completed'] = $submission->getCompletedTime();
    $formatted_data['remote_addr'] = $submission->getRemoteAddr();
    $formatted_data['uid'] = $submission->getOwnerId();

    return new JsonResponse($formatted_data);
  }

  /**
   * Delete submissions based on the provided IDs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The response containing a success or error message.
   */
  public function deleteSubmissions(Request $request)
  {
    $content = json_decode($request->getContent(), TRUE);
    $submissionIds = $content['submissionIds'] ?? [];

    if (empty($submissionIds)) {
      return new JsonResponse(['message' => 'No submission specified'], Response::HTTP_BAD_REQUEST);
    }

    try {
      $storage = $this->entityTypeManager->getStorage('webform_submission');
      $submissions = $storage->loadMultiple($submissionIds);
      $storage->delete($submissions);

      return new JsonResponse(['message' => 'Submission deleted successfully']);
    } catch (\Exception $e) {
      return new JsonResponse(['message' => 'Error deleting submission'], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Edits a Webform submission.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $id
   *   The Webform ID.
   * @param int $submission_id
   *   The submission ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the result.
   */
  public function editSubmission(Request $request, $id, $submission_id)
  {
    $data = json_decode($request->getContent(), TRUE);

    if (!$data || !is_array($data)) {
      return new JsonResponse(['error' => 'Invalid data format'], Response::HTTP_BAD_REQUEST);
    }

    $submission = WebformSubmission::load($submission_id);

    if (!$submission || $submission->getWebform()->id() !== $id) {
      return new JsonResponse(['error' => 'Submission not found'], Response::HTTP_NOT_FOUND);
    }

    $current_data = $submission->getData();
    $updated_data = array_merge($current_data, $data);

    $submission->setData($updated_data);
    $submission->save();

    return new JsonResponse([
      'status' => 'success',
      'message' => 'Submission updated.',
    ]);
  }

  /**
   * Exports Webform submissions as a CSV file.
   *
   * @param string $id
   *   The Webform ID.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The CSV file response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the Webform is not found.
   */
  public function exportSubmissions($id)
  {
    $webform = $this->entityTypeManager->getStorage('webform')->load($id);

    if (!$webform instanceof WebformInterface) {
      throw new NotFoundHttpException();
    }

    try {
      $submission_exporter = \Drupal::service('webform_submission.exporter');
      $submission_exporter->setWebform($webform);

      $export_options = $submission_exporter->getDefaultExportOptions();
      $export_options['filename'] = $id;
      $export_options['format'] = 'csv';

      $submission_exporter->setExporter($export_options);
      $submission_exporter->generate();

      $file_path = $submission_exporter->getExportFilePath();

      $response = new BinaryFileResponse($file_path);
      $response->setContentDisposition(
        ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        "$id.csv"
      );

      return $response;
    } catch (\Throwable $th) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'An error occurred during export.',
      ], 500);
    }
  }
}
