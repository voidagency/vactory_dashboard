<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Service for node utilities.
 */
class NodeService {

  /**
   * The entity type manager.
   */
  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
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

}
