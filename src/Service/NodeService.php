<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\vactory_dashboard\Constants\DashboardConstants;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\views\Views;
use Drupal\field\Entity\FieldConfig;

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

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, ConfigFactoryInterface $configFactory, EntityRepositoryInterface $entityRepository) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->configFactory = $configFactory;
    $this->entityRepository = $entityRepository;
  }

  /**
   * Process node data.
   */
  public function processNode($entity, $fields) {
    $node_data = [];
    // Get node fields
    foreach ($fields as $field) {
      if ($field['type'] === 'autocomplete') {
        $field_name = $field['name'];
        $entity_reference_field = $entity->get($field_name);

        if ($field['multiple']) {
          $node_data[$field_name] = [];
          foreach ($entity_reference_field as $item) {
            if ($item->target_id) {
              $referenced_entity = $this->entityTypeManager->getStorage($field['target_type'])
                ->load($item->target_id);
              if ($referenced_entity) {
                $referenced_entity = $this->entityRepository->getTranslationFromContext($referenced_entity);
                $node_data[$field_name][] = [
                  'id' => (string) $referenced_entity->id(),
                  'label' => $referenced_entity->label(),
                ];
              }
            }
          }
        }
        else {
          $target_id = $entity_reference_field->target_id;
          if ($target_id) {
            $referenced_entity = $this->entityTypeManager->getStorage($field['target_type'])
              ->load($target_id);
            if ($referenced_entity) {
              $referenced_entity = $this->entityRepository->getTranslationFromContext($referenced_entity);

              $node_data[$field_name] = [
                'id' => (string) $referenced_entity->id(),
                'label' => $referenced_entity->label(),
              ];
            }
          }
          else {
            $node_data[$field_name] = NULL;
          }
        }
        continue;
      }

      if ($field['type'] === 'radios') {
        $target_id = $entity->get($field['name'])->target_id ?? NULL;
        if ($target_id !== NULL) {
          $node_data[$field['name']] = (string) $target_id;
        }
        else {
          $node_data[$field['name']] = $entity->get($field['name'])->value ?? "";
        }
        continue;
      }
      if ($field['type'] === 'field_wysiwyg_dynamic') {
        $this->prepareWysiwygDynamic($entity, $node_data, $field['name']);
        continue;
      }

      if ($field['type'] === 'daterange') {
        $values = $entity->get($field['name'])->getValue();
        if (!empty($values)) {
          // Cardinalité simple.
          $node_data[$field['name']] = [
            'value' => $values[0]['value'] ?? '',
            'end_value' => $values[0]['end_value'] ?? '',
          ];
        }
        else {
          $node_data[$field['name']] = [
            'value' => '',
            'end_value' => '',
          ];
        }
        continue;
      }

      if ($field['type'] === 'faqfield' || $field['name'] === 'field_faq') {
        // Récupérer les valeurs FAQ
        $faq_values = $entity->get($field['name'])->getValue();

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
        $media = $this->loadMediaFromEntityField($entity, $field['name']);
        if ($media instanceof MediaInterface) {
          if ($media->hasField('field_media_image') && !$media->get('field_media_image')
              ->isEmpty()) {
            $file = $media->get('field_media_image')->entity;
            if ($file instanceof FileInterface) {
              $node_data[$field['name']] = [
                'id' => $entity->get($field['name'])->target_id,
                'url' => $file->createFileUrl(),
                'path' => $field['name'],
                'key' => -1,
              ];
            }
          }
        }
      }
      elseif ($field['type'] == 'remote_video') {
        $media = $this->loadMediaFromEntityField($entity, $field['name']);
        if ($media instanceof MediaInterface) {
          if ($media->hasField('field_media_oembed_video') && !$media->get('field_media_oembed_video')
              ->isEmpty()) {
            $remote_video = $media->get('field_media_oembed_video')->value;
            if (!empty($remote_video)) {
              $node_data[$field['name']] = [
                'id' => $entity->get($field['name'])->target_id,
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
        $media = $this->loadMediaFromEntityField($entity, $field['name']);
        if ($media instanceof MediaInterface) {
          if ($media->hasField($field_name) && !$media->get($field_name)
              ->isEmpty()) {
            $file = $media->get($field_name)->entity;
            if ($file instanceof FileInterface) {
              $node_data[$field['name']] = [
                'id' => $entity->get($field['name'])->target_id,
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
        $node_data[$field['name']] = array_values(explode(" ", $entity->get($field['name'])->value) ?? []);
        $node_data[$field['name']] = array_filter($node_data[$field['name']], function($vccNode) {
          return $vccNode !== "";
        });
      }
      elseif (($field['type'] === 'checkboxes' || $field['type'] === 'select') && isset($field['target_type'])) {
        if ($field['multiple']) {
          $ids = $entity->get($field['name'])->getValue() ?? [];
          $node_data[$field['name']] = array_values(array_map(function($value) {
            return $value['target_id'];
          }, $ids));
        }
        else {
          $node_data[$field['name']] = $entity->get($field['name'])->target_id ?? NULL;
        }
      }
      else {
        $node_data[$field['name']] = $entity->get($field['name'])->value ?? "";
      }
    }

    $this->prepareVactoryParagraphsData($entity, $node_data);

    return $node_data;
  }

  /**
   * Load media from entity field.
   */
  private function loadMediaFromEntityField(EntityInterface $entity, string $fieldName): ?MediaInterface {
    $target_id = $entity->get($fieldName)->target_id ?? NULL;
    return $target_id ? $this->entityTypeManager->getStorage('media')->load($target_id) : NULL;
  }

  /**
   * Process Vactory page data.
   */
  public function processVactoryPageData($node) {
    $node_data = [];

    // Get node fields.
    $node_data['title'] = $node->getTitle();
    $node_data['body'] = $node->hasField('node_summary') ? $node->get('node_summary')->value ?? "" : "";

    $this->prepareVactoryParagraphsData($node, $node_data);

    $alias = \Drupal::service('path_alias.manager')
      ->getAliasByPath('/node/' . $node->id());
    $node_data['alias'] = $alias;
    $node_data['status'] = $node->isPublished();
    return $node_data;
  }

  /**
   * Prepare vactory paragraphs data.
   */
  private function prepareVactoryParagraphsData($node, &$node_data) {
    $paragraphs = [];
    $lang = \Drupal::languageManager()->getCurrentLanguage()->getId();
    if ($node->hasField('field_vactory_paragraphs')) {
      $paragraphsData = $node->get('field_vactory_paragraphs')->getValue();
      foreach ($paragraphsData as $paragraphData) {
        $paragraph = Paragraph::load($paragraphData['target_id']);
        if ($paragraph->hasTranslation($lang)) {
          $paragraph = $paragraph->getTranslation($lang);
        }
        if (!$paragraph) {
          continue;
        }
        if ($paragraph->hasField('field_vactory_component') && $paragraph->bundle() == 'vactory_component') {
          $vactoryComponents = $paragraph->field_vactory_component->getValue();
          foreach ($vactoryComponents as $component) {
            $widgetData = Json::decode($component['widget_data']);
            $widgetConfig = \Drupal::service('vactory_dynamic_field.vactory_provider_manager')
              ->loadSettings($component['widget_id']);
            $this->processWidgetData($widgetData, $widgetConfig);
            $widgetId = $component['widget_id'];
            $paragraphs[] = [
              'title' => $paragraph->hasField('field_vactory_title') ? $paragraph->get('field_vactory_title')->value : "",
              'bundle' => $paragraph->bundle(),
              'show_title' => $paragraph->hasField('field_vactory_flag') && $paragraph->get('field_vactory_flag')->value === "1",
              'width' => $paragraph->hasField('paragraph_container') ? $paragraph->get('paragraph_container')->value : "",
              'spacing' => $paragraph->hasField('container_spacing') ? $paragraph->get('container_spacing')->value : "",
              'css_classes' => $paragraph->hasField('paragraph_css_class') ? $paragraph->get('paragraph_css_class')->value : "",
              'pid' => $paragraphData['target_id'],
              'revision_id' => $paragraph->getRevisionId(),
              'widget_id' => $widgetId,
              'widget_data' => $widgetData,
              'widget_config' => $widgetConfig,
            ];
          }
        }

        if ($paragraph->bundle() === 'vactory_paragraph_block') {
          $paragraphs[] = [
            'title' => $paragraph->hasField('field_vactory_title') ? $paragraph->get('field_vactory_title')->value : "",
            'bundle' => $paragraph->bundle(),
            'show_title' => $paragraph->hasField('field_vactory_flag') && $paragraph->get('field_vactory_flag')->value === "1",
            'width' => $paragraph->hasField('paragraph_container') ? $paragraph->get('paragraph_container')->value : "",
            'spacing' => $paragraph->hasField('container_spacing') ? $paragraph->get('container_spacing')->value : "",
            'css_classes' => $paragraph->hasField('paragraph_css_class') ? $paragraph->get('paragraph_css_class')->value : "",
            'pid' => $paragraphData['target_id'],
            'revision_id' => $paragraph->getRevisionId(),
            'screenshot' => \Drupal::service('file_url_generator')
              ->generateAbsoluteString(\Drupal::service('extension.path.resolver')
                  ->getPath('module', 'vactory_dashboard') . '/assets/images/default-screenshot.png'),
            'body' => $paragraph->get('field_vactory_body')->value ?? "",
            'block_id' => $paragraph->get('field_vactory_block')->plugin_id ?? "",
            'block_settings' => $paragraph->get('field_vactory_block')->settings ?? [],
          ];
        }

        if (in_array($paragraph->bundle(), [
          'vactory_paragraph_multi_template',
          'views_reference',
        ])) {
          $blockID = $paragraph->hasField('field_views_reference') ? $paragraph->get('field_views_reference')->first()?->getValue()['target_id'] : "";
          $paragraphs[] = [
            'id' => $node->id(),
            'block_id' => $blockID,
            'title' => $paragraph->hasField('field_vactory_title') ? $paragraph->get('field_vactory_title')->value : "",
            'display_id' => $paragraph->hasField('field_views_reference') ? $paragraph->get('field_views_reference')->first()?->getValue()['display_id'] : "",
            'displays' => $this->getViewDisplays($blockID),
            'bundle' => $paragraph->bundle(),
            'show_title' => $paragraph->hasField('field_vactory_flag') && $paragraph->get('field_vactory_flag')->value === "1",
            'width' => $paragraph->hasField('paragraph_container') ? $paragraph->get('paragraph_container')->value : "",
            'spacing' => $paragraph->hasField('container_spacing') ? $paragraph->get('container_spacing')->value : "",
            'css_classes' => $paragraph->hasField('paragraph_css_class') ? $paragraph->get('paragraph_css_class')->value : "",
            'pid' => $paragraphData['target_id'],
            'revision_id' => $paragraph->getRevisionId(),
            'screenshot' => \Drupal::service('file_url_generator')
              ->generateAbsoluteString(\Drupal::service('extension.path.resolver')
                  ->getPath('module', 'vactory_dashboard') . '/assets/images/default-screenshot.png'),
          ];
        }
      }
    }
    $node_data['paragraphs'] = $paragraphs;
  }

  /**
   * Prepare vactory paragraphs data.
   */
  private function prepareWysiwygDynamic($node, &$node_data, $fieldName) {
    $paragraphs = [];
    $default_value = $node->get($fieldName)->getValue() ?? [];
    foreach ($default_value as $component) {
      $widgetId = $component['widget_id'];
      $widgetData = Json::decode($component['widget_data']);
      $widgetConfig = \Drupal::service('vactory_dynamic_field.vactory_provider_manager')
        ->loadSettings($widgetId);
      $this->processWidgetData($widgetData, $widgetConfig);
      $paragraphs[] = [
        'bundle' => 'vactory_component',
        'widget_id' => $widgetId,
        'widget_data' => $widgetData,
        'widget_config' => $widgetConfig,
      ];
    }
    $node_data[$fieldName] = $paragraphs;
  }

  /**
   * Find image fields inside a given dynamic field.
   */
  private function findMediaFieldsInDynamicField(array $fields, $type = 'image'): array {
    $mediaFields = [];

    foreach ($fields as $key => $field) {
      if (is_array($field) && isset($field['type']) && $field['type'] === $type) {
        $mediaFields[$key] = $field;
      }
      elseif (is_array($field)) {
        // Recurse into nested fields
        $nested = $this->findMediaFieldsInDynamicField($field, $type);
        if (!empty($nested)) {
          $mediaFields = array_merge($mediaFields, $nested);
        }
      }
    }

    return $mediaFields;
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
    $imageFields = $this->findMediaFieldsInDynamicField($widgetConfig);
    $extraFieldsImageFields = array_filter($widgetConfig['extra_fields'] ?? [], function($field) {
      return ($field['type'] ?? "") === 'image';
    });
    $imageFields = array_keys($imageFields);
    $extraFieldsImageFields = array_keys($extraFieldsImageFields);

    // Get fields with type remote_video.
    $remoteVideoFields = $this->findMediaFieldsInDynamicField($widgetConfig, 'remote_video');
    $extraFieldsRemoteVideoFields = array_filter($widgetConfig['extra_fields'] ?? [], function($field) {
      return ($field['type'] ?? "") === 'remote_video';
    });
    $remoteVideoFields = array_keys($remoteVideoFields);
    $extraFieldsRemoteVideoFields = array_keys($extraFieldsRemoteVideoFields);

    // Get fields with type file.
    $fileFields = $this->findMediaFieldsInDynamicField($widgetConfig, 'file');
    $fileRemoteVideoFields = array_filter($widgetConfig['extra_fields'] ?? [], function($field) {
      return ($field['type'] ?? "") === 'file';
    });
    $fileFields = array_keys($fileFields);
    $extraFieldsFileFields = array_keys($fileRemoteVideoFields);

    // Process extra fields image fields.
    $this->handleExtraFieldsImageType($widgetData, $extraFieldsImageFields);
    $this->handleExtraFieldsRemoteVideoType($widgetData, $extraFieldsRemoteVideoFields);
    $this->handleExtraFieldsFileType($widgetData, $extraFieldsFileFields);

    // Process each numeric key (0, 1, etc.) in widgetData.
    $this->handleNonExtraFieldsImageType($widgetData, $imageFields);
    $this->handleNonExtraFieldsRemoteVideoType($widgetData, $remoteVideoFields);
    $this->handleNonExtraFieldsFileType($widgetData, $fileFields);
  }

  /**
   * Hanlde extra fields for image type.
   */
  private function handleExtraFieldsImageType(&$widgetData, $extraFieldsImageFields) {
    $extra_fields = &$widgetData['extra_field'];
    foreach ($extra_fields ?? [] as $key => &$item) {
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
              $extra_fields[$key][$randomKey]['selection'][0]['url'] = $url;
              $widgetData[$key][$randomKey]['selection'][0]['url'] = $url;
            }
          }
        }
      }
    }
  }

  /**
   * Handle non extra fields for image type.
   */
  private function handleNonExtraFieldsImageType(&$widgetData, $imageFields) {
    foreach ($widgetData ?? [] as $key => &$item) {
      // Skip non-numeric keys like 'extra_field' and 'pending_content'.
      if (!is_numeric($key)) {
        continue;
      }

      // Process each image field in the item.
      foreach ($item as $skey => $subitem) {
        foreach ($imageFields as $fieldName) {
          $container = str_starts_with($skey, 'group_') ? $item[$skey] : $item;
          $parentKey = str_starts_with($skey, 'group_') ? $skey : NULL;

          // Check if image field exists in the current container
          if (isset($container[$fieldName]) && is_array($container[$fieldName])) {
            $randomKey = array_key_first($container[$fieldName]);

            if ($randomKey && isset($container[$fieldName][$randomKey]['selection'][0]['target_id'])) {
              $mediaId = $container[$fieldName][$randomKey]['selection'][0]['target_id'];
              // Load the media entity.
              /** @var \Drupal\media\Entity\Media $media */
              $media = $this->entityTypeManager->getStorage('media')
                ->load($mediaId);
              if ($media instanceof MediaInterface) {
                $url = '';
                if (
                  $media->hasField('field_media_image') &&
                  !$media->get('field_media_image')->isEmpty()
                ) {
                  /** @var \Drupal\file\Entity\File $file */
                  $file = $media->get('field_media_image')->entity;
                  if ($file instanceof FileInterface) {
                    $url = $file->createFileUrl();
                  }
                }

                // Apply URL update depending on whether it's grouped or not
                if ($parentKey) {
                  $item[$parentKey][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $widgetData[$key][$parentKey][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                }
                else {
                  $item[$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $widgetData[$key][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                }
              }
            }
          }
        }
      }
    }
  }

  /**
   * Hanlde extra fields for file type.
   */
  private function handleExtraFieldsfileType(&$widgetData, $extraFieldsFileFields) {
    $extra_fields = &$widgetData['extra_field'];
    foreach ($extra_fields ?? [] as $key => &$item) {
      foreach ($extraFieldsFileFields as $fieldName) {
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
              if ($media->hasField('field_media_file') && !$media->get('field_media_file')
                  ->isEmpty()) {
                /** @var \Drupal\file\Entity\File $file */
                $file = $media->get('field_media_file')->entity;
                if ($file instanceof FileInterface) {
                  $url = $file->createFileUrl();
                }
              }

              // Add the URL to the image data.
              $extra_fields[$key][$randomKey]['selection'][0]['url'] = $url;
              $extra_fields[$key][$randomKey]['selection'][0]['name'] = $media->label();
              $widgetData[$key][$randomKey]['selection'][0]['url'] = $url;
              $widgetData[$key][$randomKey]['selection'][0]['name'] = $media->label();
            }
          }
        }
      }
    }
  }

  /**
   * Handle non extra fields for file type.
   */
  private function handleNonExtraFieldsfileType(&$widgetData, $fileFields) {
    foreach ($widgetData ?? [] as $key => &$item) {
      // Skip non-numeric keys like 'extra_field' and 'pending_content'.
      if (!is_numeric($key)) {
        continue;
      }

      // Process each image field in the item.
      foreach ($item as $skey => $subitem) {
        foreach ($fileFields as $fieldName) {
          $container = str_starts_with($skey, 'group_') ? $item[$skey] : $item;
          $parentKey = str_starts_with($skey, 'group_') ? $skey : NULL;

          // Check if image field exists in the current container
          if (isset($container[$fieldName]) && is_array($container[$fieldName])) {
            $randomKey = array_key_first($container[$fieldName]);

            if ($randomKey && isset($container[$fieldName][$randomKey]['selection'][0]['target_id'])) {
              $mediaId = $container[$fieldName][$randomKey]['selection'][0]['target_id'];
              // Load the media entity.
              /** @var \Drupal\media\Entity\Media $media */
              $media = $this->entityTypeManager->getStorage('media')
                ->load($mediaId);
              if ($media instanceof MediaInterface) {
                $url = '';
                if (
                  $media->hasField('field_media_file') &&
                  !$media->get('field_media_file')->isEmpty()
                ) {
                  /** @var \Drupal\file\Entity\File $file */
                  $file = $media->get('field_media_file')->entity;
                  if ($file instanceof FileInterface) {
                    $url = $file->createFileUrl();
                  }
                }

                // Apply URL update depending on whether it's grouped or not
                if ($parentKey) {
                  $item[$parentKey][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $item[$parentKey][$fieldName][$randomKey]['selection'][0]['name'] = $media->label();
                  $widgetData[$key][$parentKey][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $widgetData[$key][$parentKey][$fieldName][$randomKey]['selection'][0]['name'] = $media->label();
                }
                else {
                  $item[$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $item[$fieldName][$randomKey]['selection'][0]['name'] = $media->label();
                  $widgetData[$key][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $widgetData[$key][$fieldName][$randomKey]['selection'][0]['name'] = $media->label();
                }
              }
            }
          }
        }
      }
    }
  }

  /**
   * Handle extra fields for remote video type.
   */
  private function handleExtraFieldsRemoteVideoType(&$widgetData, $extraFieldsRemoteVideoFields) {
    $extra_fields = &$widgetData['extra_field'];
    foreach ($extra_fields ?? [] as $key => &$item) {
      foreach ($extraFieldsRemoteVideoFields as $fieldName) {
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
              if ($media->hasField('field_media_oembed_video') && !$media->get('field_media_oembed_video')
                  ->isEmpty()) {
                $extra_fields[$key][$randomKey]['selection'][0]['url'] = $media->get('field_media_oembed_video')->value;
                $extra_fields[$key][$randomKey]['selection'][0]['name'] = $media->get('field_media_oembed_video')
                  ->getEntity()
                  ->label();
              }
            }
          }
        }
      }
    }
  }

  /**
   * Handle non extra fields for remote video type.
   */
  private function handleNonExtraFieldsRemoteVideoType(&$widgetData, $remoteVideoFields) {
    foreach ($widgetData ?? [] as $key => &$item) {
      // Skip non-numeric keys like 'extra_field' and 'pending_content'.
      if (!is_numeric($key)) {
        continue;
      }

      // Process each image field in the item.
      foreach ($item as $skey => $subitem) {
        foreach ($remoteVideoFields as $fieldName) {
          $container = str_starts_with($skey, 'group_') ? $item[$skey] : $item;
          $parentKey = str_starts_with($skey, 'group_') ? $skey : NULL;

          // Check if image field exists in the current container
          if (isset($container[$fieldName]) && is_array($container[$fieldName])) {
            $randomKey = array_key_first($container[$fieldName]);

            if ($randomKey && isset($container[$fieldName][$randomKey]['selection'][0]['target_id'])) {
              $mediaId = $container[$fieldName][$randomKey]['selection'][0]['target_id'];
              // Load the media entity.
              /** @var \Drupal\media\Entity\Media $media */
              $media = $this->entityTypeManager->getStorage('media')
                ->load($mediaId);
              if ($media instanceof MediaInterface) {
                $url = '';
                if (
                  $media->hasField('field_media_oembed_video') &&
                  !$media->get('field_media_oembed_video')->isEmpty()
                ) {
                  $url = $media->get('field_media_oembed_video')->value;
                }

                // Apply URL update depending on whether it's grouped or not
                if ($parentKey) {
                  $item[$parentKey][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $widgetData[$key][$parentKey][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $widgetData[$key][$parentKey][$fieldName][$randomKey]['selection'][0]['name'] = $media->get('field_media_oembed_video')
                    ->getEntity()
                    ->label();
                }
                else {
                  $item[$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $widgetData[$key][$fieldName][$randomKey]['selection'][0]['url'] = $url;
                  $widgetData[$key][$fieldName][$randomKey]['selection'][0]['name'] = $media->get('field_media_oembed_video')
                    ->getEntity()
                    ->label();
                }
              }
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
  public function getBundleFields($bundle, $countActiveLangs = 0, $type = 'node') {
    $fields = $this->entityFieldManager->getFieldDefinitions($type, $bundle);
    $field_definitions = [];

    $form_mode = 'default';
    $form_display = \Drupal::service('entity_display.repository')
      ->getFormDisplay($type, $bundle, $form_mode);

    $components = $form_display->getComponents();
    uasort($components, function($a, $b) {
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
      $cardinality = $field_definition->getFieldStorageDefinition()
        ->getCardinality();
      $field_required = $field_definition->isRequired();
      $field_label = $field_definition->getLabel();
      $field_description = $field_definition->getDescription();

      $field_info = [
        'name' => $field_name,
        'type' => $field_type,
        'label' => $field_label,
        'required' => $field_required,
        'settings' => $field_settings,
        'description' => $field_description,
      ];

      // Continue with your custom logic...
      switch ($field_type) {
        case 'entity_reference':
          $field_info['target_type'] = $field_settings['target_type'];
          if ($field_settings['target_type'] === 'taxonomy_term' || $field_settings['target_type'] === 'user') {
            $component = $form_display->getComponent($field_name);
            if ($component) {
              $widget_type = $component['type'];
              if ($widget_type === 'entity_reference_autocomplete') {
                $field_info['type'] = 'autocomplete';
              }
              elseif ($widget_type === 'options_select') {
                $field_info['type'] = 'select';
              }
              elseif ($widget_type === 'options_buttons' || $widget_type === 'options_checkboxes') {
                // Choix dynamique selon cardinalité
                if ($cardinality == -1) {
                  $field_info['type'] = 'checkboxes';
                }
                else {
                  $field_info['type'] = 'radios';
                }
              }
            }
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
          $form = \Drupal::formBuilder()
            ->getForm('Drupal\vactory_dashboard\Form\CkeditorFieldForm', $field_name);
          $field_info['textFormatField'] = \Drupal::service('renderer')
            ->render($form);
          break;

        case 'field_cross_content':
          $field_info['options'] = $this->getCrossContentOptions($bundle, $field_info);
          $field_info['multiple'] = TRUE;
          break;

        case 'daterange':
          $field_info['type'] = 'daterange';
          $field_info['multiple'] = FALSE;
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
    $langcode = $field_definition['langcode'] ?? \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();

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
      if ($entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
        $entity = $entity->getTranslation($langcode);
      }

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
      //unset($fields['field_vactory_paragraphs']);
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

  /**
   * Update paragraphs in given node.
   */
  public function updateParagraphsInNode(&$node, $blocks, $language, $node_default_lang) {
    if (!empty($blocks)) {
      $ordered_paragraphs = [];
      foreach ($blocks as $block) {
        $bundle = $block['bundle'] ?? "vactory_component";
        if ($bundle === 'vactory_component') {
          $this->updateParagraphTemplatesInNode($block, $language, $node_default_lang, $ordered_paragraphs);
        }
        else {
          if ($bundle === 'vactory_paragraph_block') {
            $this->updateParagraphBlocksInNode($block, $language, $node_default_lang, $ordered_paragraphs);
          } else if ($bundle === 'views_reference') {
          $this->updateParagraphViewsInNode($block, $language, $node_default_lang, $ordered_paragraphs);
        }
          else {
            $ordered_paragraphs[] = [
              'target_id' => $block['id'],
              'target_revision_id' => $block['revision_id'],
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

  /**
   * Update paragraph templates in node.
   */
  private function updateParagraphTemplatesInNode($block, $language, $node_default_lang, &$ordered_paragraphs) {
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

  /**
   * Update paragraph blocks in node.
   */
  private function updateParagraphBlocksInNode($block, $language, $node_default_lang, &$ordered_paragraphs) {
    $paragraph_entity = NULL;
    $existing_block_id = $block['block_settings']['id'] ?? NULL;
    $paragraph = [
      "type" => "vactory_paragraph_block",
      "field_vactory_title" => $block['title'],
      "field_vactory_flag" => $block['show_title'],
      "paragraph_container" => $block['width'],
      "container_spacing" => $block['spacing'],
      "paragraph_css_class" => $block['css_classes'],
      "field_vactory_block" => [
        "plugin_id" => $block['blockType'],
        "settings" => $block['blockType'] === $existing_block_id ? $block['block_settings'] ?? [] : [],
      ],
      "field_vactory_body" => [
        'value' => $block['content'] ?? '',
        'format' => 'full_html',
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
        ->set('field_vactory_block', [
          "plugin_id" => $block['blockType'],
          "settings" => $block['blockType'] === $existing_block_id ? $block['block_settings'] ?? [] : [],
        ]);

      $paragraph_entity->getTranslation($language)
        ->set('field_vactory_body', [
          'value' => $block['content'] ?? '',
          'format' => 'full_html',
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
          ->set('field_vactory_block', [
            "plugin_id" => $block['blockType'],
            "settings" => $block['blockType'] === $existing_block_id ? $block['block_settings'] ?? [] : [],
          ]);

        if (isset($block['title'])) {
          $paragraph_entity->getTranslation($language)
            ->set('field_vactory_title', $block['title']);
        }

        $paragraph_entity->getTranslation($language)
          ->set('field_vactory_body', [
            'value' => $block['content'] ?? '',
            'format' => 'full_html',
          ]);

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

  /**
   * Updates or creates a "views_reference" paragraph entity for a node.
   *
   * This method handles both creation of new paragraphs and updates of existing
   * ones, including multilingual translations when the current language differs
   * from the node's default language.
   *
   * The method also populates a passed-in list of ordered paragraph references
   * (used later to attach to the node).
   *
   * @param array $block
   *   An associative array containing block data.
   * @param string $language
   *   The current language code.
   * @param string $node_default_lang
   *   The default language code of the node.
   * @param array &$ordered_paragraphs
   *   A reference to the array that collects paragraph entity references
   *   (with target_id and target_revision_id) to later attach to the node.
   */
  private function updateParagraphViewsInNode($block, $language, $node_default_lang, &$ordered_paragraphs) {
    $paragraph_entity = NULL;
    $existing_view_id = $block['block_settings']['id'] ?? NULL;
    $paragraph = [
      "type" => "views_reference",
      "field_vactory_title" => $block['title'],
      "paragraph_container" => $block['width'],
      "container_spacing" => $block['spacing'],
      "paragraph_css_class" => $block['css_classes'],
      "field_views_reference" => [
        "target_id" => $block['blockType'],
        "display_id" => $block['displayID'],
        "settings" => $block['blockType'] === $existing_view_id ? $block['block_settings'] ?? [] : [],
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
        ->set('field_views_reference', [
          "target_id" => $block['blockType'],
          "display_id" => $block['displayID'],
          "settings" => $block['blockType'] === $existing_view_id ? $block['block_settings'] ?? [] : [],
        ]);

      if (isset($block['title'])) {
        $paragraph_entity->getTranslation($language)
          ->set('field_vactory_title', $block['title']);
      }

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
          ->set('field_views_reference', [
            "target_id" => $block['blockType'],
            "display_id" => $block['displayID'],
            "settings" => $block['blockType'] === $existing_view_id ? $block['block_settings'] ?? [] : [],
          ]);

        if (isset($block['title'])) {
          $paragraph_entity->getTranslation($language)
            ->set('field_vactory_title', $block['title']);
        }

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

  /**
   * Saves paragraph blocks into a node's "field_vactory_paragraphs" field.
   *
   * This method takes an array of structured "blocks", builds corresponding
   * Paragraph entities based on their bundle type, and attaches them to the
   * given node. Each block can represent a different type of paragraph,
   * such as:
   * - vactory_component
   * - vactory_paragraph_block
   * - views_reference
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node entity where the paragraphs will be saved (passed by reference).
   * @param array $blocks
   *   An array of associative arrays, each representing a paragraph block.
   * @param string $language
   *   The language code to assign to each created paragraph entity.
   */
  public function saveParagraphsInNode(&$node, $blocks, $language) {
    $ordered_paragraphs = [];
    if (!empty($blocks)) {
      foreach ($blocks as $block) {
        $bundle = $block['bundle'] ?? "vactory_component";
        $paragraph = [
          "type" => $bundle,
          "field_vactory_title" => $block['title'],
          "field_vactory_flag" => $block['show_title'],
          "paragraph_container" => $block['width'],
          "container_spacing" => $block['spacing'],
          "paragraph_css_class" => $block['css_classes'],
        ];
        if ($bundle === 'vactory_component') {
          $paragraph['field_vactory_component'] = [
            "widget_id" => $block['widget_id'],
            "widget_data" => json_encode($block['widget_data']),
          ];
        }
        else {
          if ($bundle === 'vactory_paragraph_block') {
            $paragraph['field_vactory_block'] = [
              "plugin_id" => $block['blockType'],
              "settings" => [],
            ];
            $paragraph['field_vactory_body'] = [
              'value' => $block['content'] ?? '',
              'format' => 'full_html',
            ];
          }
          else if ($bundle === 'views_reference') {
            $paragraph['field_views_reference'] = [
                "target_id" => $block['blockType'],
                "display_id" => $block['displayID'],
            ];
          }
        }
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

  /**
   * Get paragraph blocks list.
   */
  public function getParagraphBlocksList() {
    $field_vactory_block = $this->entityFieldManager->getFieldDefinitions('paragraph', 'vactory_paragraph_block');
    $field_vactory_block = $field_vactory_block['field_vactory_block'] ?? [];

    $blocks = $field_vactory_block->getSettings()['selection_settings']['plugin_ids'] ?? [];
    $paragraph_blocks = [];
    foreach ($blocks as $block) {
      $paragraph_blocks[] = [
        'id' => $block,
        'label' => $block,
      ];
    }
    return $paragraph_blocks;
  }

  /**
   * Get paragraph views list.
   */

  public function getParagraphViewsList() {
    $view_storage = \Drupal::entityTypeManager()->getStorage('view');
    $all_views = $view_storage->loadMultiple();

    $paragraph_views = [];

    foreach ($all_views as $view_id => $view_config) {
      if (!$view_config->status()) {
        continue; // Skip disabled views
      }

      $paragraph_views[] = [
        'id' => $view_id,
        'label' => $view_config->label(),
      ];
    }

    return $paragraph_views;
  }

  /**
   * Get all displays of a given view.
   *
   * @param string $view_id
   *   The ID of the view.
   *
   * @return array
   *   An array of display IDs and their titles.
   */
  public function getViewDisplays($view_id) {
    $view = Views::getView($view_id);

    if (!$view) {
      return [];
    }

    $displays = [];

    foreach ($view->storage->get('display') as $display_id => $display) {
      if ($display['display_plugin'] === 'page') {
        $displays[$display_id] = $display['display_title'] ?? $display_id;
      }
    }

    return $displays;
  }

  /**
   * Retrieves the enabled status of specific Paragraph types 
   * for a given content type's "field_vactory_paragraphs" field.
   *
   * This method checks if the following Paragraph bundles are enabled:
   * - views_reference
   * - vactory_paragraph_block
   * - vactory_component
   * - vactory_paragraph_multi_template
   *
   * @param string $paragraph_bundle
   *   (Unused) The machine name of a Paragraph type (kept for potential future use).
   * @param string $bundle
   *   (Optional) The machine name of the content type (node bundle).
   *   Defaults to 'vactory_page'.
   *
   * @return array
   *   An associative array indicating whether each of the target paragraph types
   *   is enabled, or FALSE if the field or configuration is not found.
   */
  public function isParagraphTypeEnabled($bundle="vactory_page"): array {
    $field_config = FieldConfig::loadByName('node', $bundle, 'field_vactory_paragraphs');

    if (!$field_config) {
      return [];
    }

    $settings = $field_config->getSettings();

    if (!isset($settings['handler_settings']['target_bundles'])) {
      return [];
    }

    return [
      '#isParagraphViewEnabled' => array_key_exists('views_reference', $settings['handler_settings']['target_bundles']),
      '#isParagraphBlockEnabled' => array_key_exists('vactory_paragraph_block', $settings['handler_settings']['target_bundles']),
      '#isParagraphTemplateEnabled' => array_key_exists('vactory_component', $settings['handler_settings']['target_bundles']),
      '#isParagraphMultipleEnabled' => array_key_exists('vactory_paragraph_multi_template', $settings['handler_settings']['target_bundles'])
    ];
  }

}
