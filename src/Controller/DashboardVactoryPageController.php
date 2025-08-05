<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\pathauto\PathautoState;
use Drupal\vactory_dashboard\Constants\DashboardConstants;
use Drupal\vactory_dashboard\Service\NodeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\vactory_dashboard\Service\MetatagService;
use Drupal\vactory_dashboard\Service\AliasValidationService;
use Drupal\vactory_dashboard\Service\PreviewUrlService;
use Drupal\field\Entity\FieldConfig;

/**
 * Controller for the vactory_page dashboard.
 */
class DashboardVactoryPageController extends ControllerBase {

  /**
   * The preview URL service.
   *
   * Used to generate or retrieve preview URLs for nodes or other entities.
   *
   * @var \Drupal\vactory_dashboard\Service\PreviewUrlService
   *
   */
  protected $previewUrlService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The metatag service.
   *
   * @var \Drupal\vactory_dashboard\Service\MetatagService
   */
  protected $metatagService;

  /**
   * The alias validation service.
   *
   * @var \Drupal\vactory_dashboard\Service\AliasValidationService
   */
  protected AliasValidationService $aliasValidationService;

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
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MetatagService $metatag_Service, AliasValidationService $aliasValidationService, PreviewUrlService $previewUrlService, NodeService $node_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->metatagService = $metatag_Service;
    $this->aliasValidationService = $aliasValidationService;
    $this->previewUrlService = $previewUrlService;
    $this->nodeService = $node_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('vactory_dashboard.metatag_service'),
      $container->get('vactory_dashboard.alias_validation'),
      $container->get('vactory_dashboard.preview_url'),
      $container->get('vactory_dashboard.node_service'),
    );
  }

  /**
   * Returns the users dashboard page.
   *
   * @return array
   *   A render array for the users dashboard.
   */
  public function add() {
    // Get current language.
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();

    // Get node available languages.
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    foreach ($languages as $language) {
      $available_languages_list[] = [
        'id' => $language->getId(),
        'url' => Url::fromRoute('vactory_dashboard.vactory_page.add', [], ['language' => $language]),
      ];
    }

    return [
      '#theme' => 'vactory_dashboard_node_add',
      '#type' => 'page',
      '#language' => $current_language,
      '#isParagraphViewEnabled' => $this->isParagraphTypeEnabled('views_reference'),
      '#isParagraphBlockEnabled' => $this->isParagraphTypeEnabled('vactory_paragraph_block'),
      '#node_default_lang' => $current_language,
      '#available_languages' => $available_languages_list,
    ];
  }

  /**
   * Checks if a given Paragraph bundle is enabled for
   * field_vactory_paragraphs on vactory_page content type.
   *
   * @param string $paragraph_bundle
   *   The machine name of the paragraph type to check.
   *
   * @return bool
   *   TRUE if the paragraph type is enabled, FALSE otherwise.
   */
  public function isParagraphTypeEnabled(string $paragraph_bundle): bool {
    $field_config = FieldConfig::loadByName('node', 'vactory_page', 'field_vactory_paragraphs');

    if (!$field_config) {
      return FALSE;
    }

    $settings = $field_config->getSettings();

    if (!isset($settings['handler_settings']['target_bundles'])) {
      return FALSE;
    }

    return array_key_exists($paragraph_bundle, $settings['handler_settings']['target_bundles']);
  }

  /**
   * Returns the users dashboard page.
   *
   * @return array
   *   A render array for the users dashboard.
   */
  public function edit($id) {
    // Get node by id and current language.
    $vid = $this->entityTypeManager
      ->getStorage('node')
      ->getLatestRevisionId($id);

    $node = $this->entityTypeManager->getStorage('node')->loadRevision($vid);
    if (!$node) {
      throw new NotFoundHttpException('Node not found');
    }

    // Get current language.
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();

    // Get node translation by current language.
    $has_translation = $node->hasTranslation($current_language);
    if (!$has_translation) {
      return $this->redirect('vactory_dashboard.vactory_page.add.translation', ['id' => $node->id()]);
    }

    $node_translation = $node->getTranslation($current_language);

    // Get node available languages.
    $available_languages = $node->getTranslationLanguages();
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    foreach ($languages as $language) {
      $available_languages_list[] = [
        'id' => $language->getId(),
        'url' => in_array($language->getId(), array_keys($available_languages)) ? '/' . $language->getId() . '/admin/dashboard/vactory_page/edit/' . $id : '/' . $language->getId() . '/admin/dashboard/vactory_page/edit/' . $id . '/add/translation',
      ];
    }

    $meta_tags = $this->metatagService->prepareMetatags($node_translation ?? $node);

    return [
      '#theme' => 'vactory_dashboard_node_edit',
      '#type' => 'page',
      '#language' => $node_translation ? $node_translation->language()
        ->getId() : $node->language()->getId(),
      '#node' => $this->nodeService->processVactoryPageData($node_translation ?? $node),
      '#changed' => $node_translation ? $node_translation->get('changed')->value : $node->get('changed')->value,
      '#label' => $node_translation ? $node_translation->label() : $node->label(),
      '#nid' => $id,
      '#status' => $node_translation ? $node_translation->get('status')->value : $node->get('status')->value,
      '#available_languages' => $available_languages_list,
      '#node_default_lang' => $node->language()->getId(),
      '#has_translation' => $node_translation ? TRUE : FALSE,
      '#meta_tags' => $meta_tags,
      '#isParagraphViewEnabled' => $this->isParagraphTypeEnabled('views_reference'),
      '#isParagraphBlockEnabled' => $this->isParagraphTypeEnabled('vactory_paragraph_block'),
      '#preview_url' => $this->previewUrlService->getPreviewUrl($node),
    ];
  }

  /**
   * Returns the users dashboard page.
   *
   * @return array
   *   A render array for the users dashboard.
   */
  public function translate($id) {
    $vid = $this->entityTypeManager
      ->getStorage('node')
      ->getLatestRevisionId($id);

    $node = $this->entityTypeManager->getStorage('node')->loadRevision($vid);
    if (!$node) {
      throw new NotFoundHttpException('Node not found');
    }

    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();
    try {
      if ($node->hasTranslation($current_language)) {
        return $this->redirect('vactory_dashboard.vactory_page.edit', ['id' => $node->id()]);
      }
    }
    catch (\Exception $e) {
    }

    // Get node available languages.
    $available_languages = $node->getTranslationLanguages();
    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];
    foreach ($languages as $language) {
      $available_languages_list[] = [
        'id' => $language->getId(),
        'url' => in_array($language->getId(), array_keys($available_languages)) ? '/' . $language->getId() . '/admin/dashboard/vactory_page/edit/' . $id : '/' . $language->getId() . '/admin/dashboard/vactory_page/edit/' . $id . '/add/translation',
      ];
    }

    $meta_tags = $this->metatagService->prepareMetatags($node);

    return [
      '#theme' => 'vactory_dashboard_node_edit',
      '#type' => 'page',
      '#language' => $current_language,
      '#node' => $this->nodeService->processVactoryPageData($node),
      '#nid' => $id,
      '#label' => $node->label(),
      '#status' => $node->get('status')->value,
      '#available_languages' => $available_languages_list,
      '#node_default_lang' => $node->language()->getId(),
      '#has_translation' => FALSE,
      '#meta_tags' => $meta_tags,
    ];
  }

  /**
   * Returns available block templates.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing templates data.
   */
  public function getTemplates(Request $request) {
    // Dummy data for templates.
    $templates = \Drupal::service('vactory_dynamic_field.vactory_provider_manager')
      ->getModalWidgetsList();
    return new JsonResponse([
      'data' => $templates,
      'message' => 'Templates retrieved successfully',
    ]);
  }

  public function getParagraphBlocks(Request $request) {
    $paragraph_blocks = $this->nodeService->getParagraphBlocksList();
    return new JsonResponse([
      'data' => $paragraph_blocks,
      'message' => 'Paragraph blocks retrieved successfully',
    ]);
  }

  public function getParagraphViews(Request $request) {
    $paragraph_views = $this->nodeService->getParagraphViewsList();
    return new JsonResponse([
      'data' => $paragraph_views,
      'message' => 'Paragraph views retrieved successfully',
    ]);
  }

  public function updateDisplays($vid) {
    $displays = $this->nodeService->getViewDisplays($vid);
    return new JsonResponse([
      'data' => $displays,
      'message' => 'Displays retrieved successfully',
    ]);
  }

  /**
   * Saves or updates a node.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function saveEdit($nid, Request $request) {
    try {
      // Get the request content.
      $content = json_decode($request->getContent(), TRUE);
      if (empty($content)) {
        throw new \Exception('Invalid request data');
      }

      // Extract data from request.
      $node_default_lang = NULL;
      $language = $content['language'] ?? \Drupal::languageManager()
        ->getDefaultLanguage()
        ->getId();
      $settings = $content['settings'] ?? [];
      $seo = $content['seo'] ?? [];
      $blocks = $content['blocks'] ?? [];
      $has_translation = $content['has_translation'] ?? TRUE;
      $status = $content['status'] ?? TRUE;
      $client_changed = $content['changed'] ?? NULL;

      if (empty($settings['title'])) {
        return new JsonResponse([
          'message' => $this->t('Title is required.'),
        ], 400);
      }

      // Load node by nid.
      if ($nid) {
        $vid = $this->entityTypeManager
          ->getStorage('node')
          ->getLatestRevisionId($nid);

        /** @var \Drupal\node\NodeInterface $node */
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

      // Update node fields.
      if (isset($settings['title'])) {
        $node->getTranslation($language)->set('title', $settings['title']);
      }

      if (isset($settings['alias'])) {
        $alias = trim($settings['alias']);

        if (!empty($alias)) {
          $this->aliasValidationService->validate($alias, $node->id());

          $node->path->pathauto = PathautoState::SKIP;
          $path_alias = $this->entityTypeManager->getStorage('path_alias')
            ->create([
              'path' => '/node/' . $node->id(),
              'alias' => '/' . ltrim($alias, '/'),
              'langcode' => $language,
            ]);
          $path_alias->save();
        }
        else {
          // Empty alias - let pathauto generate one or use no alias.
          $node->path->pathauto = PathautoState::CREATE;
        }
      }

      // Update SEO fields if they exist.
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
      $this->nodeService->updateParagraphsInNode($node, $blocks, $language, $node_default_lang);

      // Save the node.
      $node->save();

      return new JsonResponse([
        'message' => $this->t('Node saved successfully'),
        'node_id' => $node->id(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'message' => $e->getMessage(),
      ], 400);
    }
  }

  /**
   * Saves or updates a node.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function save(Request $request) {
    try {
      // Get the request content.
      $content = json_decode($request->getContent(), TRUE);
      if (empty($content)) {
        throw new \Exception('Invalid request data');
      }

      // Extract data from request.
      $node_default_lang = NULL;
      $language = $content['language'] ?? \Drupal::languageManager()
        ->getDefaultLanguage()
        ->getId();

      $settings = $content['settings'] ?? [];
      $seo = $content['seo'] ?? [];
      $blocks = $content['blocks'] ?? [];
      $status = $content['status'] ?? TRUE;

      if (empty($settings['title'])) {
        return new JsonResponse([
          'message' => $this->t('Title is required'),
        ], 400);
      }

      $node_data = [
        'type' => 'vactory_page',
        'langcode' => $language,
        'uid' => \Drupal::currentUser()->id(),
        'status' => $status,
        'path' => [
          'pathauto' => ltrim($settings['alias']) === '',
          'alias' => '/' . ltrim($settings['alias'], '/'),
        ],
      ];

      $node = Node::create($node_data);

      if ($node->hasField('moderation_state')) {
        $node->set('moderation_state', $status ? 'published' : 'draft');
      }

      if (isset($settings['title'])) {
        $node->set('title', $settings['title']);
      }

      // Update SEO fields if they exist.
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

      // Update blocks/paragraphs if they exist.
      if ($node->hasField('field_vactory_paragraphs')) {
        $this->nodeService->saveParagraphsInNode($node, $blocks, $language);
      }

      // Save the node.
      $node->isNew();
      $node->save();

      return new JsonResponse([
        'message' => $this->t('Node saved successfully'),
        'node_id' => $node->id(),
        'edit_path' => Url::fromRoute('vactory_dashboard.vactory_page.edit', ['id' => $node->id()])
          ->toString(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'message' => $this->t('Internal server error'),
      ], 400);
    }
  }

}
