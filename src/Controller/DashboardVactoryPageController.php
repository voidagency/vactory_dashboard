<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\Entity\Node;
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

    // Get enabled languages from our custom configuration.
    $config = \Drupal::config('vactory_dashboard.global.settings');
    $enabled_languages = $config->get('dashboard_languages') ?? [];
    $enabled_languages = array_filter($enabled_languages);

    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];

    foreach ($languages as $language) {
      $lang_id = $language->getId();

      // Only show languages that are enabled in our custom configuration.
      if (empty($enabled_languages) || isset($enabled_languages[$lang_id])) {
        $available_languages_list[] = [
          'id' => $lang_id,
          'url' => Url::fromRoute('vactory_dashboard.vactory_page.add', [], ['language' => $language])->toString(),
        ];
      }
    }

    $paragraph_flags = $this->nodeService->isParagraphTypeEnabled();
    return [
      '#theme' => 'vactory_dashboard_node_add',
      '#type' => 'page',
      '#language' => $current_language,
      ...$paragraph_flags,
      '#node_default_lang' => $current_language,
      '#available_languages' => $available_languages_list,
      '#banner' => $this->nodeService->getBannerConfiguration("vactory_page"),
      '#domain_access_enabled' => \Drupal::moduleHandler()->moduleExists('domain_access'),
    ];
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

    if (!$vid) {
      throw new NotFoundHttpException('Node revision not found');
    }

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

    // Get enabled languages from our custom configuration.
    $config = \Drupal::config('vactory_dashboard.global.settings');
    $enabled_languages = $config->get('dashboard_languages') ?? [];
    $enabled_languages = array_filter($enabled_languages);

    // Get existing translations.
    $existing_translations = $node->getTranslationLanguages();

    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];

    foreach ($languages as $language) {
      $lang_id = $language->getId();
      $has_existing_translation = array_key_exists($lang_id, $existing_translations);

      // Show language if: enabled in config OR has existing translation.
      $is_enabled = empty($enabled_languages) || isset($enabled_languages[$lang_id]);

      if ($is_enabled || $has_existing_translation) {
        $available_languages_list[] = [
          'id' => $lang_id,
          'url' => $has_existing_translation 
            ? '/' . $lang_id . '/admin/dashboard/vactory_page/edit/' . $id 
            : '/' . $lang_id . '/admin/dashboard/vactory_page/edit/' . $id . '/add/translation',
          'has_translation' => $has_existing_translation,
        ];
      }
    }

    $meta_tags = $this->metatagService->prepareMetatags($node_translation ?? $node);

    $paragraph_flags = $this->nodeService->isParagraphTypeEnabled();

    // Get bundle fields for domain access settings.
    $fields = $this->nodeService->getBundleFields('vactory_page', count($available_languages_list));

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
      '#fields' => $fields,
      ...$paragraph_flags,
      '#preview_url' => $this->previewUrlService->getPreviewUrl($node),
      '#banner' => $this->nodeService->getBannerConfiguration("vactory_page"),
      '#domain_access_enabled' => \Drupal::moduleHandler()->moduleExists('domain_access'),
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

    if (!$vid) {
      throw new NotFoundHttpException('Node revision not found');
    }

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

    // Get enabled languages from our configuration.
    $config = \Drupal::config('vactory_dashboard.global.settings');
    $enabled_languages = $config->get('dashboard_languages') ?? [];
    $enabled_languages = array_filter($enabled_languages);

    // Get existing translations.
    $existing_translations = $node->getTranslationLanguages();

    $languages = \Drupal::languageManager()->getLanguages();
    $available_languages_list = [];

    foreach ($languages as $language) {
      $lang_id = $language->getId();
      $has_existing_translation = array_key_exists($lang_id, $existing_translations);

      // Show language if: enabled in config OR has existing translation.
      $is_enabled = empty($enabled_languages) || isset($enabled_languages[$lang_id]);

      if ($is_enabled || $has_existing_translation) {
        $available_languages_list[] = [
          'id' => $lang_id,
          'url' => $has_existing_translation 
            ? '/' . $lang_id . '/admin/dashboard/vactory_page/edit/' . $id 
            : '/' . $lang_id . '/admin/dashboard/vactory_page/edit/' . $id . '/add/translation',
          'has_translation' => $has_existing_translation,
        ];
      }
    }

    $meta_tags = $this->metatagService->prepareMetatags($node);

    $fields = $this->nodeService->getBundleFields('vactory_page', count($available_languages_list));

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
      '#fields' => $fields,
      '#meta_tags' => $meta_tags,
      '#domain_access_enabled' => \Drupal::moduleHandler()->moduleExists('domain_access'),
      '#banner' => $this->nodeService->getBannerConfiguration("vactory_page"),
    ];
  }

  /**
   * Returns available block templates.
   *
   * This method retrieves the list of available dynamic field templates
   * (widgets) from the vactory_dynamic_field module. The returned list
   * automatically excludes widgets that are configured as excluded in the
   * Dynamic Field Settings (/admin/config/system/dynamic-field-configuration).
   *
   * The excluded widgets configuration from vactory_dynamic_field.settings
   * is automatically respected, ensuring consistency across both the standard
   * Drupal UI and the Dashboard UI.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing templates data, with excluded widgets filtered
   *   out.
   *
   * @see \Drupal\vactory_dynamic_field\Form\DynamicFieldSettingsForm
   * @see \Drupal\vactory_dynamic_field\WidgetsManager::getModalWidgetsList()
   * @see \Drupal\vactory_dynamic_field\WidgetsManager::getDisabledWidgets()
   */
  public function getTemplates(Request $request) {
    // Get templates from vactory_dynamic_field.
    $templates = \Drupal::service('vactory_dynamic_field.vactory_provider_manager')
      ->getModalWidgetsList();

    $templates = $this->filterExcludedWidgets($templates);

    return new JsonResponse([
      'data' => $templates,
      'message' => 'Templates retrieved successfully',
    ]);
  }

  /**
   * Filter out excluded widgets from templates list.
   *
   * This method provides additional filtering to ensure excluded widgets
   * configured in Dynamic Field Settings are properly removed from the
   * templates list. It re-applies the exclusion logic to fix a bug where
   * widgets may still appear after being unset.
   *
   * @param array $templates
   *   The templates array from getModalWidgetsList().
   *
   * @return array
   *   The filtered templates array with excluded widgets removed.
   */
  protected function filterExcludedWidgets(array $templates) {
    $config = \Drupal::config('vactory_dynamic_field.settings');
    $excluded_widgets_yaml = $config->get('excluded_widgets') ?: '';

    if (empty($excluded_widgets_yaml)) {
      return $templates;
    }

    try {
      $excluded_config = \Drupal\Component\Serialization\Yaml::decode($excluded_widgets_yaml) ?: [];
    }
    catch (\Exception $e) {
      \Drupal::logger('vactory_dashboard')->error('Invalid YAML in excluded_widgets: @message', ['@message' => $e->getMessage()]);
      return $templates;
    }

    // Build list of disabled widgets
    $disabled_widgets = [];
    foreach ($excluded_config as $provider_id => $config_data) {
      if (isset($config_data['settings'])) {
        $settings = $config_data['settings'];
        $disable_all = $settings['disable_all'] ?? FALSE;

        if ($disable_all) {
          // Disable all widgets from this provider except those in 'except' list
          $disabled_widgets[$provider_id] = [
            'disable_all' => TRUE,
            'except' => array_map(function($id) use ($provider_id) {
              return $provider_id . ':' . $id;
            }, $settings['except'] ?? []),
          ];
        }
        else {
          // Disable specific widgets
          if (isset($settings['widgets']) && is_array($settings['widgets'])) {
            foreach ($settings['widgets'] as $widget_id) {
              $disabled_widgets[$provider_id . ':' . $widget_id] = TRUE;
            }
          }
        }
      }
    }

    // Filter templates
    foreach ($templates as $category => &$widgets) {
      if (!is_array($widgets)) {
        continue;
      }

      foreach ($widgets as $widget_key => $widget) {
        // Check if this specific widget is disabled
        if (isset($disabled_widgets[$widget_key])) {
          unset($widgets[$widget_key]);
          continue;
        }

        // Check if all widgets from this provider are disabled
        $provider_id = explode(':', $widget_key)[0];
        if (isset($disabled_widgets[$provider_id]) && is_array($disabled_widgets[$provider_id])) {
          if ($disabled_widgets[$provider_id]['disable_all']) {
            // Check if widget is in exception list
            if (!in_array($widget_key, $disabled_widgets[$provider_id]['except'])) {
              unset($widgets[$widget_key]);
            }
          }
        }
      }

      // Remove empty categories
      if (empty($widgets)) {
        unset($templates[$category]);
      }
    }

    return $templates;
  }

  /**
   * Returns a JSON response containing the list of available paragraph blocks.
   *
   * This method fetches a list of paragraph blocks from the NodeService
   * and returns it in a structured JSON response, including a success message.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing:
   *   - data: An array of available paragraph blocks.
   *   - message: A success message string.
   */
  public function getParagraphBlocks() {
    $paragraph_blocks = $this->nodeService->getParagraphBlocksList();
    return new JsonResponse([
      'data' => $paragraph_blocks,
      'message' => 'Paragraph blocks retrieved successfully',
    ]);
  }

  /**
   * Returns a JSON response with a list of available paragraph views.
   *
   * This method fetches paragraph views from the NodeService and returns
   * them in a JSON response along with a success message.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing:
   *   - data: An array of available paragraph views.
   *   - message: A success message string.
   */
  public function getParagraphViews() {
    $paragraph_views = $this->nodeService->getParagraphViewsList();
    return new JsonResponse([
      'data' => $paragraph_views,
      'message' => 'Paragraph views retrieved successfully',
    ]);
  }

  /**
   * Returns a JSON response with the list of displays for a given view ID.
   *
   * This method retrieves the available displays for the given Views view
   * machine name using the NodeService, and returns them in a structured
   * JSON response with a success message.
   *
   * @param string $vid
   *   The machine name of the view for which displays should be retrieved.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing:
   *   - data: An array of display options for the view.
   *   - message: A success message string.
   */
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
      $banner = $content['banner'] ?? [];

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

        if (!$vid) {
          throw new NotFoundHttpException('Node revision not found');
        }

        /** @var \Drupal\node\NodeInterface $node */
        $node = $this->entityTypeManager->getStorage('node')
          ->loadRevision($vid);
        if (!$node) {
          throw new NotFoundHttpException('Node not found');
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

      if (isset($settings['summary'])) {
        $node->getTranslation($language)->set('node_summary', $settings['summary']);
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

      // Update domain access and other fields if they exist.
      $fields = $content['fields'] ?? [];
      foreach ($fields as $field_name => $field_value) {
        if ($node->hasField($field_name)) {
          if ($field_value || is_array($field_value) || is_bool($field_value)) {
            $node->getTranslation($language)->set($field_name, $field_value);
          }
        }
      }

      // Update blocks/paragraphs if they exist.
      $this->nodeService->updateParagraphsInNode($node, $blocks, $language, $node_default_lang);

      $this->nodeService->saveBannerInNode($node->getTranslation($language), $banner);

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
      $banner = $content['banner'] ?? [];

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

      if (isset($settings['summary'])) {
        $node->set('node_summary', $settings['summary']);
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

      $this->nodeService->saveBannerInNode($node, $banner);

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
