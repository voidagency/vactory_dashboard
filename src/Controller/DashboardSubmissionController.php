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
class DashboardSubmissionController extends ControllerBase {

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
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebformSubmissionExporterInterface $submissionExporter, FileUrlGeneratorInterface $fileUrlGenerator, CacheBackendInterface $cache) {
    $this->entityTypeManager = $entity_type_manager;
    $this->submissionExporter = $submissionExporter;
    $this->fileUrlGenerator = $fileUrlGenerator;
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
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
  public function content($id, $submission_id) {
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
  public function edit($id, $submission_id) {
    return [
      '#theme' => 'vactory_dashboard_submission_edit',
      '#id' => $id,
      '#submission_id' => $submission_id,
    ];
  }

  private function human_filesize($bytes, $decimals = 2) {
    $factor = floor((strlen($bytes) - 1) / 3);
    if ($factor > 0) {
      $sz = 'KMGT';
    }
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
  public function getSubmission($id, $submission_id) {
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
              'url' => \Drupal::service('file_url_generator')
                ->generateAbsoluteString($file->getFileUri()),
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
      }
      else {
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
  public function deleteSubmissions(Request $request) {
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
    }
    catch (\Exception $e) {
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
  public function editSubmission(Request $request, $id, $submission_id) {
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

    // Récupérer toutes les soumissions à exporter
    $submission_ids = $this->getSubmissionIds($webform_id);
    
    if (empty($submission_ids)) {
      return new JsonResponse(['error' => 'Aucune soumission à exporter'], 400);
    }

    // Initialiser état export
    $export_data = [
      'progress' => 0,
      'total' => count($submission_ids),
      'done' => 0,
      'status' => 'starting',
      'filepath' => $this->createTempFile($webform_id),
      'message' => 'Export démarré',
    ];

    // Enregistrer dans cache
    $this->cache->set($cache_id, $export_data);
    $this->cache->set("export_ids_{$webform_id}", $submission_ids);

    return new JsonResponse([
      'message' => 'Export démarré',
      'total' => $export_data['total'],
    ]);
  }

  /**
   * Effectue un "pas" d'export.
   */
  public function processBatchExport($webform_id) {
    $cache_id = "export_progress_{$webform_id}";
    $export_data = $this->cache->get($cache_id);

    if (!$export_data) {
      return new JsonResponse(['error' => 'Aucun export en cours'], 400);
    }

    if ($export_data->data['status'] === 'finished') {
      return new JsonResponse(['message' => 'Export terminé']);
    }

    // Récupérer les IDs restants
    $submission_ids = $this->getRemainingSubmissionIds($webform_id);
    if (!$submission_ids) {
      return new JsonResponse(['error' => 'Liste de soumissions introuvable'], 400);
    }

    // Traiter le prochain lot
    $batch_size = 50;
    $chunk = array_splice($submission_ids, 0, $batch_size);
    
    $filepath = $export_data->data['filepath'];
    $is_first_batch = $export_data->data['done'] === 0;
    
    $this->processSubmissionChunk($chunk, $filepath, $is_first_batch);

    // Mettre à jour la progression
    $export_data->data['done'] += count($chunk);
    $export_data->data['progress'] = ($export_data->data['done'] / $export_data->data['total']) * 100;
    $export_data->data['status'] = count($submission_ids) > 0 ? 'processing' : 'finished';

    // Sauvegarder l'état
    $this->cache->set("export_ids_{$webform_id}", $submission_ids);
    $this->cache->set($cache_id, $export_data->data);

    return new JsonResponse([
      'progress' => round($export_data->data['progress']),
      'done' => $export_data->data['done'],
      'total' => $export_data->data['total'],
      'status' => $export_data->data['status'],
      'filepath' => $filepath,
    ]);
  }

  /**
   * Téléchargement du fichier CSV à l'issue de l'export.
   */
  public function downloadBatchExport($webform_id) {
    $cache_id = "export_progress_{$webform_id}";
    $export_data = $this->cache->get($cache_id);

    if (!$export_data || $export_data->data['status'] !== 'finished') {
      return new JsonResponse(['error' => 'Export non terminé ou fichier manquant'], 404);
    }

    $file_path = $export_data->data['filepath'];

    if (!file_exists($file_path)) {
      return new JsonResponse(['error' => 'Fichier export introuvable'], 404);
    }

    $response = new BinaryFileResponse($file_path);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, "export_{$webform_id}.csv");
    return $response;
  }

  /**
   * Récupère les IDs des soumissions pour un webform.
   */
  private function getSubmissionIds($webform_id) {
    $storage = $this->entityTypeManager->getStorage('webform_submission');
    $query = $storage->getQuery()
      ->condition('webform_id', $webform_id)
      ->accessCheck(FALSE)
      ->sort('sid', 'ASC');

    return $query->execute();
  }

  /**
   * Récupère les IDs restants des soumissions.
   */
  private function getRemainingSubmissionIds($webform_id) {
    $cache_ids = $this->cache->get("export_ids_{$webform_id}");
    return $cache_ids ? $cache_ids->data : [];
  }

  /**
   * Crée un fichier temporaire pour l'export.
   */
  private function createTempFile($webform_id) {
    $tmp_dir = sys_get_temp_dir();
    return $tmp_dir . "/export_{$webform_id}_" . uniqid() . ".csv";
  }

  /**
   * Traite un lot de soumissions et les écrit dans le fichier CSV.
   */
  private function processSubmissionChunk($submission_ids, $filepath, $is_first_batch) {
    $handle = fopen($filepath, $is_first_batch ? 'w' : 'a');
    if (!$handle) {
      throw new \Exception('Impossible d\'ouvrir le fichier CSV');
    }

    try {
      // Écrire l'en-tête si c'est le premier lot
      if ($is_first_batch && !empty($submission_ids)) {
        $this->writeCsvHeader($handle, $submission_ids[0]);
      }

      // Traiter chaque soumission
      $submissions = WebformSubmission::loadMultiple($submission_ids);
      foreach ($submissions as $submission) {
        $this->writeCsvRow($handle, $submission);
      }
    } finally {
      fclose($handle);
    }
  }

  /**
   * Écrit l'en-tête du fichier CSV.
   */
  private function writeCsvHeader($handle, $submission_id) {
    $submission = WebformSubmission::load($submission_id);
    if (!$submission) {
      return;
    }

    $data = $submission->getData();
    $headers = $this->getFilteredHeaders($data);
    fputcsv($handle, $headers);
  }

  /**
   * Écrit une ligne de données dans le fichier CSV.
   */
  private function writeCsvRow($handle, $submission) {
    $data = $submission->getData();
    $filtered_data = $this->getFilteredData($data);
    fputcsv($handle, $filtered_data);
  }

  /**
   * Filtre les en-têtes en excluant les champs non désirés.
   */
  private function getFilteredHeaders($data) {
    $excluded_fields = $this->getExcludedFields();
    return array_keys(array_diff_key($data, array_flip($excluded_fields)));
  }

  /**
   * Filtre les données en excluant les champs non désirés.
   */
  private function getFilteredData($data) {
    $excluded_fields = $this->getExcludedFields();
    $filtered_data = array_diff_key($data, array_flip($excluded_fields));
    
    return array_map(function($value, $key) {
      return $this->formatFieldValue($value, $key);
    }, $filtered_data, array_keys($filtered_data));
  }

  /**
   * Formate une valeur de champ pour l'export CSV.
   */
  private function formatFieldValue($value, $field_key) {
    // Gérer les champs de fichiers
    if ($this->isFileField($value)) {
      return $this->formatFileField($value);
    }
    
    // Gérer les tableaux
    if (is_array($value)) {
      return implode(', ', $value);
    }
    
    return $value;
  }

  /**
   * Vérifie si un champ contient des données de fichier.
   */
  private function isFileField($value) {
    if (is_array($value)) {
      // Vérifier si c'est un tableau de fichiers
      foreach ($value as $item) {
        if (is_array($item) && isset($item['fid'])) {
          return true;
        }
        if (is_numeric($item)) {
          return true; // ID de fichier
        }
      }
    }
    
    // Vérifier si c'est un ID de fichier simple
    if (is_numeric($value)) {
      return true;
    }
    
    // Vérifier si c'est un objet fichier
    if (is_array($value) && isset($value['fid'])) {
      return true;
    }
    
    return false;
  }

  /**
   * Formate un champ de fichier pour l'export.
   */
  private function formatFileField($value) {
    $files = [];
    
    // Normaliser en tableau
    if (!is_array($value)) {
      $value = [$value];
    }
    
    foreach ($value as $file_item) {
      $file_id = null;
      
      // Extraire l'ID du fichier
      if (is_numeric($file_item)) {
        $file_id = $file_item;
      } elseif (is_array($file_item) && isset($file_item['fid'])) {
        $file_id = $file_item['fid'];
      }
      
      if ($file_id) {
        $file = File::load($file_id);
        if ($file) {
          $file_url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
          $files[] = $file_url;
        }
      }
    }
    
    return implode(', ', $files);
  }

  /**
   * Retourne la liste des champs à exclure de l'export.
   */
  private function getExcludedFields() {
    return [
      'ip',
      'csrfToken',
      'completed',
      'csrf_token',
      'g-recaptcha-response',
      'in_draft',
      'webform_id',
      'uid',
      'remote_addr',
    ];
  }

}




