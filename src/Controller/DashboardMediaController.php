<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

define('UPLOAD_BASE_PATH_PRIVATE', 'private://uploads');
define('UPLOAD_BASE_PATH_PUBLIC', 'public://');

/**
 * Controller for the media dashboard.
 */
class DashboardMediaController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new DashboardMediaController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, FileSystemInterface $file_system, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->fileSystem = $file_system;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('file_system'),
      $container->get('current_user')
    );
  }

  /**
   * Returns the media dashboard page.
   *
   * @return array
   *   A render array for the media dashboard.
   */
  public function content() {
    // Get all media types.
    $media_types = $this->entityTypeManager->getStorage('media_type')
      ->loadMultiple();

    $types = [];
    foreach ($media_types as $type_id => $type) {
      $types[$type_id] = $type->label();
    }

    return [
      '#theme' => 'vactory_dashboard_media',
      '#media_types' => $types,
    ];
  }

  /**
   * Returns paginated media data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with media data.
   */
  public function getMediaData(Request $request) {
    $page = $request->query->get('page', 1);
    $limit = $request->query->get('limit', 12);
    $search = $request->query->get('search', '');
    $type = $request->query->get('type', '');

    // Create query for counting.
    $count_query = $this->entityTypeManager->getStorage('media')->getQuery();
    $count_query->accessCheck(FALSE);

    // Create main query.
    $query = $this->entityTypeManager->getStorage('media')->getQuery();
    $query->accessCheck(FALSE);
    $query->sort('created', 'DESC');

    // Apply filters.
    if (!empty($search)) {
      $query->condition('name', $search, 'CONTAINS');
      $count_query->condition('name', $search, 'CONTAINS');
    }

    if (!empty($type)) {
      $query->condition('bundle', $type);
      $count_query->condition('bundle', $type);
    }

    // Get total count.
    $total = $count_query->count()->execute();

    // Add pagination.
    $query->range(($page - 1) * $limit, $limit);

    // Get media IDs.
    $mids = $query->execute();

    // Load media entities.
    $medias = $this->entityTypeManager->getStorage('media')
      ->loadMultiple($mids);

    $data = [];
    foreach ($medias as $media) {
      /** @var \Drupal\media\Entity\Media $media */
      $item = [
        'id' => $media->id(),
        'name' => $media->getName(),
        'type' => $media->bundle(),
        'created' => $media->getCreatedTime(),
        'changed' => $media->getChangedTime(),
        'url' => $this->getMediaUrl($media),
      ];
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
   * Gets the media thumbnail URL.
   *
   * @param \Drupal\media\Entity\Media $media
   *   The media entity.
   *
   * @return string
   *   The thumbnail URL.
   */
  protected function getMediaUrl($media) {
    $bundle = $media->bundle();
    if ($bundle == 'image') {
      if ($media->hasField('thumbnail') && !$media->get('thumbnail')
          ->isEmpty()) {
        $file = $media->get('thumbnail')->entity;
        if ($file) {
          return $file->createFileUrl();
        }
      }
    }

    if ($bundle == 'remote_video') {
      return $media->get('field_media_oembed_video')->value;
    }

    if ($bundle == 'file') {
      $file = $media->get('field_media_file')->entity;
      if ($file instanceof FileInterface) {
        return $file->createFileUrl();
      }
    }

    if ($bundle == 'private_file') {
      $file = $media->get('field_media_file_1')->entity;
      if ($file instanceof FileInterface) {
        return $file->createFileUrl();
      }
    }

    return '';
  }

  /**
   * Returns medias types page  .
   *
   * @return array
   *   An array of media types.
   */
  public function add() {
    $media_types = $this->entityTypeManager->getStorage('media_type')
      ->loadMultiple();

    $types = [];
    foreach ($media_types as $type_id => $type) {
      $types[$type_id] = $type->label();
    }

    return [
      '#theme' => 'vactory_dashboard_ajoute_media',
      '#media_types' => $types,
    ];
  }

  /**
   * Returns page for file upload.
   *
   * @return array
   *   An array of allowed extensions .
   */
  public function addFiles() {
    //Récupération des extensions autorisées depuis le champ "field_media_file"
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', 'file');
    $field = $field_definitions['field_media_file'];
    $settings = $field->getSettings();
    $allowed_extensions = explode(' ', $settings['file_extensions']);

    // Récupérer la taille maximale depuis les paramètres.
    $max_size_bytes = $settings['max_filesize'];
    if (empty($max_size_bytes)) {
      $max_size_bytes = Bytes::toNumber(Environment::getUploadMaxSize());
    }

    return [
      '#theme' => 'vactory_dashboard_ajoute_medias_files',
      '#allowed_extensions' => $allowed_extensions,
      '#max_size_bytes' => $max_size_bytes,
    ];
  }

  /**
   * Returns page for image upload.
   *
   * @return array
   *   An array of allowed extensions .
   */
  public function pageAddImage() {
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', 'image');
    $field = $field_definitions['field_media_image'];
    $settings = $field->getSettings();

    $allowed_extensions = explode(' ', $settings['file_extensions']);
    $max_size_bytes = $settings['max_filesize'];

    return [
      '#theme' => 'vactory_dashboard_ajoute_medias_images',
      '#allowed_extensions' => $allowed_extensions,
      '#max_size_bytes' => $max_size_bytes,
    ];
  }

  /**
   * Handles the file upload.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */

  public function addFileUpload($type_id, Request $request) {
    try {
      $errors = [];

      //  Récupérer le fichier et le nom
      $uploaded_file = $request->files->get('file');
      $fileName = trim($request->get('fileName'));

      //  Valider le nom du fichier
      if (empty($fileName)) {
        $errors['fileName'] = 'Le nom du fichier est requis.';
      }
      elseif (strlen($fileName) < 4 || strlen($fileName) > 255) {
        $errors['fileName'] = 'Le nom du fichier doit contenir entre 4 et 255 caractères.';
      }

      //  Vérifier la présence du fichier
      if (!$uploaded_file) {
        $errors['file'] = 'Aucun fichier n’a été téléchargé.';
      }

      //  Retour si erreurs
      if (!empty($errors)) {
        return new JsonResponse(['errors' => $errors], 400);
      }

      //  Obtenir les paramètres du champ.
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', $type_id);
      $field_name = $type_id === 'private_file' ? 'field_media_file_1' : 'field_media_file';
      $field = $field_definitions[$field_name];
      $settings = $field->getSettings();

      // Récupérer la taille maximale depuis les paramètres.
      $max_size_bytes = $max_size_bytes = $settings['max_filesize'];
      if (empty($max_size_bytes)) {
        $max_size_bytes = Bytes::toNumber(Environment::getUploadMaxSize());
      }

      //  Vérification de l’extension.
      $allowed_extensions = explode(' ', $settings['file_extensions']);
      $extension = strtolower($uploaded_file->getClientOriginalExtension());

      if (!in_array($extension, $allowed_extensions)) {
        $message = "Extension non autorisée : .$extension";
        return new JsonResponse(['error' => $message], 400);
      }

      //  Taille maximale : 8 Mo
      if ($uploaded_file->getSize() > $max_size_bytes) {
        $message = "Le fichier dépasse la taille maximale autorisée : {$max_size_bytes} .";
        return new JsonResponse(['error' => $message], 400);
      }

      // Ajouter extension au nom si nécessaire.
      if (!str_ends_with($fileName, '.' . $extension)) {
        $fileName .= '.' . $extension;
      }

      // Choisir le chemin selon la visibilité (public ou privé).
      $isPublic = $request->get('isPublic') === '1';
      $destinationPath = $isPublic ? UPLOAD_BASE_PATH_PUBLIC . '/uploads/' : UPLOAD_BASE_PATH_PRIVATE;

      // Générer le chemin avec la date du jour
      $date_folder = date('Y-m-d');
      $destinationPath .= '/' . $date_folder . '/';

      // Lire et enregistrer le fichier
      $file_system = $this->fileSystem;
      $data = file_get_contents($uploaded_file->getPathname());
      $destination = $destinationPath . $fileName;

      // Créer le répertoire s’il n'existe pas
      $this->fileSystem->prepareDirectory($destinationPath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      $final_uri = $file_system->saveData($data, $destination, FileSystemInterface::EXISTS_RENAME);

      // Créer une entité File
      $file = File::create([
        'uri' => $final_uri,
        'status' => 1,
      ]);
      $file->save();

      // Créer une entité Media
      $media = Media::create([
        'bundle' => $type_id,
        'name' => $fileName,
        $field_name => [
          'target_id' => $file->id(),
        ],
        'status' => 1,
      ]);
      $media->save();

      return new JsonResponse(['message' => 'Fichier ajouté avec succès !'], 200);
    }
    catch (\Exception $e) {
      \Drupal::logger('custom_upload')->error($e->getMessage());
      return new JsonResponse(['error' => 'Une erreur est survenue lors du téléversement.'], 500);
    }
  }

  /**
   * Handles the image upload.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function addImage(Request $request) {
    try {
      $errors = [];

      // Récupérer l'image et le nom
      $uploaded_image = $request->files->get('image');
      $name = trim($request->get('name'));

      // Validation du nom
      if (empty($name)) {
        $errors['name'] = 'Le nom de l\'image est requis.';
      }
      elseif (strlen($name) < 4 || strlen($name) > 255) {
        $errors['name'] = 'Le nom de l\'image doit contenir entre 4 et 255 caractères.';
      }

      // Validation de l'image
      if (!$uploaded_image) {
        $errors['image'] = 'Aucune image reçue.';
      }
      else {
        // Obtenir les extensions autorisées dynamiquement
        $field_definitions = $this->entityFieldManager->getFieldDefinitions('media', 'image');
        $field = $field_definitions['field_media_image'];
        $settings = $field->getSettings();
        $allowed_extensions = explode(' ', $settings['file_extensions']);
        $extension = strtolower($uploaded_image->getClientOriginalExtension());
        $max_size_bytes = $settings['max_filesize'];

        if (empty($max_size_bytes)) {
          $max_size_bytes = Bytes::toNumber(Environment::getUploadMaxSize());
        }

        // Vérification de l’extension
        if (!in_array($extension, $allowed_extensions)) {
          $errors['image'] = "Extension non autorisée : .$extension";
        }

        // Vérification de la taille
        if ($uploaded_image->getSize() > $max_size_bytes) {
          $message = "Le fichier dépasse la taille maximale autorisée : {$max_size_bytes} .";
          return new JsonResponse(['error' => $message], 400);
        }
      }

      // Retourner les erreurs s’il y en a
      if (!empty($errors)) {
        return new JsonResponse(['errors' => $errors], 400);
      }

      //  Lire les données
      $data = file_get_contents($uploaded_image->getPathname());

      // Ajouter l'extension si manquante
      if (!str_ends_with($name, '.' . $extension)) {
        $name .= '.' . $extension;
      }

      $originalFileName = $_FILES['image']['name'];

      $dateFolder = (new \DateTime())->format('Y-m-d');
      $destinationPath = UPLOAD_BASE_PATH_PUBLIC . '/images/' . $dateFolder . '/';

      //  Préparer le dossier (le créer s’il n’existe pas)
      $file_system = $this->fileSystem;
      $file_system->prepareDirectory($destinationPath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

      //  Chemin final du fichier
      $destination = $destinationPath . $originalFileName;

      //  Sauvegarde
      $final_uri = $file_system->saveData($data, $destination, FileSystemInterface::EXISTS_RENAME);

      //  Créer l'entité File
      $file = File::create([
        'uri' => $final_uri,
        'status' => 1,
      ]);
      $file->save();

      //  Créer l'entité Media
      $media = Media::create([
        'bundle' => 'image',
        'name' => $name,
        'field_media_image' => [
          'target_id' => $file->id(),
        ],
        'status' => 1,
      ]);
      $media->save();

      //  Retourner une réponse de succès
      return new JsonResponse([
        'message' => 'Image ajoutée avec succès !',
        'image_url' => $file->createFileUrl(),
      ], 200);
    }
    catch (\Exception $e) {
      \Drupal::logger('custom_upload')->error($e->getMessage());
      return new JsonResponse([
        'error' => 'Une erreur est survenue lors de l’ajout de l’image.',
        'details' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Returns page for remote video upload.
   *
   */

  public function pageAddRemoteVideo() {
    return [
      '#theme' => 'vactory_dashboard_ajoute_medias_remote_video',
    ];
  }

  /**
   * Handles the remote video upload.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */

  public function addRemoteVideo(Request $request) {
    try {
      $errors = [];

      // Récupérer l'URL
      $data = json_decode($request->getContent(), TRUE);
      $url = isset($data['url']) ? trim($data['url']) : '';

      // Validation de l'URL
      if (empty($url)) {
        $errors['url'] = 'Aucune URL reçue.';
      }
      elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $errors['url'] = 'URL invalide.';
      }

      // Retourner les erreurs s’il y en a
      if (!empty($errors)) {
        return new JsonResponse(['errors' => $errors], 400);
      }

      // Créer l'entité Media
      $media = Media::create([
        'bundle' => 'remote_video',
        'field_media_oembed_video' => [
          'value' => $url,
          'format' => 'oembed_full',
        ],
        'status' => 1,
      ]);
      $media->save();

      // Retourner une réponse de succès
      return new JsonResponse([
        'message' => 'Vidéo ajoutée avec succès !',
        'video_url' => $url,
      ], 200);
    }
    catch (\Exception $e) {
      \Drupal::logger('custom_upload')->error($e->getMessage());
      return new JsonResponse([
        'error' => 'Une erreur est survenue lors de l’ajout de la vidéo.',
        'details' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Returns page for upload documents.
   *
   * @return array
   *   An array of allowed extensions .
   */

  public function pageAddUploadDocuments() {
    $allowed_extensions = [];

    try {
      // Récupérer les extensions autorisées pour 'image'
      $field_definitions_image = $this->entityFieldManager->getFieldDefinitions('media', 'image');
      if (isset($field_definitions_image['field_media_image'])) {
        $settings_image = $field_definitions_image['field_media_image']->getSettings();
        $allowed_extensions = array_merge($allowed_extensions, explode(' ', $settings_image['file_extensions']));
      }

      // Récupérer les extensions autorisées pour 'file'
      $field_definitions_file = $this->entityFieldManager->getFieldDefinitions('media', 'file');
      if (isset($field_definitions_file['field_media_file'])) {
        $settings_file = $field_definitions_file['field_media_file']->getSettings();
        $allowed_extensions = array_merge($allowed_extensions, explode(' ', $settings_file['file_extensions']));
      }

      // Supprimer les doublons
      $allowed_extensions = array_unique($allowed_extensions);
    }
    catch (\Exception $e) {
      \Drupal::logger('vactory_dashboard')->error($e->getMessage());
    }

    return [
      '#theme' => 'vactory_dashboard_ajoute_medias_upload_documents',
      '#allowed_extensions' => $allowed_extensions,
    ];
  }

  /**
   * Handles the upload of documents.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function addUploadDocuments(Request $request) {
    try {
      $files = $request->files->get('documents');
      $errors = [];

      if (!$files || count($files) === 0) {
        return new JsonResponse(['errors' => ['files' => 'Aucun document reçu.']], 400);
      }

      // 🔹 Extensions autorisées
      $allowed_extensions = [];

      $image_definitions = $this->entityFieldManager->getFieldDefinitions('media', 'image');
      if (isset($image_definitions['field_media_image'])) {
        $image_settings = $image_definitions['field_media_image']->getSettings();
        $allowed_extensions_image = array_merge($allowed_extensions, explode(' ', $image_settings['file_extensions']));
      }

      $file_definitions = $this->entityFieldManager->getFieldDefinitions('media', 'file');
      if (isset($file_definitions['field_media_file'])) {
        $file_settings = $file_definitions['field_media_file']->getSettings();
        $allowed_extensions = array_merge($allowed_extensions, explode(' ', $file_settings['file_extensions']));
      }

      $allowed_extensions = array_merge($allowed_extensions, $allowed_extensions_image);
      $allowed_extensions = array_unique(array_map('strtolower', $allowed_extensions));
      $max_total_size = 20 * 1024 * 1024; // 20 Mo
      $total_size = 0;

      //  Validation
      foreach ($files as $file) {
        if (!$file instanceof UploadedFile) {
          $errors[] = "Fichier invalide.";
          continue;
        }

        $ext = strtolower($file->getClientOriginalExtension());
        $size = $file->getSize();
        $total_size += $size;

        if (!in_array($ext, $allowed_extensions)) {
          $errors[] = "Extension non autorisée : .$ext";
        }
      }

      if ($total_size > $max_total_size) {
        $errors[] = "La taille totale des fichiers dépasse 20 Mo.";
      }

      if (!empty($errors)) {
        return new JsonResponse(['errors' => ['files' => implode(', ', $errors)]], 400);
      }
      $file_system = $this->fileSystem;
      $dateFolder = (new \DateTime())->format('Y-m-d');

      //  Enregistrement des fichiers
      foreach ($files as $file) {
        $ext = strtolower($file->getClientOriginalExtension());
        $is_image = in_array($ext, $allowed_extensions_image);

        $filename = $file->getClientOriginalName();
        $file_contents = file_get_contents($file->getPathname());

        //  Dossier de destination
        $subDir = $is_image ? 'images' : 'uploads';
        $destinationPath = UPLOAD_BASE_PATH_PUBLIC . "$subDir/$dateFolder/";

        //  Créer dossier si nécessaire
        $file_system->prepareDirectory($destinationPath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

        //  Sauvegarder fichier
        $destination = $destinationPath . $filename;
        $uri = $file_system->saveData($file_contents, $destination, FileSystemInterface::EXISTS_RENAME);
        $uid = $this->currentUser->id();
        //  Créer entité fichier
        $saved_file = File::create([
          'uri' => $uri,
          'uid' => $uid,
          'status' => 1,
        ]);
        $saved_file->save();

        // Créer entité média
        $media = Media::create([
          'bundle' => $is_image ? 'image' : 'file',
          'uid' => $uid,
          'status' => 1,
          $is_image ? 'field_media_image' : 'field_media_file' => [
            'target_id' => $saved_file->id(),
          ],
        ]);
        $media->save();
      }

      return new JsonResponse(['status' => 'success']);
    }
    catch (\Exception $e) {
      \Drupal::logger('custom_upload')->error($e->getMessage());
      return new JsonResponse([
        'errors' => ['server' => 'Une erreur interne est survenue. Veuillez réessayer.'],
      ], 500);
    }
  }

  /**
   * Deletes multiple media .
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function deleteMultipleMedia(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      $ids = $data['ids'] ?? [];

      if (empty($ids)) {
        return new JsonResponse(['error' => 'Aucun ID fourni.'], 400);
      }

      $mediaStorage = $this->entityTypeManager->getStorage('media');
      foreach ($ids as $id) {
        $media = $mediaStorage->load($id);
        if ($media) {
          $media->delete();
        }
      }

      return new JsonResponse(['message' => 'Médias supprimés avec succès.'], 200);
    }
    catch (\Exception $e) {
      \Drupal::logger('vactory_dashboard')->error($e->getMessage());
      return new JsonResponse(['error' => 'Une erreur est survenue lors de la suppression.'], 500);
    }
  }

}
