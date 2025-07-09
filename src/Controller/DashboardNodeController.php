<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Url;
use Drupal\entityqueue\Entity\EntityQueue;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\vactory_dashboard\Constants\DashboardConstants;
use Drupal\vactory_dashboard\Service\NodeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\vactory_dashboard\Service\MetatagService;
use Drupal\token\Token;
use Drupal\vactory_dashboard\Service\PreviewUrlService;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Controller for the node dashboard.
 */
class DashboardNodeController extends ControllerBase {

  /**
   * The preview URL service.
   *
   * Used to generate or retrieve preview URLs for nodes or other entities.
   *
   * @var \Drupal\vactory_dashboard\Service\PreviewUrlService
   */
  protected $previewUrlService;

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
   * The metatag service.
   *
   * @var \Drupal\vactory_dashboard\Service\MetatagService
   */
  protected $metatagService;

  /**
   * Le service AliasManager.
   *
   * @var \Drupal\Core\Path\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The token service.
   *
   * @var \Drupal\token\Token
   */
  protected $tokenService;

  /**
   * The node service.
   *
   * @var \Drupal\vactory_dashboard\Service\NodeService
   */
  protected $nodeService;

  /**
   * Constructs a new DashboardUsersController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    MetatagService $metatag_service,
    Token $tokenService,
    PreviewUrlService $previewUrlService,
    AliasManagerInterface $alias_manager,
    NodeService $node_service
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->metatagService = $metatag_service;
    $this->tokenService = $tokenService;
    $this->previewUrlService = $previewUrlService;
    $this->aliasManager = $alias_manager;
    $this->nodeService = $node_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('vactory_dashboard.metatag_service'),
      $container->get('token'),
      $container->get('vactory_dashboard.preview_url'),
      $container->get('path_alias.manager'),
      $container->get('vactory_dashboard.node_service'),
    );
  }

  /**
   * Returns the content types dashboard page.
   *
   * @return array
   *   A render array for the content types dashboard.
   */
  public function content($bundle) {
    // Get bundle label.
    $bundle_info = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo('node')[$bundle];
    $bundle_label = $bundle_info['label'];
    // Load all queues targeting 'node'.
    $queues = EntityQueue::loadMultipleByTargetType("node");

    // Filter queues that target the specific bundle.
    $filtered_queues = array_filter($queues, function($queue) use ($bundle) {
      $settings = $queue->getEntitySettings();
      return isset($settings['handler_settings']['target_bundles']) && in_array($bundle, $settings['handler_settings']['target_bundles']);
    });

    $results = [];
    foreach ($filtered_queues as $queue) {
      $entity_subqueue = $this->entityTypeManager->getStorage('entity_subqueue')
        ->load($queue->id());
      $items = $entity_subqueue->get('items')->getValue();
      $results[$queue->id()] = [];
      if (!empty($items)) {
        foreach ($items as $item) {
          $node = Node::load($item['target_id']);
          if ($node) {
            $results[$queue->id()][] = [
              'id' => $item['target_id'],
              'title' => $node->label(),
            ];
          }
        }
      }
    }

    $dynamic_exports = [];
    if (\Drupal::moduleHandler()->moduleExists('vactory_dynamic_import')) {
      // Get all exports.
      $dynamic_exports = $this->entityTypeManager->getStorage('dynamic_import');
      $dynamic_exports = $dynamic_exports->loadByProperties([
        'target_entity' => 'node',
        'target_bundle' => $bundle,
      ]);
      $dynamic_exports = array_map(function($dynamic_export) {
        return $dynamic_export->label();
      }, $dynamic_exports);
    }

    $languages = \Drupal::languageManager()->getLanguages();
    $langs = [];
    foreach ($languages as $lang) {
      $langs[$lang->getId()] = $lang->getName();
    }

    return [
      '#theme' => 'vactory_dashboard_content_types',
      '#id' => $bundle,
      '#bundle_label' => $bundle_label,
      '#entity_queues' => $results,
      '#dynamic_exports' => $dynamic_exports,
      '#taxonomies' => $this->nodeService->getReferencedTaxonomies($bundle),
      '#langs' => $langs,
      '#has_metatag' => array_key_exists('field_vactory_meta_tags', $this->entityFieldManager->getFieldDefinitions('node', $bundle)),
    ];
  }

  /**
   * Get data for the content types dashboard.
   *
   * @param string $bundle
   *   The bundle name.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the data.
   */
  public function getData($bundle, Request $request) {
    // Get pagination parameters.
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = max(1, (int) $request->query->get('limit', 10));
    $search = $request->query->get('search', '');
    $offset = ($page - 1) * $limit;

    // Build the query.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', $bundle)
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC');

    // Add search condition if search term is provided.
    if (!empty($search)) {
      $group = $query->orConditionGroup()
        ->condition('title', '%' . $search . '%', 'LIKE')
        ->condition('body', '%' . $search . '%', 'LIKE');
      $query->condition($group);
    }

    // Get total count before adding range
    $total_query = clone $query;
    $total = $total_query->count()->execute();

    // Add pagination
    $query->range($offset, $limit);

    // Execute query
    $nids = $query->execute();
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);

    // Format the data
    $data = [];
    foreach ($nodes as $node) {
      $vid = $this->entityTypeManager
        ->getStorage('node')
        ->getLatestRevisionId($node->id());

      /** @var \Drupal\node\Entity\Node $node */
      $node = $this->entityTypeManager->getStorage('node')->loadRevision($vid);

      $metatags = [];
      $raw_metatags = $this->metatagService->prepareMetatags($node);

      $allowed_keys = [
        'title',
        'description',
        'canonical_url',
        'og_url',
        'og_image',
        'og_title',
        'og_description',
      ];
      foreach ($raw_metatags as $key => $value) {
        if (!in_array($key, $allowed_keys)) {
          continue;
        }

        $resolved = is_string($value)
          ? $this->tokenService->replace($value, ['node' => $node])
          : $value;

        $type = 'text';
        if (in_array($key, ['description', 'og_description'])) {
          $type = 'textarea';
        }
        elseif (in_array($key, [
          'og_image',
          'og_image_url',
          'og_image_secure_url',
        ])) {
          $type = 'image';
        }

        $metatags[$key] = [
          'raw' => $value,
          'resolved' => $resolved,
          'type' => $type,
        ];
      }

      $data[] = [
        'id' => $node->id(),
        'title' => $node->label(),
        'author' => $node->getOwner() ? $node->getOwner()
          ->getDisplayName() : '',
        'created' => $node->getCreatedTime(),
        'changed' => $node->getChangedTime(),
        'status' => (bool) $node->isPublished(),
        'language' => $node->language()->getId(),
        'langague_label' => $node->language()->getName(),
        'alias' => $this->previewUrlService->getPreviewUrl($node),
        'delete_url' => Url::fromRoute('vactory_dashboard.node.delete', [
          'bundle' => $bundle,
          'nid' => $node->id(),
        ], ['language' => $node->language()])->toString(),
        'edit_url' => Url::fromRoute('vactory_dashboard.node.edit', [
          'bundle' => $bundle,
          'nid' => $node->id(),
        ], ['language' => $node->language()])->toString(),
        'metatags' => $metatags,
      ];
    }

    // Calculate total pages.
    $total_pages = ceil($total / $limit);

    return new JsonResponse([
      'data' => $data,
      'total' => $total,
      'page' => $page,
      'pages' => $total_pages,
      'limit' => $limit,
    ]);
  }

  /**
   * Returns the node add form page.
   *
   * @param string $bundle
   *   The node bundle.
   *
   * @return array
   *   A render array for the node add form.
   */
  public function add($bundle) {
    // Get current language
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();

    // Get node available languages
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    foreach ($languages as $language) {
      $available_languages_list[] = [
        'id' => $language->getId(),
        'url' => Url::fromRoute('vactory_dashboard.node.add', ['bundle' => $bundle], ['language' => $language]),
      ];
    }

    // Get bundle fields.
    $fields = $this->nodeService->getBundleFields($bundle);

    // Check bundle if has a paragraphs field (field_vactory_paragraphs) with a dynamic field.
    $has_paragraphs_field = $this->nodeService->hasParagraphsField($fields);

    // Get bundle label.
    $bundle_info = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo('node')[$bundle];
    $bundle_label = $bundle_info['label'];

    return [
      //'#theme' => 'vactory_dashboard_node_add',
      '#theme' => 'vactory_dashboard_node_add',
      '#type' => 'not_page',
      '#has_paragraphs_field' => $has_paragraphs_field,
      '#language' => $current_language,
      '#node_default_lang' => $current_language,
      '#available_languages' => $available_languages_list,
      '#bundle' => $bundle,
      '#bundle_label' => $bundle_label,
      '#fields' => $fields,
    ];
  }

  /**
   * Returns the node add form page.
   *
   * @param string $bundle
   *   The node bundle.
   *
   * @return array
   *   A render array for the node add form.
   */
  public function edit($bundle, $nid) {
    $manager = \Drupal::service('content_translation.manager');

    $vid = $this->entityTypeManager
      ->getStorage('node')
      ->getLatestRevisionId($nid);

    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager->getStorage('node')->loadRevision($vid);
    if (!$node) {
      throw new NotFoundHttpException('Node not found');
    }

    // Get current language.
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();

    // Get node translation by current language
    $has_translation = $node->hasTranslation($current_language);
    if (!$has_translation) {
      return $this->redirect('vactory_dashboard.node.translate', [
        'bundle' => $bundle,
        'nid' => $node->id(),
      ]);
    }

    $node_translation = $node->getTranslation($current_language);
    $meta_tags = $this->metatagService->prepareMetatags($node_translation ?? $node);

    // Get node available languages.
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    if ($manager->isEnabled('node', $bundle)) {
      $available_languages = $node->getTranslationLanguages();
      foreach ($languages as $language) {
        $available_languages_list[] = [
          'id' => $language->getId(),
          'url' => in_array($language->getId(), array_keys($available_languages)) ? '/' . $language->getId() . '/admin/dashboard/' . $bundle . '/edit/' . $nid : '/' . $language->getId() . '/admin/dashboard/' . $bundle . '/edit/' . $nid . '/add/translation',
        ];
      }
    }

    // Get bundle fields.
    $fields = $this->nodeService->getBundleFields($bundle, count($available_languages_list));

    // Check bundle if has a paragraphs field (field_vactory_paragraphs) with a dynamic field.
    $has_paragraphs_field = $this->nodeService->hasParagraphsField($fields);

    // Get bundle label.
    $bundle_info = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo('node')[$bundle];
    $bundle_label = $bundle_info['label'];
    return [
      // '#theme' => 'vactory_dashboard_node_edit',
      '#theme' => 'vactory_dashboard_node_edit',
      '#type' => 'not_page',
      '#has_paragraphs_field' => $has_paragraphs_field,
      '#alias' => $this->previewUrlService->getPreviewUrl($node),
      '#node' => $this->nodeService->processNode($node_translation ?? $node, $fields),
      '#language' => $node_translation ? $node_translation->language()
        ->getId() : $node->language()->getId(),
      '#node_default_lang' => $node->language()->getId(),
      '#available_languages' => $available_languages_list,
      '#bundle' => $bundle,
      '#has_seo' => $node->hasField('field_vactory_meta_tags'),
      '#changed' => $node_translation ? $node_translation->get('changed')->value : $node->get('changed')->value,
      '#nid' => $nid,
      '#status' => $node_translation ? $node_translation->get('status')->value : $node->get('status')->value,
      '#bundle_label' => $bundle_label,
      '#fields' => $fields,
      '#has_translation' => $node_translation ? TRUE : FALSE,
      '#meta_tags' => $meta_tags,
    ];
  }

  /**
   * Returns the node add form page.
   *
   * @param string $bundle
   *   The node bundle.
   *
   * @return array
   *   A render array for the node add form.
   */
  public function translate($bundle, $nid) {
    $vid = $this->entityTypeManager
      ->getStorage('node')
      ->getLatestRevisionId($nid);

    /** @var \Drupal\node\Entity\Node $node */
    $node = $this->entityTypeManager->getStorage('node')->loadRevision($vid);
    if (!$node) {
      throw new NotFoundHttpException('Node not found');
    }

    // Get current language.
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();
    // Get node translation by current language
    try {
      if ($node->hasTranslation($current_language)) {
        return $this->redirect('vactory_dashboard.node.edit', [
          'bundle' => $bundle,
          'nid' => $node->id(),
        ]);
      }
    }
    catch (\Exception $e) {
    }

    // Get node available languages.
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    $available_languages = $node->getTranslationLanguages();
    foreach ($languages as $language) {
      $available_languages_list[] = [
        'id' => $language->getId(),
        'url' => in_array($language->getId(), array_keys($available_languages)) ? '/' . $language->getId() . '/admin/dashboard/' . $bundle . '/edit/' . $nid : '/' . $language->getId() . '/admin/dashboard/' . $bundle . '/edit/' . $nid . '/add/translation',
      ];
    }

    // Get bundle fields.
    $fields = $this->nodeService->getBundleFields($bundle);

    // Check bundle if has a paragraphs field (field_vactory_paragraphs) with a dynamic field.
    $has_paragraphs_field = $this->nodeService->hasParagraphsField($fields);

    // Get bundle label.
    $bundle_info = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo('node')[$bundle];
    $bundle_label = $bundle_info['label'];

    $meta_tags = $this->metatagService->prepareMetatags($node);

    return [
      '#theme' => 'vactory_dashboard_node_edit',
      '#type' => 'not_page',
      '#has_paragraphs_field' => $has_paragraphs_field,
      '#node' => $this->nodeService->processNode($node, $fields),
      '#language' => $current_language,
      '#node_default_lang' => $node->language()->getId(),
      '#available_languages' => $available_languages_list,
      '#bundle' => $bundle,
      '#nid' => $nid,
      '#status' => $node->get('status')->value,
      '#bundle_label' => $bundle_label,
      '#fields' => $fields,
      '#has_translation' => FALSE,
      '#meta_tags' => $meta_tags,
    ];
  }

  /**
   * Save node endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function save($bundle, Request $request) {
    try {
      $data = Json::decode($request->getContent());

      $status = $data['status'] ?? TRUE;

      $language = $data['language'] ?? \Drupal::languageManager()
        ->getDefaultLanguage()
        ->getId();

      // Create node
      $node = Node::create([
        'type' => $data['bundle'],
        'langcode' => $language,
        'status' => $status,
        'path' => [
          'pathauto' => 1,
        ],
      ]);

      $seo = $data['seo'] ?? [];

      $blocks = $data['blocks'] ?? [];

      if ($node->hasField('moderation_state')) {
        $node->set('moderation_state', $status ? 'published' : 'draft');
      }

      // Set field values
      foreach ($data['fields'] as $field_name => $field_value) {
        if (!$node->hasField($field_name)) {
          continue;
        }

        if ($field_name === "field_contenu_lie" && is_array($field_value)) {
          $node->set($field_name, implode(" ", $field_value));
          continue;
        }

        if (is_array($field_value) && isset($field_value['url'], $field_value['id'])) {
          $node->set($field_name, $field_value['id']);
          continue;
        }

        if ($field_value) {
          $node->set($field_name, $field_value);
        }
      }

      if ($node->hasField('field_vactory_paragraphs')) {
        $ordered_paragraphs = [];
        if (!empty($blocks)) {
          foreach ($blocks as $block) {
            $paragraph_entity = NULL;
            $paragraph = [
              "type" => "vactory_component",
              "field_vactory_title" => $block['title'],
              "field_vactory_flag" => $block['show_title'],
              "paragraph_container" => $block['width'],
              "container_spacing" => $block['spacing'],
              "paragraph_css_class" => $block['css_classes'],

              "field_vactory_component" => [
                "widget_id" => $block['widget_id'],
                "widget_data" => json_encode($block['widget_data']),
              ],
            ];
            $paragraph['langcode'] = $language;
            $paragraph_entity = Paragraph::create($paragraph);
            $paragraph_entity->save();
            $ordered_paragraphs[] = [
              'target_id' => $paragraph_entity->id(),
              'target_revision_id' => \Drupal::entityTypeManager()
                ->getStorage('paragraph')
                ->getLatestRevisionId($paragraph_entity->id()),
            ];
          }
        }
        if (!empty($ordered_paragraphs)) {
          $node->set('field_vactory_paragraphs', $ordered_paragraphs);
        }
      }

      // Save SEO fields if they exist.
      if (!empty($seo) && $node->hasField('field_vactory_meta_tags')) {
        // Mettre à jour les meta tags avec les valeurs fournies dans $seo.
        foreach (DashboardConstants::METATAGS_KEYS as $key) {
          if (isset($seo[$key])) {
            $meta_tags[$key] = $seo[$key];
          }
        }
        // Remplace 'field_meta_tags' par le nom exact du champ metatag sur ton nœud.
        $node->set('field_vactory_meta_tags', serialize($meta_tags));
      }

      $node->save();

      return new JsonResponse([
        'message' => $this->t('Node created successfully'),
        'nid' => $node->id(),
        'list' => Url::fromRoute('vactory_dashboard.content_types', ['bundle' => $bundle])
          ->toString(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'message' => $this->t('Error while creating node'),
      ], 400);
    }
  }

  /**
   * Save edit.
   */
  public function saveEdit($bundle, $nid, Request $request) {
    try {
      // Get the request content
      $content = json_decode($request->getContent(), TRUE);
      if (empty($content)) {
        throw new \Exception('Invalid request data');
      }

      $seo = $content['seo'] ?? [];

      // Extract data from request.
      $node_default_lang = NULL;
      $language = $content['language'] ?? \Drupal::languageManager()
        ->getDefaultLanguage()
        ->getId();

      $has_translation = $content['has_translation'] ?? TRUE;

      $has_translation = $has_translation !== "" ? $has_translation : FALSE;

      $status = $content['status'] ?? TRUE;

      $blocks = $content['blocks'] ?? [];

      $client_changed = $content['changed'] ?? NULL;

      // Load node by nid.
      if ($nid) {
        $vid = $this->entityTypeManager
          ->getStorage('node')
          ->getLatestRevisionId($nid);

        /** @var \Drupal\node\Entity\Node $node */
        $node = $this->entityTypeManager->getStorage('node')
          ->loadRevision($vid);
        if (!$node) {
          throw new \Exception('Node not found');
        }
        $node_default_lang = $node->language()->getId();
      }
      if (!$has_translation) {
        $node->addTranslation($language);
      }

      $current_changed = $node->getTranslation($language)->getChangedTime();

      if ($client_changed && $client_changed != $current_changed) {
        return new JsonResponse([
          'message' => $this->t('The node has been modified by another user. Please reload before saving.'),
          'code' => 409,
        ], 409);
      }

      if ($node->hasField('moderation_state')) {
        $node->getTranslation($language)
          ->set('moderation_state', $status ? 'published' : 'draft');
      }

      $node->getTranslation($language)->set('status', $status);

      foreach ($content['fields'] as $field_name => $field_value) {
        if (!$node->hasField($field_name)) {
          continue;
        }

        if ($field_name === "field_contenu_lie" && is_array($field_value)) {
          $node->getTranslation($language)
            ->set($field_name, implode(" ", $field_value));
          continue;
        }

        if (is_array($field_value) && isset($field_value['url'], $field_value['id'])) {
          $node->getTranslation($language)
            ->set($field_name, $field_value['id']);
          continue;
        }

        if ($field_value) {
          $node->getTranslation($language)->set($field_name, $field_value);
        }
      }

      // Update SEO fields if they exist
      if (!empty($seo) && $node->hasField('field_vactory_meta_tags')) {
        // Mettre à jour les meta tags avec les valeurs fournies dans $seo.
        foreach (DashboardConstants::METATAGS_KEYS as $key) {
          if (isset($seo[$key])) {
            $meta_tags[$key] = $seo[$key];
          }
        }
        $node->getTranslation($language)
          ->set('field_vactory_meta_tags', serialize($meta_tags));
      }

      // Update blocks/paragraphs if they exist.
      if ($node->hasField('field_vactory_paragraphs')) {
        if (!empty($blocks)) {
          $ordered_paragraphs = [];
          foreach ($blocks as $block) {
            $paragraph_entity = NULL;
            $paragraph = [
              "type" => "vactory_component",
              "field_vactory_title" => $block['title'],
              "field_vactory_flag" => $block['show_title'],
              "paragraph_container" => $block['width'],
              "container_spacing" => $block['spacing'],
              "paragraph_css_class" => $block['css_classes'],
              "field_vactory_component" => [
                "widget_id" => $block['widget_id'],
                "widget_data" => json_encode($block['widget_data']),
              ],
            ];
            $is_new = $block['is_new'] ?? FALSE;
            // Paragraph translate.
            if ($language != $node_default_lang) {
              // Handle translations.
              $paragraph_entity = Paragraph::load($block['id']);

              // Check if translation exists, if not create it first.
              if (!$paragraph_entity->hasTranslation($language)) {
                $paragraph_entity->addTranslation($language, $paragraph_entity->toArray());
              }

              // Now we can safely access and modify the translation.
              $paragraph_entity->getTranslation($language)
                ->set('field_vactory_component', [
                  "widget_id" => $block['widget_id'],
                  "widget_data" => json_encode($block['widget_data']),
                ]);

              if (isset($block['title'])) {
                $paragraph_entity->getTranslation($language)
                  ->set('field_vactory_title', $block['title']);
              }

              $paragraph_entity->getTranslation($language)
                ->set('field_vactory_flag', $block['show_title']);

              if (isset($block['width'])) {
                $paragraph_entity->getTranslation($language)
                  ->set('paragraph_container', $block['width']);
              }

              if (isset($block['spacing'])) {
                $paragraph_entity->getTranslation($language)
                  ->set('container_spacing', $block['spacing']);
              }

              if (isset($block['css_classes'])) {
                $paragraph_entity->getTranslation($language)
                  ->set('paragraph_css_class', $block['css_classes']);
              }

              $paragraph_entity->save();
            }
            else {
              if ($is_new) {
                $paragraph['langcode'] = $language;
                $paragraph_entity = Paragraph::create($paragraph);
                $paragraph_entity->save();
              }
              else {
                $paragraph_entity = Paragraph::load($block['id']);
                $paragraph_entity->getTranslation($language)
                  ->set('field_vactory_component', [
                    "widget_id" => $block['widget_id'],
                    "widget_data" => json_encode($block['widget_data']),
                  ]);

                if (isset($block['title'])) {
                  $paragraph_entity->getTranslation($language)
                    ->set('field_vactory_title', $block['title']);
                }

                $paragraph_entity->getTranslation($language)
                  ->set('field_vactory_flag', $block['show_title']);

                if (isset($block['width'])) {
                  $paragraph_entity->getTranslation($language)
                    ->set('paragraph_container', $block['width']);
                }

                if (isset($block['spacing'])) {
                  $paragraph_entity->getTranslation($language)
                    ->set('container_spacing', $block['spacing']);
                }

                if (isset($block['css_classes'])) {
                  $paragraph_entity->getTranslation($language)
                    ->set('paragraph_css_class', $block['css_classes']);
                }

                $paragraph_entity->save();
              }
              if ($paragraph_entity instanceof ParagraphInterface) {
                $ordered_paragraphs[] = [
                  'target_id' => $paragraph_entity->id(),
                  'target_revision_id' => \Drupal::entityTypeManager()
                    ->getStorage('paragraph')
                    ->getLatestRevisionId($paragraph_entity->id()),
                ];
              }
            }
          }
          if (!empty($ordered_paragraphs)) {
            $node->set('field_vactory_paragraphs', $ordered_paragraphs);
          }
        }
        else {
          $node->set('field_vactory_paragraphs', []);
        }
      }

      // Save the node
      $node->save();

      return new JsonResponse([
        'message' => $this->t('Node updated successfully'),
        'node_id' => $node->id(),
      ]);
    }
    catch (\Exception $e) {
      \Drupal::logger('vactory_dashboard')->error($e->getMessage());
      return new JsonResponse([
        'message' => $this->t('Error while updating node'),
      ], 400);
    }
  }

  /**
   * Search content for entity queue autocomplete.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing search results.
   */
  public function contentSearch(Request $request) {
    $query = $request->query->get('q', '');
    $bundle = $request->query->get('bundle', '');
    $results = [];

    if (strlen($query) >= 2 && !empty($bundle)) {
      // Build the query
      $node_query = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $bundle)
        ->condition('title', '%' . \Drupal::database()
            ->escapeLike($query) . '%', 'LIKE')
        ->accessCheck(TRUE)
        ->sort('created', 'DESC')
        ->range(0, 10);

      $nids = $node_query->execute();
      $nodes = $this->entityTypeManager->getStorage('node')
        ->loadMultiple($nids);

      foreach ($nodes as $node) {
        $results[] = [
          'id' => $node->id(),
          'title' => $node->label(),
          'type' => $node->bundle(),
        ];
      }
    }

    return new JsonResponse($results);
  }

  /**
   * Save entity queue items order.
   *
   * @param string $queue_name
   *   The queue machine name.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success or failure.
   */
  public function saveQueueOrder($queue_name, Request $request) {
    try {
      $content = Json::decode($request->getContent());

      if (!isset($content['items']) || !is_array($content['items'])) {
        throw new \InvalidArgumentException('Invalid request format. Expected items array.');
      }

      // Load the entity subqueue
      $entity_subqueue = $this->entityTypeManager->getStorage('entity_subqueue')
        ->load($queue_name);

      if (!$entity_subqueue) {
        throw new NotFoundHttpException('Queue not found');
      }

      // Update items
      $items = [];
      foreach ($content['items'] as $nid) {
        $items[] = ['target_id' => $nid];
      }

      $entity_subqueue->set('items', $items);
      $entity_subqueue->save();

      return new JsonResponse(['message' => $this->t('Queue order updated successfully')]);
    }
    catch (\Exception $e) {
      return new JsonResponse(
        ['error' => $e->getMessage()],
        500
      );
    }
  }

  /**
   * Delete node.
   *
   * @param string $bundle
   *   The node bundle.
   * @param int $nid
   *   The node ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response.
   */
  public function delete($bundle, $nid) {
    try {
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
      if (!$node) {
        throw new NotFoundHttpException('Node not found');
      }
      // Check permissions
      if (!$this->currentUser()
          ->hasPermission("delete any $bundle content") && !$this->currentUser()
          ->hasPermission("delete own $bundle content")) {
        throw new \Exception('Access denied');
      }
      $node->delete();
      $url = Url::fromRoute('vactory_dashboard.content_types', ['bundle' => $bundle])
        ->toString();
      return new RedirectResponse($url);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'message' => $this->t('Error while deleting node'),
      ], 400);
    }
  }

  /**
   * Delete multiple nodes.
   *
   * @param string $bundle
   *   The node bundle.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing node IDs.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response indicating success or failure.
   */
  public function deleteNodes($bundle, Request $request) {
    try {
      $content = json_decode($request->getContent(), TRUE);
      $nids = $content['nodeIds'] ?? [];
      if (empty($nids)) {
        throw new \InvalidArgumentException('No node IDs provided for deletion.');
      }
      // Valider nids numériques
      foreach ($nids as $nid) {
        if (!is_numeric($nid)) {
          throw new \InvalidArgumentException('Invalid node ID: ' . $nid);
        }
      }
      $nodes = $this->entityTypeManager->getStorage('node')
        ->loadMultiple($nids);
      if (empty($nodes)) {
        throw new NotFoundHttpException('No nodes found for the provided IDs.');
      }
      // Vérification permissions et bundle
      foreach ($nodes as $node) {
        if ($node->bundle() !== $bundle) {
          throw new \Exception('Node ID ' . $node->id() . ' does not belong to the specified bundle.');
        }
        if (
          !$this->currentUser()->hasPermission("delete any $bundle content") &&
          !($this->currentUser()
              ->hasPermission("delete own $bundle content") && $node->getOwnerId() == $this->currentUser()
              ->id())
        ) {
          throw new \Exception('Access denied for node ID: ' . $node->id());
        }
      }
      foreach ($nodes as $node) {
        $node->delete();
      }
      return new JsonResponse([
        'message' => $this->t('Nodes deleted successfully'),
        'deleted_ids' => $nids,
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'message' => $this->t('Error while deleting nodes: @error', ['@error' => $e->getMessage()]),
      ], 400);
    }
  }

  /**
   * Export csv model.
   */
  public function exportCsvModel($export_key) {
    try {
      $export = $this->entityTypeManager->getStorage('dynamic_import')
        ->load($export_key);
      if (!$export) {
        throw new NotFoundHttpException('Export not found');
      }
      $config = \Drupal::config('vactory_dynamic_import.dynamic_import.' . $export_key);

      $targetEntity = $config->get('target_entity');
      $targetBundle = $config->get('target_bundle');
      $concernedFields = $config->get('concerned_fields');
      $isTranslation = $config->get('is_translation');
      \Drupal::service('vactory_dynamic_import.helper')->generateCsvModel(
        $targetEntity,
        $targetBundle,
        $concernedFields,
        $isTranslation
      );
      return new JsonResponse(['message' => $this->t('Export started successfully')]);
    }
    catch (\Exception $e) {
      throw new \Exception('Error while exporting');
    }
  }

  /**
   * Handles bulk update of metatag fields for multiple nodes.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request containing JSON payload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the update status and count.
   */
  public function editMetatg(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);
      $changes = $data['changes'] ?? [];

      $updated = 0;

      foreach ($changes as $item) {
        $nid = $item['id'] ?? NULL;
        $incomingMetatags = $item['metatags'] ?? [];

        if (empty($nid) || empty($incomingMetatags)) {
          continue;
        }

        // Extract raw values only
        $rawMetatags = [];
        foreach ($incomingMetatags as $key => $info) {
          if (isset($info['raw'])) {
            $rawMetatags[$key] = $info['raw'];
          }
        }

        /** @var \Drupal\node\Entity\Node $node */
        $node = $this->entityTypeManager->getStorage('node')->load($nid);
        if ($node && $node->hasField('field_vactory_meta_tags')) {
          $node->set('field_vactory_meta_tags', [
            'value' => serialize($rawMetatags),
            'format' => 'serialized',
          ]);
          $node->save();
          $updated++;
        }
      }

      return new JsonResponse([
        'status' => 'success',
        'updated' => $updated,
      ], 200);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'status' => 'error',
        'message' => 'An unexpected error occurred during update.',
      ], 500);
    }
  }

  /**
   * Returns the list of aliases of published nodes accessible to the user,
   * filtered by an optional search query
   *
   * @param Request $request
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getNodeLinks(Request $request) {
    $query = $request->query->get('q', '');

    $entityQuery = \Drupal::entityQuery('node')
      ->accessCheck(TRUE)
      ->condition('status', 1);

    if ($query !== '') {
      $entityQuery->condition('title', '%' . $query . '%', 'LIKE');
    }

    $entityQuery->range(0, 10);

    $nids = $entityQuery->execute();

    $nodes = Node::loadMultiple($nids);
    $links = [];
    $entity_repository = \Drupal::service('entity.repository');
    foreach ($nodes as $node) {
      $node = $entity_repository->getTranslationFromContext($node);
      $url = $node->toUrl()->getInternalPath();
      $links[] = [
        'title' => $node->label(),
        'url' => '/' . $url,
        'type' => $node->bundle(),
        'created' => $node->getCreatedTime(),
        'id' => $node->id(),
        'author' => $node->getOwner()->getDisplayName(),
      ];
    }

    return new JsonResponse($links);
  }

  /**
   * Get referenced taxonomies.
   */
  public function getReferencedTaxonomies($bundle) {
    return $this->nodeService->getReferencedTaxonomies($bundle);
  }

}
