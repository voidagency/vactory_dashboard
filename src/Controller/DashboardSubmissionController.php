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
use Drupal\Core\Cache\CacheBackendInterface;

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
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a new DashboardSubmissionController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebformSubmissionExporterInterface $submissionExporter, FileUrlGeneratorInterface $fileUrlGenerator,CacheBackendInterface $cache)
  {
    $this->entityTypeManager = $entity_type_manager;
    $this->submissionExporter = $submissionExporter;
    $this->fileUrlGenerator = $fileUrlGenerator;
    $this->cache = $cache;
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
      $container->get('cache.default'),
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
   * Démarre le batch export.
   */
  public function startBatchExport($webform_id) {
    $webform = Webform::load($webform_id);
    if (!$webform) {
      return new JsonResponse(['error' => 'Webform introuvable'], 404);
    }

    $cache_id = "export_progress_{$webform_id}";

    // Initialiser état export
    $data = [
      'progress' => 0,
      'total' => 0,
      'done' => 0,
      'status' => 'starting',
      'filepath' => '',
      'message' => 'Export démarré',
    ];
    $this->cache->set($cache_id, $data);

    // Récupérer toutes les soumissions à exporter (id uniquement)
    $storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
    $query = $storage->getQuery()
     ->condition('webform_id', $webform_id)
     ->accessCheck(FALSE)
     ->sort('sid', 'ASC');

    $submission_ids = $query->execute();

    $data['total'] = count($submission_ids);

    // Fichier CSV temporaire
    $tmp_dir = sys_get_temp_dir();
    $file_path = $tmp_dir . "/export_{$webform_id}_" . uniqid() . ".csv";

    // Enregistrer dans cache l’état export initial
    $data['filepath'] = $file_path;
    $this->cache->set($cache_id, $data);

    // Stocker la liste des ids à traiter 
    $this->cache->set("export_ids_{$webform_id}", $submission_ids);

    return new JsonResponse(['message' => 'Export démarré', 'total' => $data['total']]);
  }

  /**
   * Effectue un "pas" d’export.
   */
  public function processBatchExport($webform_id) {
    $cache_id = "export_progress_{$webform_id}";
    $data = $this->cache->get($cache_id);

    if (!$data) {
      return new JsonResponse(['error' => 'Aucun export en cours'], 400);
    }

    if ($data->data['status'] === 'finished') {
      return new JsonResponse(['message' => 'Export terminé']);
    }

    // Charge ids
    $cache_ids = $this->cache->get("export_ids_{$webform_id}");
    if (!$cache_ids) {
      return new JsonResponse(['error' => 'Liste de soumissions introuvable'], 400);
    }
    $submission_ids = $cache_ids->data;

    // Ouvrir fichier CSV en append 
    $filepath = $data->data['filepath'];
    $handle = fopen($filepath, $data->data['done'] === 0 ? 'w' : 'a');
    if (!$handle) {
      return new JsonResponse(['error' => 'Impossible d\'ouvrir le fichier CSV'], 500);
    }

    // Nombre à traiter par pas (batch size)
    $batch_size = 100;

    // Extraire le prochain lot d’IDs à traiter
    $chunk = array_splice($submission_ids, 0, $batch_size);

    if ($data->data['done'] === 0 && count($chunk) > 0) {
      $first_sub = WebformSubmission::load($chunk[0]);
      if ($first_sub) {
        $first_data = $first_sub->getData();
        fputcsv($handle, array_keys($first_data));
      }
    }

    // Charger les entités de soumission
    $submissions = WebformSubmission::loadMultiple($chunk);

    foreach ($submissions as $sub) {
      $row = [];
      $data_row = $sub->getData();

      $excludedFields = [
        'id',
        'ip',
        'csrfToken',
        'completed',
        'csrf_token',
        'g-recaptcha-response',
        'in_draft',
        'created',
        'webform_id',
        'uid',
        'remote_addr',
      ];
      
      foreach ($data_row as $value) {
        if (is_array($value)) {
          $row[] = implode(', ', $value);
        }
        else {
          $row[] = $value;
        }
      }
      fputcsv($handle, $row);
    }
    fclose($handle);

    // Mise à jour data cache : soumissions restantes, progression
    $data->data['done'] += count($chunk);
    $data->data['progress'] = ($data->data['done'] / $data->data['total']) * 100;

    $data->data['status'] = count($submission_ids) > 0 ? 'processing' : 'finished';

    // Sauvegarder reste des IDs à traiter
    $this->cache->set("export_ids_{$webform_id}", $submission_ids);
    $this->cache->set($cache_id, $data->data);

    return new JsonResponse([
      'progress' => round($data->data['progress']),
      'done' => $data->data['done'],
      'total' => $data->data['total'],
      'status' => $data->data['status'],
      'filepath' => $filepath,
    ]);
  }

  /**
   * Téléchargement du fichier CSV à l’issue de l’export.
   */
  public function downloadBatchExport($webform_id) {
    $cache_id = "export_progress_{$webform_id}";
    $data = $this->cache->get($cache_id);

    if (!$data || $data->data['status'] !== 'finished') {
      return new JsonResponse(['error' => 'Export non terminé ou fichier manquant'], 404);
    }

    $file_path = $data->data['filepath'];

    if (!file_exists($file_path)) {
      return new JsonResponse(['error' => 'Fichier export introuvable'], 404);
    }

    $response = new BinaryFileResponse($file_path);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($file_path));
    return $response;
  }
}




