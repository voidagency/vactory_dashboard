<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
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
   * Returns the taxonomy add form page.
   *
   * @param string $vid
   *   The vocabulary ID.
   *
   * @return array
   *   A render array for the taxonomy add form.
   */
  public function add($vid) {
    // Check if vocabulary exists
    if (!$this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid)) {
      throw new NotFoundHttpException('Taxonomy vocabulary not found.');
    }

    // Get current language
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();

    // Get vocabulary available languages
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    foreach ($languages as $language) {
      $available_languages_list[] = [
        'id' => $language->getId(),
        'url' => \Drupal\Core\Url::fromRoute('vactory_dashboard.taxonomy.add', ['vid' => $vid], ['language' => $language]),
      ];
    }

    // Get vocabulary fields
    $fields = \Drupal::service('vactory_dashboard.node_service')->getBundleFields($vid, 0, 'taxonomy_term');

    // Get vocabulary label
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
    $vocabulary_label = $vocabulary->label();

    return [
      '#theme' => 'vactory_dashboard_taxonomy_add',
      '#type' => 'not_page',
      '#language' => $current_language,
      '#vocabulary_default_lang' => $current_language,
      '#available_languages' => $available_languages_list,
      '#vid' => $vid,
      '#vocabulary_label' => $vocabulary_label,
      '#fields' => $fields,
    ];
  }

  /**
   * Returns the taxonomy edit form page.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param int $tid
   *   The taxonomy term ID.
   *
   * @return array
   *   A render array for the taxonomy edit form.
   */
  public function edit($vid, $tid) {
    // Check if vocabulary exists
    if (!$this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid)) {
      throw new NotFoundHttpException('Taxonomy vocabulary not found.');
    }

    // Load the taxonomy term
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    if (!$term) {
      throw new NotFoundHttpException('Taxonomy term not found.');
    }

    // Verify the term belongs to the specified vocabulary
    if ($term->bundle() !== $vid) {
      throw new NotFoundHttpException('Term does not belong to the specified vocabulary.');
    }

    // Get current language
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();

    // Get term translation by current language
    $has_translation = $term->hasTranslation($current_language);
    if (!$has_translation) {
      return $this->redirect('vactory_dashboard.taxonomy.translate', [
        'vid' => $vid,
        'tid' => $term->id(),
      ]);
    }

    $term_translation = $term->getTranslation($current_language);

    // Get vocabulary available languages
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    $available_languages = $term->getTranslationLanguages();
    foreach ($languages as $language) {
      $available_languages_list[] = [
        'id' => $language->getId(),
        'url' => in_array($language->getId(), array_keys($available_languages)) ? '/' . $language->getId() . '/admin/dashboard/taxonomies/' . $vid . '/edit/' . $tid : '/' . $language->getId() . '/admin/dashboard/taxonomies/' . $vid . '/edit/' . $tid . '/add/translation',
      ];
    }

    // Get vocabulary fields
    $fields = \Drupal::service('vactory_dashboard.node_service')->getBundleFields($vid, 0, 'taxonomy_term');

    // Get vocabulary label
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
    $vocabulary_label = $vocabulary->label();

    return [
      '#theme' => 'vactory_dashboard_taxonomy_edit',
      '#type' => 'not_page',
      '#term' => $this->processTerm($term_translation ?? $term, $fields),
      '#language' => $term_translation ? $term_translation->language()->getId() : $term->language()->getId(),
      '#vocabulary_default_lang' => $term->language()->getId(),
      '#available_languages' => $available_languages_list,
      '#vid' => $vid,
      '#changed' => $term_translation ? $term_translation->get('changed')->value : $term->get('changed')->value,
      '#tid' => $tid,
      '#status' => $term_translation ? $term_translation->get('status')->value : $term->get('status')->value,
      '#vocabulary_label' => $vocabulary_label,
      '#fields' => $fields,
      '#has_translation' => $term_translation ? TRUE : FALSE,
    ];
  }

  /**
   * Returns the taxonomy translate form page.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param int $tid
   *   The taxonomy term ID.
   *
   * @return array
   *   A render array for the taxonomy translate form.
   */
  public function translate($vid, $tid) {
    // Check if vocabulary exists
    if (!$this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid)) {
      throw new NotFoundHttpException('Taxonomy vocabulary not found.');
    }

    // Load the taxonomy term
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
    if (!$term) {
      throw new NotFoundHttpException('Taxonomy term not found.');
    }

    // Verify the term belongs to the specified vocabulary
    if ($term->bundle() !== $vid) {
      throw new NotFoundHttpException('Term does not belong to the specified vocabulary.');
    }

    // Get current language
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();

    // Check if the term already has a translation in the current language
    try {
      if ($term->hasTranslation($current_language)) {
        return $this->redirect('vactory_dashboard.taxonomy.edit', [
          'vid' => $vid,
          'tid' => $term->id(),
        ]);
      }
    }
    catch (\Exception $e) {
      // If there's an error checking translation, continue to translate form
    }

    // Get vocabulary available languages
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    $available_languages = $term->getTranslationLanguages();
    foreach ($languages as $language) {
      $available_languages_list[] = [
        'id' => $language->getId(),
        'url' => in_array($language->getId(), array_keys($available_languages)) ? '/' . $language->getId() . '/admin/dashboard/taxonomies/' . $vid . '/edit/' . $tid : '/' . $language->getId() . '/admin/dashboard/taxonomies/' . $vid . '/edit/' . $tid . '/add/translation',
      ];
    }

    // Get vocabulary fields
    $fields = \Drupal::service('vactory_dashboard.node_service')->getBundleFields($vid, 0, 'taxonomy_term');

    // Get vocabulary label
    $vocabulary = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($vid);
    $vocabulary_label = $vocabulary->label();

    return [
      '#theme' => 'vactory_dashboard_taxonomy_edit',
      '#type' => 'not_page',
      '#term' => $this->processTerm($term, $fields),
      '#language' => $current_language,
      '#vocabulary_default_lang' => $term->language()->getId(),
      '#available_languages' => $available_languages_list,
      '#vid' => $vid,
      '#tid' => $tid,
      '#vocabulary_label' => $vocabulary_label,
      '#fields' => $fields,
      '#has_translation' => FALSE,
      '#status' => $term->get('status')->value,
    ];
  }

  /**
   * Saves a new taxonomy term.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function save($vid, Request $request) {
    // Check if user has permission to create terms in this vocabulary.
    if (!$this->currentUser()->hasPermission('create terms in ' . $vid)) {
      return new JsonResponse(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
    }

    $content = json_decode($request->getContent(), TRUE);
    $fields = $content['fields'] ?? [];
    $status = $content['status'] ?? 1;
    $lang = $content['lang'] ?? \Drupal::languageManager()->getCurrentLanguage()->getId();

    // Get the name field
    $name = $fields['name'] ?? '';
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
        'status' => $status,
      ]);

      // Set description if provided
      if (isset($fields['description'])) {
        $term->setDescription($fields['description']);
      }

      // Set custom fields
      foreach ($fields as $field_name => $field_value) {
        if ($term->hasField($field_name) && !in_array($field_name, ['name', 'description'])) {
          if (is_array($field_value) && isset($field_value['url'], $field_value['id'])) {
            $term->set($field_name, $field_value['id']);
            continue;
          }
          if (is_array($field_value) && isset($field_value['value'], $field_value['end_value'])) {
            if ($field_value['end_value'] < $field_value['value']) {
              throw new \Exception('End date cannot be before start date');
            }
            $term->set($field_name, $field_value);
            continue;
          }
  
          if ($field_value) {
            $term->set($field_name, $field_value);
          }
        }
      }

      $term->save();

      return new JsonResponse([
        'message' => 'Term created successfully',
        'term' => [
          'id' => $term->id(),
          'name' => $term->getName(),
        ],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['message' => 'Error creating term: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Saves an edited taxonomy term.
   *
   * @param string $vid
   *   The vocabulary ID.
   * @param int $tid
   *   The taxonomy term ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function saveEdit($vid, $tid, Request $request) {
    // Check if user has permission to edit terms in this vocabulary.
    if (!$this->currentUser()->hasPermission('edit terms in ' . $vid)) {
      return new JsonResponse(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
    }

    try {
      // Get the request content
      $content = json_decode($request->getContent(), TRUE);
      if (empty($content)) {
        throw new \Exception('Invalid request data');
      }

      // Extract data from request.
      $language = $content['language'] ?? \Drupal::languageManager()
        ->getCurrentLanguage()
        ->getId();

      $has_translation = $content['has_translation'] ?? TRUE;
      $has_translation = $has_translation !== "" ? $has_translation : FALSE;

      $status = $content['status'] ?? TRUE;
      $fields = $content['fields'] ?? [];
      $client_changed = $content['changed'] ?? NULL;

      // Load the term
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($tid);
      if (!$term) {
        return new JsonResponse(['message' => 'Term not found'], Response::HTTP_NOT_FOUND);
      }

      // Verify the term belongs to the specified vocabulary
      if ($term->bundle() !== $vid) {
        return new JsonResponse(['message' => 'Term does not belong to the specified vocabulary'], Response::HTTP_BAD_REQUEST);
      }

      // Add translation if it doesn't exist
      if (!$has_translation) {
        $term->addTranslation($language);
      }

      // Get the current translation
      $term_translation = $term->getTranslation($language);

      // Check for concurrent modifications
      $current_changed = $term_translation->get('changed')->value;
      if ($client_changed && $client_changed != $current_changed) {
        return new JsonResponse([
          'message' => $this->t('The term has been modified by another user. Please reload before saving.'),
          'code' => 409,
        ], 409);
      }

      // Get the name field
      $name = $fields['name'] ?? '';
      if (empty($name)) {
        return new JsonResponse(['message' => 'Name is required'], Response::HTTP_BAD_REQUEST);
      }

      // Set basic fields on the translation
      $term_translation->setName($name);
      $term_translation->set('status', $status);

      // Set description if provided
      if (isset($fields['description'])) {
        $term_translation->setDescription($fields['description']);
      }

      // Set custom fields
      foreach ($fields as $field_name => $field_value) {
        if ($term->hasField($field_name) && !in_array($field_name, ['name', 'description'])) {
          if (is_array($field_value) && isset($field_value['url'], $field_value['id'])) {
            $term->getTranslation($language)->set($field_name, $field_value['id']);
            continue;
          }
          if (is_array($field_value) && isset($field_value['value'], $field_value['end_value'])) {
            if ($field_value['end_value'] < $field_value['value']) {
              throw new \Exception('End date cannot be before start date');
            }
            $term->getTranslation($language)->set($field_name, $field_value);
            continue;
          }
  
          if ($field_value || is_array($field_value)) {
            $term->getTranslation($language)->set($field_name, $field_value);
          }
        }
      }

      $term->save();

      return new JsonResponse([
        'message' => 'Term updated successfully',
        'term' => [
          'id' => $term->id(),
          'name' => $term_translation->getName(),
        ],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['message' => 'Error updating term: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Adds a new taxonomy term via API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $vid
   *   The vocabulary ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function addApi(Request $request, string $vid) {
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
   * Edits a taxonomy term via API.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $vid
   *   The vocabulary ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function editApi(Request $request, string $vid) {
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

  /**
   * Processes a taxonomy term for the form.
   *
   * @param \Drupal\taxonomy\Entity\Term $term
   *   The taxonomy term.
   * @param array $fields
   *   The fields array.
   *
   * @return array
   *   The processed term data.
   */
  protected function processTerm($term, $fields) {
    $data = [
      'tid' => $term->id(),
      'name' => $term->getName(),
      'description' => $term->getDescription(),
      'status' => $term->get('status')->value,
      'langcode' => $term->language()->getId(),
      'fields' => [],
    ];
    
    foreach ($fields as $field_name => $field_definition) {
      if ($term->hasField($field_name)) {
        $field_value = $term->get($field_name)->value;
        $data['fields'][$field_name] = $field_value;
      }
    }
    // @todo: use processTerm instead of processNode.
    $data_processed = \Drupal::service('vactory_dashboard.node_service')->processNode($term, $fields);
    $data['fields'] = $data_processed;
    return $data;
  }

}
