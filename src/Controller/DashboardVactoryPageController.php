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
   * Constructs a new DashboardUsersController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MetatagService $metatag_Service, AliasValidationService $aliasValidationService, PreviewUrlService $previewUrlService) {
    $this->entityTypeManager = $entity_type_manager;
    $this->metatagService = $metatag_Service;
    $this->aliasValidationService = $aliasValidationService;
    $this->previewUrlService = $previewUrlService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('vactory_dashboard.metatag_service'),
      $container->get('vactory_dashboard.alias_validation'),
      $container->get('vactory_dashboard.preview_url')
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
      '#theme' => 'vactory_dashboard_vactory_page_add',
      '#language' => $current_language,
      '#node_default_lang' => $current_language,
      '#available_languages' => $available_languages_list,
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
      '#theme' => 'vactory_dashboard_vactory_page_edit',
      '#language' => $node_translation ? $node_translation->language()
        ->getId() : $node->language()->getId(),
      '#node' => $this->processNode($node_translation ?? $node),
      '#changed' => $node_translation ? $node_translation->get('changed')->value : $node->get('changed')->value,
      '#label' => $node_translation ? $node_translation->label() : $node->label(),
      '#nid' => $id,
      '#status' => $node_translation ? $node_translation->get('status')->value : $node->get('status')->value,
      '#available_languages' => $available_languages_list,
      '#node_default_lang' => $node->language()->getId(),
      '#has_translation' => $node_translation ? TRUE : FALSE,
      '#meta_tags' => $meta_tags,
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
      '#theme' => 'vactory_dashboard_vactory_page_edit',
      '#language' => $current_language,
      '#node' => $this->processNode($node),
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
   *
   */
  private function processNode($node) {
    $node_data = [];

    // Get node fields.
    $node_data['title'] = $node->getTitle();
    $node_data['body'] = $node->hasField('node_summary') ? $node->get('node_summary')->value ?? "" : "";
      
    $paragraphs = [];
    $lang = \Drupal::languageManager()->getCurrentLanguage()->getId();
    if ($node->hasField('field_vactory_paragraphs')) {
      $paragraphsData = $node->get('field_vactory_paragraphs')->getValue();
      foreach ($paragraphsData as $paragraphData) {
        $paragraph = Paragraph::load($paragraphData['target_id']);
        if ($paragraph->hasTranslation($lang)) {
          $paragraph = $paragraph->getTranslation($lang);
        }
        if ($paragraph && $paragraph->hasField('field_vactory_component') && $paragraph->bundle() == 'vactory_component') {
          $vactoryComponents = $paragraph->field_vactory_component->getValue();
          foreach ($vactoryComponents as $component) {
            $widgetData = Json::decode($component['widget_data']);
            $widgetConfig = \Drupal::service('vactory_dynamic_field.vactory_provider_manager')
              ->loadSettings($component['widget_id']);
            $this->processWidgetData($widgetData, $widgetConfig);
            $widgetId = $component['widget_id'];
            $paragraphs[] = [
              'title' => $paragraph->get('field_vactory_title')->value,
              'show_title' => $paragraph->get('field_vactory_flag')->value === "1",
              'width' => $paragraph->get('paragraph_container')->value,
              'spacing' => $paragraph->get('container_spacing')->value,
              'css_classes' => $paragraph->get('paragraph_css_class')->value,
              'pid' => $paragraphData['target_id'],
              'widget_id' => $widgetId,
              'widget_data' => $widgetData,
              'widget_config' => $widgetConfig,
            ];
          }
        }
      }
    }
    $node_data['paragraphs'] = $paragraphs;

    $alias = \Drupal::service('path_alias.manager')
      ->getAliasByPath('/node/' . $node->id());
    $node_data['alias'] = $alias;
    $node_data['status'] = $node->isPublished();
    return $node_data;
  }

  /**
   * Process widget data to add media URLs.
   *
   * @param array $widgetData
   *   The widget data to process.
   * @param array $widgetConfig
   *   The widget configuration.
   */
  private function processWidgetData(&$widgetData, $widgetConfig) {
    // Get fields with type image.
    $imageFields = array_filter($widgetConfig['fields'], function($field) {
      return ($field['type'] ?? "") === 'image';
    });

    $extraFieldsImageFields = array_filter($widgetConfig['extra_fields'] ?? [], function($field) {
      return ($field['type'] ?? "") === 'image';
    });

    $imageFields = array_keys($imageFields);

    $extraFieldsImageFields = array_keys($extraFieldsImageFields);

    // Process extra fields image fields.
    foreach ($widgetData['extra_field'] ?? [] as $key => &$item) {
      foreach ($extraFieldsImageFields as $fieldName) {
        if ($key === $fieldName) {
          $randomKey = array_key_first($item ?? []);
          if ($randomKey && isset($item[$randomKey]['selection'][0]['target_id'])) {
            $mediaId = $item[$randomKey]['selection'][0]['target_id'];
            // Load the media entity.
            /** @var \Drupal\media\Entity\Media $media */
            $media = $this->entityTypeManager->getStorage('media')
              ->load($mediaId);
            if ($media instanceof MediaInterface) {
              // Get the file URL.
              $url = '';
              if ($media->hasField('field_media_image') && !$media->get('field_media_image')
                  ->isEmpty()) {
                /** @var \Drupal\file\Entity\File $file */
                $file = $media->get('field_media_image')->entity;
                if ($file instanceof FileInterface) {
                  $url = $file->createFileUrl();
                }
              }

              // Add the URL to the image data.
              $item[$randomKey]['selection'][0]['url'] = $url;
              $widgetData[$key][$randomKey]['selection'][0]['url'] = $url;
            }
          }
        }
      }
    }

    // Process each numeric key (0, 1, etc.) in widgetData.
    foreach ($widgetData ?? [] as $key => &$item) {
      // Skip non-numeric keys like 'extra_field' and 'pending_content'.
      if (!is_numeric($key)) {
        continue;
      }

      // Process each image field in the item.
      foreach ($imageFields as $fieldName) {
        if (isset($item[$fieldName]) && is_array($item[$fieldName])) {
          // Get the random key (e.g., f998c32812bd2cc9395e57409a3f6986)
          $randomKey = array_key_first($item[$fieldName]);

          if ($randomKey && isset($item[$fieldName][$randomKey]['selection'][0]['target_id'])) {
            $mediaId = $item[$fieldName][$randomKey]['selection'][0]['target_id'];
            // Load the media entity.
            /** @var \Drupal\media\Entity\Media $media */
            $media = $this->entityTypeManager->getStorage('media')
              ->load($mediaId);
            if ($media instanceof MediaInterface) {
              // Get the file URL.
              $url = '';
              if ($media->hasField('field_media_image') && !$media->get('field_media_image')
                  ->isEmpty()) {
                /** @var \Drupal\file\Entity\File $file */
                $file = $media->get('field_media_image')->entity;
                if ($file instanceof FileInterface) {
                  $url = $file->createFileUrl();
                }
              }

              // Add the URL to the image data.
              $item[$fieldName][$randomKey]['selection'][0]['url'] = $url;
              $widgetData[$key][$fieldName][$randomKey]['selection'][0]['url'] = $url;
            }
          }
        }
      }
    }
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
              $paragraph_entity->getTranslation($language)->set('field_vactory_component', [
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
