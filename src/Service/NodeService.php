<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\vactory_dashboard\Constants\DashboardConstants;

/**
 * Service for node utilities.
 */
class NodeService {

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, ConfigFactoryInterface $configFactory) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->configFactory = $configFactory;
  }

  /**
   * Process node data.
   */
  public function processNode(NodeInterface $node, $fields) {
    $node_data = [];
    // Get node fields
    foreach ($fields as $field) {
      if ($field['type'] === 'faqfield' || $field['name'] === 'field_faq') {
        // Récupérer les valeurs FAQ
        $faq_values = $node->get($field['name'])->getValue();

        $formatted_faq = [];
        foreach ($faq_values as $item) {
          if (!empty($item['question']) || !empty($item['answer'])) {
            $formatted_faq[] = [
              'question' => $item['question'] ?? '',
              'answer' => $item['answer'] ?? '',
            ];
          }
        }

        $node_data[$field['name']] = $formatted_faq;
        continue;
      }

      if ($field['type'] == 'image') {
        $target_id = $node->get($field['name'])->target_id;
        $media = $this->entityTypeManager->getStorage('media')
          ->load($target_id);
        if ($media instanceof MediaInterface) {
          if ($media->hasField('field_media_image') && !$media->get('field_media_image')
              ->isEmpty()) {
            /** @var \Drupal\file\Entity\File $file */
            $file = $media->get('field_media_image')->entity;
            if ($file instanceof FileInterface) {
              $node_data[$field['name']] = [
                'id' => $target_id,
                'url' => $file->createFileUrl(),
                'path' => $field['name'],
                'key' => -1,
              ];
            }
          }
        }
      }
      elseif ($field['type'] == 'remote_video') {
        $target_id = $node->get($field['name'])->target_id;
        $media = $this->entityTypeManager->getStorage('media')
          ->load($target_id);
        if ($media instanceof MediaInterface) {
          if ($media->hasField('field_media_oembed_video') && !$media->get('field_media_oembed_video')
              ->isEmpty()) {
            $remote_video = $media->get('field_media_oembed_video')->value;
            if (!empty($remote_video)) {
              $node_data[$field['name']] = [
                'id' => $target_id,
                'url' => $remote_video,
                'path' => $field['name'],
                'key' => -1,
                'name' => $media->get('field_media_oembed_video')
                  ->getEntity()
                  ->label(),
              ];
            }
          }
        }
      }
      elseif ($field['type'] == 'file' || $field['type'] == 'private_file') {
        $field_name = $field['type'] === 'file' ? 'field_media_file' : 'field_media_file_1';
        $target_id = $node->get($field['name'])->target_id;
        $media = $this->entityTypeManager->getStorage('media')
          ->load($target_id);
        if ($media instanceof MediaInterface) {
          if ($media->hasField($field_name) && !$media->get($field_name)
              ->isEmpty()) {
            $file = $media->get($field_name)->entity;
            if ($file instanceof FileInterface) {
              $node_data[$field['name']] = [
                'id' => $target_id,
                'url' => $file->createFileUrl(),
                'path' => $field['name'],
                'key' => -1,
                'name' => $media->label(),
              ];
            }
          }
        }
      }
      elseif ($field['type'] === "field_cross_content") {
        $node_data[$field['name']] = array_values(explode(" ", $node->get($field['name'])->value) ?? []);
        $node_data[$field['name']] = array_filter($node_data[$field['name']], function($vccNode) {
          return $vccNode !== "";
        });
      }
      elseif ($field['type'] == 'select' && isset($field['target_type'])) {
        if ($field['multiple']) {
          $ids = $node->get($field['name'])->getValue() ?? [];
          $node_data[$field['name']] = array_values(array_map(function($value) {
            return $value['target_id'];
          }, $ids));
        }
        else {
          $node_data[$field['name']] = $node->get($field['name'])->target_id ?? NULL;
        }
      }
      else {
        $node_data[$field['name']] = $node->get($field['name'])->value ?? "";
      }
    }

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

    return $node_data;
  }

  /**
   * Process Vactory page data.
   */
  public function processVactoryPageData($node) {
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
   * Get bundle fields information.
   *
   * @param string $bundle
   *   The bundle name.
   *
   * @return array
   *   Array of field definitions with their settings.
   */
  public function getBundleFields($bundle, $countActiveLangs = 0) {
    $fields = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
    $field_definitions = [];

    $form_mode = 'default';
    $form_display = \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', $bundle, $form_mode);

    $components = $form_display->getComponents();
    uasort($components, function ($a, $b) {
      return ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
    });

    foreach ($components as $field_name => $component) {
      // Skip technical/system fields
      if (!isset($fields[$field_name]) || in_array($field_name, DashboardConstants::SKIPPED_FIELDS)) {
        continue;
      }

      $field_definition = $fields[$field_name];
      $field_type = $field_definition->getType();
      $field_settings = $field_definition->getSettings();
      $cardinality = $field_definition->getFieldStorageDefinition()->getCardinality();
      $field_required = $field_definition->isRequired();
      $field_label = $field_definition->getLabel();

      $field_info = [
        'name' => $field_name,
        'type' => $field_type,
        'label' => $field_label,
        'required' => $field_required,
        'settings' => $field_settings,
      ];

      // Continue with your custom logic...
      switch ($field_type) {
        case 'entity_reference':
          $field_info['target_type'] = $field_settings['target_type'];
          if ($field_settings['target_type'] === 'taxonomy_term' || $field_settings['target_type'] === 'user') {
            $field_info['type'] = 'select';
            $field_info['multiple'] = $cardinality == -1;
            $field_info['options'] = $this->load_entity_reference_options($field_info);
          }
          if ($field_settings['target_type'] === 'media') {
            $field_info['type'] = reset($field_settings['handler_settings']['target_bundles']);
          }
          break;

        case 'list_string':
          $field_info['type'] = 'select';
          $field_info['multiple'] = $cardinality == -1;
          $field_info['options'] = $field_definition->getSettings()['allowed_values'] ?? [];
          break;

        case 'text_with_summary':
        case 'text_long':
          $field_info['format'] = 'full_html';
          $field_info['type'] = 'text_with_summary';
          $form = \Drupal::formBuilder()->getForm('Drupal\vactory_dashboard\Form\CkeditorFieldForm', $field_name);
          $field_info['textFormatField'] = \Drupal::service('renderer')->render($form);
          break;

        case 'field_cross_content':
          $field_info['options'] = $this->getCrossContentOptions($bundle, $field_info);
          $field_info['multiple'] = TRUE;
          break;
      }

      $field_info['is_translatable'] = $countActiveLangs === 0 || $field_definition->isTranslatable();
      $field_definitions[$field_name] = $field_info;
    }
    return $field_definitions;
  }

  /**
   * Get cross content options.
   */
  public function getCrossContentOptions($type, array $field_definition) {
    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $node_type = NodeType::load($type);
    if ($node_type->getThirdPartySetting('vactory_cross_content', 'enabling', '') == 1) {
      $content_type_selected = $node_type->getThirdPartySetting('vactory_cross_content', 'content_type', '');
      if (!empty($content_type_selected) && $content_type_selected != 'none') {
        $type = $content_type_selected;
      }
    }
    $node_list = \Drupal::entityTypeManager()
      ->getListBuilder('node')
      ->getStorage()
      ->loadByProperties([
        'type' => $type,
      ]);

    foreach ($node_list as $key => $node) {
      $node_list[$key] = \Drupal::service('entity.repository')
        ->getTranslationFromContext($node, $language);
    }

    // @todo: implement for edit.
    $current_node = "";
    if (empty($current_node)) {
      $current_node = 0;
    }
    else {
      // $current_node = $current_node[0]['value'];
    }
    $options = [];
    foreach ($node_list as $key => $value) {
      if ($key == $current_node) {
        continue;
      }
      $options[$key] = $value->label();
    }
    return $options;
  }

  /**
   * Load entity reference options.
   */
  public function load_entity_reference_options(array $field_definition): array {
    $target_type = $field_definition['settings']['target_type'] ?? NULL;
    $handler_settings = $field_definition['settings']['handler_settings'] ?? [];

    if (!$target_type) {
      return [];
    }

    $entity_storage = \Drupal::entityTypeManager()->getStorage($target_type);

    // Add conditions for bundles if specified
    $query = $entity_storage->getQuery();
    $query->accessCheck(TRUE);

    // Handle target bundles (e.g. specific vocabularies or content types)
    if (!empty($handler_settings['target_bundles'])) {
      $bundle_keys = array_keys($handler_settings['target_bundles']);
      if ($target_type === 'taxonomy_term') {
        $query->condition('vid', $bundle_keys, 'IN');
      }
      elseif ($this->entityTypeManager
        ->getDefinition($target_type)
        ->getKey('bundle')
      ) {
        $query->condition('bundle', $bundle_keys, 'IN');
      }
    }

    $ids = $query->execute();
    $entities = $entity_storage->loadMultiple($ids);

    $options = [];
    foreach ($entities as $entity) {
      if ($entity->label()) {
        $options[$entity->id()] = $entity->label();
      }
    }

    return $options;
  }

  /**
   * If the bundle has a paragraphs field.
   */
  public function hasParagraphsField(array &$fields): bool {
    if (!in_array('field_vactory_paragraphs', array_keys($fields))) {
      return FALSE;
    }

    $settings = $fields['field_vactory_paragraphs']['settings'];
    if (in_array('vactory_component', $settings['handler_settings']['target_bundles'] ?? [])) {
      unset($fields['field_vactory_paragraphs']);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Get referenced taxonomies.
   */
  public function getReferencedTaxonomies($bundle) {
    // Load saved config with selected vocabularies.
    $config = $this->configFactory->get('vactory_dashboard.advanced.content_types');
    $taxonomy_selections = $config->get('taxonomy_selections') ?? [];

    // Get selected vocabularies for this bundle.
    $selected_vocabularies = $taxonomy_selections[$bundle] ?? [];

    $result = [];
    foreach ($selected_vocabularies as $vocab_id) {
      $vocabulary = Vocabulary::load($vocab_id);
      if ($vocabulary) {
        $result[] = [
          'id' => $vocab_id,
          'label' => $vocabulary->label(),
        ];
      }
    }

    return $result;
  }

}
