<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\paragraphs\ParagraphsTypeInterface;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\vactory_dashboard\Constants\DashboardConstants;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\views\Views;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\file\FileUrlGeneratorInterface;

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

  /**
   * The file URL generator service.
   *
   * Used to generate absolute or relative URLs for files.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, ConfigFactoryInterface $configFactory, EntityRepositoryInterface $entityRepository, FileUrlGeneratorInterface $fileUrlGenerator, ModuleHandlerInterface $moduleHandler) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->configFactory = $configFactory;
    $this->entityRepository = $entityRepository;
    $this->fileUrlGenerator = $fileUrlGenerator;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * Find the paragraph field name for a given bundle.
   *
   * Searches for any field that:
   * - Type: entity_reference_revisions
   * - Target type: paragraph
   *
   * @param string $bundle
   *   The bundle name.
   * @param string $entity_type
   *   The entity type (default: 'node').
   *
   * @return string|null
   *   The field name or NULL if not found.
   */
  public function getParagraphFieldName($bundle, $entity_type = 'node') {
    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    foreach ($fields as $field_name => $field_definition) {
      // Check if it's entity_reference_revisions targeting paragraph
      if ($field_definition->getType() === 'entity_reference_revisions') {
        $settings = $field_definition->getSettings();
        if (isset($settings['target_type']) && $settings['target_type'] === 'paragraph') {
          return $field_name;
        }
      }
    }

    return NULL;
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

      if ($field['type'] === 'vactory_quiz_question' || $field['name'] === 'field_quiz_questions') {
        // Récupérer les valeurs Quiz Questions
        $quiz_values = $entity->get($field['name'])->getValue();

        $formatted_quiz = [];
        foreach ($quiz_values as $item) {
          if (!empty($item['question_text_value'])) {
            $quiz_item = [
              'question_number' => $item['question_number'] ?? '',
              'question_text_value' => $item['question_text_value'] ?? '',
              'question_text_format' => $item['question_text_format'] ?? 'basic_html',
              'question_type' => $item['question_type'] ?? 'multiple',
              'question_answers' => $item['question_answers'] ?? '[]',
              'question_reward' => $item['question_reward'] ?? 1,
              'question_penalty' => $item['question_penalty'] ?? 0,
            ];

            $formatted_quiz[] = $quiz_item;
          }
        }

        $node_data[$field['name']] = $formatted_quiz;
        continue;
      }

      // Handle Google Map field type
      if ($field['type'] === 'vactory_google_map_field') {
        $map_value = $entity->get($field['name'])->getValue();
        if (!empty($map_value)) {
          $map_item = reset($map_value);
          $node_data[$field['name']] = [
            'lat' => $map_item['lat'] ?? '',
            'lng' => $map_item['lon'] ?? '', // Database uses 'lon', we use 'lng' in frontend
            'zoom' => $map_item['zoom'] ?? 10,
            'type' => $map_item['type'] ?? 'roadmap',
            'address' => '',
          ];
        }
        else {
          $node_data[$field['name']] = [
            'lat' => '',
            'lng' => '',
            'zoom' => 10,
            'type' => 'roadmap',
            'address' => '',
          ];
        }
        continue;
      }

      $media_target_type = $field['target_type'] ?? "";

      if (in_array($field['type'], [
          'remote_video',
          'file',
          'private_file',
          'image',
        ]) && $media_target_type === 'media') {
        $node_data[$field['name']] = $this->prepareMediaData($entity, $field['name'], $field['name'], $field['type']);
        continue;
      }

      if ($field['type'] === 'image') {
        // Handle image field type (stores files directly with alt/title)
        $image_value = $entity->get($field['name'])->getValue();
        if (!empty($image_value)) {
          $image_item = reset($image_value);
          if (!empty($image_item['target_id'])) {
            $file = $this->entityTypeManager->getStorage('file')->load($image_item['target_id']);
            if ($file instanceof FileInterface) {
              $node_data[$field['name']] = [
                'id' => $image_item['target_id'],
                'url' => $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri()),
                'alt' => $image_item['alt'] ?? '',
                'title' => $image_item['title'] ?? '',
                'width' => $image_item['width'] ?? NULL,
                'height' => $image_item['height'] ?? NULL,
              ];
            }
          }
        }
      }
      elseif ($field['type'] === "field_cross_content") {
        $node_data[$field['name']] = array_values(explode(" ", $entity->get($field['name'])->value ?? "") ?? []);
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
      elseif ($field['type'] === 'string' && !empty($field['multiple'])) {
        // Handle string fields with multiple values
        $values = $entity->get($field['name'])->getValue() ?? [];
        $node_data[$field['name']] = array_values(array_map(function($item) {
          return $item['value'] ?? '';
        }, $values));
        if (empty($node_data[$field['name']])) {
          $node_data[$field['name']] = [''];
        }
      }
      else {
        if ($field['type'] === 'datetime' && $field['settings']['datetime_type'] === 'datetime') {
          $datetime_value = $entity->get($field['name'])->value;
          if ($datetime_value) {
            $date = new \DateTime($datetime_value, new \DateTimeZone('UTC'));
            $date->setTimezone(new \DateTimeZone(date_default_timezone_get() ?? 'UTC'));
            $node_data[$field['name']] = $date->format('Y-m-d\TH:i:s');
          }
          else {
            $node_data[$field['name']] = "";
          }
        }
        else {
          $node_data[$field['name']] = $entity->get($field['name'])->value ?? "";
        }
      }
    }

    $paragraph_field = $this->getParagraphFieldName($entity->bundle());

    if ($paragraph_field && $entity->hasField($paragraph_field)) {
      $this->prepareVactoryParagraphsData($entity, $node_data, $paragraph_field);
    }
    return $node_data;
  }

  /**
   * Prepare Banner Data.
   *
   * @param \Drupal\node\NodeInterface $entity
   * @param $node_data
   *
   * @return void
   */
  private function prepareBannerData(NodeInterface $entity, &$node_data) {
    if ($entity->hasField('node_banner_image')) {
      $node_data['node_banner_image'] = $this->prepareMediaData($entity, 'node_banner_image', 'banner.node_banner_image');
    }
    if ($entity->hasField('node_banner_mobile_image')) {
      $node_data['node_banner_mobile_image'] = $this->prepareMediaData($entity, 'node_banner_mobile_image', 'banner.node_banner_mobile_image');
    }
    if ($entity->hasField('node_banner_title')) {
      $node_data['node_banner_title'] = $entity->get('node_banner_title')->value ?? "";
    }
    if ($entity->hasField('node_banner_description')) {
      $node_data['node_banner_description'] = $entity->get('node_banner_description')->value ?? "";
    }
    if ($entity->hasField('node_banner_showbreadcrumb')) {
      $node_data['node_banner_showbreadcrumb'] = $entity->get('node_banner_showbreadcrumb')->value ?? "";
    }
  }

  /**
   * Check tha banner availability.
   *
   * @param $bundle
   *
   * @return array
   */
  public function getBannerConfiguration($bundle) {
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle) ?? [];
    return [
      'enabled' => $this->moduleHandler->moduleExists('vactory_banner'),
      'node_banner_image' => isset($field_definitions['node_banner_image']),
      'node_banner_mobile_image' => isset($field_definitions['node_banner_mobile_image']),
      'node_banner_title' => isset($field_definitions['node_banner_title']),
      'node_banner_description' => isset($field_definitions['node_banner_description']),
      'node_banner_showbreadcrumb' => isset($field_definitions['node_banner_showbreadcrumb']),
    ];
  }

  /**
   * Prepare media data.
   */
  private function prepareMediaData($entity, $field_name, $path, $bundle = 'image') {
    $media_data = NULL;
    $media = $this->loadMediaFromEntityField($entity, $field_name);
    if (!$media instanceof MediaInterface) {
      return $media_data;
    }
    if ($bundle === 'image') {
      if ($media->hasField('field_media_image') && !$media->get('field_media_image')
          ->isEmpty()) {
        $file = $media->get('field_media_image')->entity;
        if ($file instanceof FileInterface) {
          $media_data = [
            'id' => $entity->get($field_name)->target_id,
            'url' => $file->createFileUrl(),
            'path' => $path,
            'key' => -1,
          ];
        }
      }
    }
    if ($bundle === 'remote_video') {
      if ($media->hasField('field_media_oembed_video') && !$media->get('field_media_oembed_video')
          ->isEmpty()) {
        $remote_video = $media->get('field_media_oembed_video')->value;
        if (!empty($remote_video)) {
          $media_data = [
            'id' => $entity->get($field_name)->target_id,
            'url' => $remote_video,
            'path' => $path,
            'key' => -1,
            'name' => $media->get('field_media_oembed_video')
              ->getEntity()
              ->label(),
          ];
        }
      }
    }

    if ($bundle == 'file' || $bundle == 'private_file') {
      $file_field_name = $bundle === 'file' ? 'field_media_file' : 'field_media_file_1';
      if ($media->hasField($file_field_name) && !$media->get($file_field_name)
          ->isEmpty()) {
        $file = $media->get($file_field_name)->entity;
        if ($file instanceof FileInterface) {
          $media_data = [
            'id' => $entity->get($field_name)->target_id,
            'url' => $file->createFileUrl(),
            'path' => $path,
            'key' => -1,
            'name' => $media->label(),
          ];
        }
      }
    }

    return $media_data;
  }

  /**
   * Load media from entity field.
   */
  private function loadMediaFromEntityField(EntityInterface $entity, string $fieldName): ?MediaInterface {
    $target_id = $entity->get($fieldName)->target_id ?? NULL;
    return $target_id ? $this->entityTypeManager->getStorage('media')
      ->load($target_id) : NULL;
  }

  /**
   * Process Vactory page data.
   */
  public function processVactoryPageData($node) {
    $node_data = [];

    // Get node fields.
    $node_data['title'] = $node->getTitle();
    $node_data['summary'] = $node->hasField('node_summary') ? $node->get('node_summary')->value ?? "" : "";
    $node_data['body'] = $node->hasField('node_summary') ? $node->get('node_summary')->value ?? "" : "";

    $this->prepareVactoryParagraphsData($node, $node_data);
    $this->prepareBannerData($node, $node_data);

    $alias = \Drupal::service('path_alias.manager')
      ->getAliasByPath('/node/' . $node->id());
    $node_data['alias'] = $alias;
    $node_data['status'] = $node->isPublished();
    return $node_data;
  }

  /**
   * Prepare vactory paragraphs data.
   */
  private function prepareVactoryParagraphsData($node, &$node_data, $paragraph_field = 'field_vactory_paragraphs') {
    $paragraphs = [];
    $lang = \Drupal::languageManager()->getCurrentLanguage()->getId();
    if ($node->hasField($paragraph_field)) {
      $paragraphsData = $node->get($paragraph_field)->getValue();
      foreach ($paragraphsData as $paragraphData) {
        $paragraph = Paragraph::load($paragraphData['target_id']);
        if ($paragraph->hasTranslation($lang)) {
          $paragraph = $paragraph->getTranslation($lang);
        }
        if (!$paragraph) {
          continue;
        }

        $hex = "";
        if ($paragraph->hasField('field_background_color') && !$paragraph->get('field_background_color')
            ->isEmpty()) {
          $colorItem = $paragraph->get('field_background_color')->first();
          $colorRaw = $colorItem->get('color')->getString();
          $hex = explode(',', $colorRaw)[0];
        }

        $image = "";
        $imageID = -1;
        if ($paragraph->hasField('paragraph_background_image') && !$paragraph->get('paragraph_background_image')
            ->isEmpty()) {
          $media = $paragraph->get('paragraph_background_image')->entity;
          if ($media?->hasField('field_media_image') && !$media->get('field_media_image')
              ->isEmpty()) {
            $file = $media->get('field_media_image')->entity;
            $imageID = $media->id();
            if ($file) {
              $image = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
            }
          }
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
              'spacing' => $paragraph->hasField('container_spacing') ? $paragraph->get('container_spacing')->value : "",
              'pid' => $paragraphData['target_id'],
              'revision_id' => $paragraph->getRevisionId(),
              'widget_id' => $widgetId,
              'widget_data' => $widgetData,
              'widget_config' => $widgetConfig,
              /* start configuration */
              'width' => $paragraph->hasField('paragraph_container') ? $paragraph->get('paragraph_container')->value : "",
              'css_classes' => $paragraph->hasField('paragraph_css_class') ? $paragraph->get('paragraph_css_class')->value : "",
              'color' => $hex,
              'bg_image' => $image,
              'imageID' => $imageID,
              'position_image_x' => $paragraph->hasField('field_position_image_x') ? $paragraph->get('field_position_image_x')->value : "",
              'position_image_y' => $paragraph->hasField('field_position_image_y') ? $paragraph->get('field_position_image_y')->value : "",
              'size_image' => $paragraph->hasField('field_size_image') ? $paragraph->get('field_size_image')->value : "",
              'hide_desktop' => $paragraph->hasField('field_paragraph_hide_lg') ? $paragraph->get('field_paragraph_hide_lg')->value : "",
              'hide_mobile' => $paragraph->hasField('field_paragraph_hide_sm') ? $paragraph->get('field_paragraph_hide_sm')->value : "",
              'enabel_parallax' => $paragraph->hasField('paragraph_background_parallax') ? $paragraph->get('paragraph_background_parallax')->value : "",
              /* end configuration */
            ];
          }
        }

        if ($paragraph->bundle() === 'vactory_paragraph_block') {
          $paragraphs[] = [
            'title' => $paragraph->hasField('field_vactory_title') ? $paragraph->get('field_vactory_title')->value : "",
            'bundle' => $paragraph->bundle(),
            'show_title' => $paragraph->hasField('field_vactory_flag') && $paragraph->get('field_vactory_flag')->value === "1",
            'spacing' => $paragraph->hasField('container_spacing') ? $paragraph->get('container_spacing')->value : "",
            'pid' => $paragraphData['target_id'],
            'revision_id' => $paragraph->getRevisionId(),
            'screenshot' => \Drupal::service('file_url_generator')
              ->generateAbsoluteString(\Drupal::service('extension.path.resolver')
                  ->getPath('module', 'vactory_dashboard') . '/assets/images/default-screenshot.png'),
            'body' => $paragraph->get('field_vactory_body')->value ?? "",
            'block_id' => $paragraph->get('field_vactory_block')->plugin_id ?? "",
            'block_settings' => $paragraph->get('field_vactory_block')->settings ?? [],
            /* start configuration */
            'width' => $paragraph->hasField('paragraph_container') ? $paragraph->get('paragraph_container')->value : "",
            'css_classes' => $paragraph->hasField('paragraph_css_class') ? $paragraph->get('paragraph_css_class')->value : "",
            'color' => $hex,
            'bg_image' => $image,
            'imageID' => $imageID,
            'position_image_x' => $paragraph->hasField('field_position_image_x') ? $paragraph->get('field_position_image_x')->value : "",
            'position_image_y' => $paragraph->hasField('field_position_image_y') ? $paragraph->get('field_position_image_y')->value : "",
            'size_image' => $paragraph->hasField('field_size_image') ? $paragraph->get('field_size_image')->value : "",
            'hide_desktop' => $paragraph->hasField('field_paragraph_hide_lg') ? $paragraph->get('field_paragraph_hide_lg')->value : "",
            'hide_mobile' => $paragraph->hasField('field_paragraph_hide_sm') ? $paragraph->get('field_paragraph_hide_sm')->value : "",
            'enabel_parallax' => $paragraph->hasField('paragraph_background_parallax') ? $paragraph->get('paragraph_background_parallax')->value : "",
            /* end configuration */
          ];
        }

        if (in_array($paragraph->bundle(), [
          'views_reference',
        ])) {
          $blockID = $paragraph->hasField('field_views_reference') ? $paragraph->get('field_views_reference')
            ->first()
            ?->getValue()['target_id'] : "";
          $paragraphs[] = [
            'id' => $node->id(),
            'block_id' => $blockID,
            'title' => $paragraph->hasField('field_vactory_title') ? $paragraph->get('field_vactory_title')->value : "",
            'display_id' => $paragraph->hasField('field_views_reference') ? $paragraph->get('field_views_reference')
              ->first()
              ?->getValue()['display_id'] : "",
            'displays' => $this->getViewDisplays($blockID),
            'bundle' => $paragraph->bundle(),
            /* start configuration */
            'width' => $paragraph->hasField('paragraph_container') ? $paragraph->get('paragraph_container')->value : "",
            'css_classes' => $paragraph->hasField('paragraph_css_class') ? $paragraph->get('paragraph_css_class')->value : "",
            'color' => $hex,
            'bg_image' => $image,
            'imageID' => $imageID,
            'position_image_x' => $paragraph->hasField('field_position_image_x') ? $paragraph->get('field_position_image_x')->value : "",
            'position_image_y' => $paragraph->hasField('field_position_image_y') ? $paragraph->get('field_position_image_y')->value : "",
            'size_image' => $paragraph->hasField('field_size_image') ? $paragraph->get('field_size_image')->value : "",
            'hide_desktop' => $paragraph->hasField('field_paragraph_hide_lg') ? $paragraph->get('field_paragraph_hide_lg')->value : "",
            'hide_mobile' => $paragraph->hasField('field_paragraph_hide_sm') ? $paragraph->get('field_paragraph_hide_sm')->value : "",
            'enabel_parallax' => $paragraph->hasField('paragraph_background_parallax') ? $paragraph->get('paragraph_background_parallax')->value : "",
            /* end configuration */
            'show_title' => $paragraph->hasField('field_vactory_flag') && $paragraph->get('field_vactory_flag')->value === "1",
            'spacing' => $paragraph->hasField('container_spacing') ? $paragraph->get('container_spacing')->value : "",
            'pid' => $paragraphData['target_id'],
            'revision_id' => $paragraph->getRevisionId(),
            'screenshot' => \Drupal::service('file_url_generator')
              ->generateAbsoluteString(\Drupal::service('extension.path.resolver')
                  ->getPath('module', 'vactory_dashboard') . '/assets/images/default-screenshot.png'),
          ];
        }

        if (in_array($paragraph->bundle(), [
          'vactory_paragraph_multi_template',
        ])) {
          $paragraphs[] = [
            'id' => $node->id(),
            'title' => $paragraph->hasField('field_vactory_title') ? $paragraph->get('field_vactory_title')->value : "",
            'show_title' => $paragraph->hasField('field_vactory_flag') && $paragraph->get('field_vactory_flag')->value === "1",
            'spacing' => $paragraph->hasField('container_spacing') ? $paragraph->get('container_spacing')->value : "",
            'display' => $paragraph->hasField('field_multi_paragraph_type') ? $paragraph->get('field_multi_paragraph_type')->value : "",
            'introduction' => $paragraph->hasField('field_paragraph_introduction') ? $paragraph->get('field_paragraph_introduction')->value : "",
            'items' => $this->getReferencedTabs($paragraph),
            'bundle' => $paragraph->bundle(),
            /* start configuration */
            'width' => $paragraph->hasField('paragraph_container') ? $paragraph->get('paragraph_container')->value : "",
            'css_classes' => $paragraph->hasField('paragraph_css_class') ? $paragraph->get('paragraph_css_class')->value : "",
            'color' => $hex,
            'bg_image' => $image,
            'imageID' => $imageID,
            'position_image_x' => $paragraph->hasField('field_position_image_x') ? $paragraph->get('field_position_image_x')->value : "",
            'position_image_y' => $paragraph->hasField('field_position_image_y') ? $paragraph->get('field_position_image_y')->value : "",
            'size_image' => $paragraph->hasField('field_size_image') ? $paragraph->get('field_size_image')->value : "",
            'hide_desktop' => $paragraph->hasField('field_paragraph_hide_lg') ? $paragraph->get('field_paragraph_hide_lg')->value : "",
            'hide_mobile' => $paragraph->hasField('field_paragraph_hide_sm') ? $paragraph->get('field_paragraph_hide_sm')->value : "",
            'enabel_parallax' => $paragraph->hasField('paragraph_background_parallax') ? $paragraph->get('paragraph_background_parallax')->value : "",
            /* end configuration */
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
   * Load referenced tab paragraphs from field_vactory_paragraph_tab.
   *
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The paragraph entity containing the reference field.
   *
   * @return array
   *   An array of referenced tab paragraphs data.
   */
  private function getReferencedTabs($paragraph) {
    $tabs = [];

    if ($paragraph->hasField('field_vactory_paragraph_tab') && !$paragraph->get('field_vactory_paragraph_tab')
        ->isEmpty()) {
      foreach ($paragraph->get('field_vactory_paragraph_tab')
        ->referencedEntities() as $tab_paragraph) {
        // Extract widgets from field_tab_templates
        $widgets = [];
        if ($tab_paragraph->hasField('field_tab_templates') && !$tab_paragraph->get('field_tab_templates')
            ->isEmpty()) {
          foreach ($tab_paragraph->get('field_tab_templates') as $widget_item) {
            $widgets[] = [
              'widget_id' => $widget_item->widget_id ?? NULL,
              'widget_config' => \Drupal::service('vactory_dynamic_field.vactory_provider_manager')
                ->loadSettings($widget_item->widget_id),
              'widget_data' => !empty($widget_item->widget_data) ? json_decode($widget_item->widget_data, TRUE) : NULL,
            ];
          }
        }

        $tabs[] = [
          'title' => $tab_paragraph->get('field_vactory_title')->value ?? NULL,
          'tab_id' => $tab_paragraph->get('paragraph_identifier')->value ?? NULL,
          'widgets' => $widgets,
          'id' => $tab_paragraph->id() ?? NULL,
        ];
      }
    }

    return $tabs;
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
    if (array_key_exists('extra_field', $widgetData ?? []) && $widgetData['extra_field']) {
      $this->handleExtraFieldsImageType($widgetData, $extraFieldsImageFields);
      $this->handleExtraFieldsRemoteVideoType($widgetData, $extraFieldsRemoteVideoFields);
      $this->handleExtraFieldsFileType($widgetData, $extraFieldsFileFields);
    }
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

    $skipped_fields = DashboardConstants::SKIPPED_FIELDS;
    $context = ['entity_type' => $type, 'bundle' => $bundle];
    $this->moduleHandler->alter('dashboard_form_skipped_fields', $skipped_fields, $context);

    foreach ($components as $field_name => $component) {
      // Skip technical/system fields.
      if (!isset($fields[$field_name]) || in_array($field_name, $skipped_fields)) {
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
          if ($field_settings['target_type'] === 'taxonomy_term' || $field_settings['target_type'] === 'user' || $field_settings['target_type'] === 'node') {
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
            $target_bundles = $field_settings['handler_settings']['target_bundles'] ?? [];
            $field_info['type'] = reset($target_bundles);
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

        case 'vactory_quiz_question':
          $field_info['type'] = 'vactory_quiz_question';
          $field_info['multiple'] = $cardinality == -1;
          break;

        case 'integer':
          $field_info['type'] = 'integer';
          $field_info['multiple'] = FALSE;
          $field_info['min'] = $field_settings['min'] ?? NULL;
          $field_info['max'] = $field_settings['max'] ?? NULL;
          $field_info['prefix'] = $field_settings['prefix'] ?? '';
          $field_info['suffix'] = $field_settings['suffix'] ?? '';
          break;

        case 'image':
          $field_info['type'] = 'image';
          $field_info['multiple'] = $cardinality == -1;
          $field_info['max_filesize'] = $field_settings['max_filesize'] ?? '';
          $field_info['max_resolution'] = $field_settings['max_resolution'] ?? '';
          $field_info['min_resolution'] = $field_settings['min_resolution'] ?? '';
          $field_info['file_extensions'] = $field_settings['file_extensions'] ?? 'png gif jpg jpeg';
          $field_info['alt_field'] = $field_settings['alt_field'] ?? TRUE;
          $field_info['alt_field_required'] = ($field_settings['alt_field_required'] ?? TRUE) && $field_required;
          $field_info['title_field'] = $field_settings['title_field'] ?? FALSE;
          $field_info['title_field_required'] = $field_settings['title_field_required'] ?? FALSE;
          break;

        case 'string':
          $field_info['type'] = 'string';
          $field_info['multiple'] = $cardinality == -1 || $cardinality > 1;
          $field_info['maxlength'] = $field_settings['max_length'] ?? 255;
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

      // Get the entity type definition
      $entity_type_definition = $this->entityTypeManager->getDefinition($target_type);
      $bundle_key = $entity_type_definition->getKey('bundle');

      if ($target_type === 'taxonomy_term') {
        // Taxonomy terms use 'vid' as the bundle key
        $query->condition('vid', $bundle_keys, 'IN');
      }
      elseif ($target_type === 'node') {
        // Nodes use 'type' as the bundle key
        $query->condition('type', $bundle_keys, 'IN');
      }
      elseif ($bundle_key) {
        // Other entity types with a bundle key
        $query->condition($bundle_key, $bundle_keys, 'IN');
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
   * Check if bundle has any paragraph field.
   */
  public function hasParagraphsField(array &$fields): bool {
    foreach ($fields as $field_name => $field) {
      if ($field['type'] === 'entity_reference_revisions' &&
        isset($field['settings']['target_type']) &&
        $field['settings']['target_type'] === 'paragraph') {
        return TRUE;
      }
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
    $paragraph_field = $this->getParagraphFieldName($node->bundle());
    if (!empty($blocks)) {
      $ordered_paragraphs = [];
      foreach ($blocks as $block) {
        $bundle = $block['bundle'] ?? "vactory_component";
        $handlers = [
          'vactory_component' => 'updateParagraphTemplatesInNode',
          'vactory_paragraph_block' => 'updateParagraphBlocksInNode',
          'views_reference' => 'updateParagraphViewsInNode',
          'vactory_paragraph_multi_template' => 'updateParagraphMultipleInNode',
        ];
        if (isset($handlers[$bundle])) {
          $this->{$handlers[$bundle]}($block, $language, $node_default_lang, $ordered_paragraphs);
        }
        else {
          $ordered_paragraphs[] = [
            'target_id' => $block['id'],
            'target_revision_id' => $block['revision_id'],
          ];
        }
      }
      if (!empty($ordered_paragraphs)) {
        $node->set($paragraph_field, $ordered_paragraphs);
      }
    }
    else {
      $node->set($paragraph_field, []);
    }
  }

  /**
   * Update paragraph templates in node.
   */
  private function updateParagraphTemplatesInNode($block, $language, $node_default_lang, &$ordered_paragraphs) {
    $paragraph = [
      "type" => "vactory_component",
      "field_vactory_title" => $block['title'],
      "field_vactory_flag" => $block['show_title'],
      "container_spacing" => $block['spacing'],
      "field_vactory_component" => [
        "widget_id" => $block['widget_id'],
        "widget_data" => json_encode($block['widget_data']),
      ],
      "paragraph_container" => $block['width'],
      "paragraph_css_class" => $block['css_classes'],
      "field_background_color" => !empty($block['color']) ? ['color' => $block['color']] : NULL,
      "paragraph_background_image" => !empty($block['image']) ? ['target_id' => $block["imageID"]] : NULL,
      "field_position_image_x" => $block['positionImageX'] ?? '',
      "field_position_image_y" => $block['positionImageY'] ?? '',
      "field_size_image" => $block['imageSize'] ?? '',
      "field_paragraph_hide_lg" => !empty($block['hideDesktop']) ? 1 : 0,
      "field_paragraph_hide_sm" => !empty($block['hideMobile']) ? 1 : 0,
      "paragraph_background_parallax" => !empty($block['enableParallax']) ? 1 : 0,
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
      $this->updateParagraphAppearanceSettings($paragraph_entity, $block, $language);
    }
    else {
      if ($is_new) {
        $paragraph['langcode'] = $language;
        $paragraph_entity = Paragraph::create($paragraph);
        $paragraph_entity->save();
      }
      else {
        $paragraph_entity = Paragraph::load($block['id']);
        $this->updateParagraphAppearanceSettings($paragraph_entity, $block, $language);
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
    $existing_block_id = $block['block_settings']['id'] ?? NULL;
    $paragraph = [
      "type" => "vactory_paragraph_block",
      "field_vactory_title" => $block['title'],
      "field_vactory_flag" => $block['show_title'],
      "container_spacing" => $block['spacing'],
      "field_vactory_block" => [
        "plugin_id" => $block['blockType'],
        "settings" => $block['blockType'] === $existing_block_id ? $block['block_settings'] ?? [] : [],
      ],
      "field_vactory_body" => [
        'value' => $block['content'] ?? '',
        'format' => 'full_html',
      ],
      "paragraph_container" => $block['width'],
      "paragraph_css_class" => $block['css_classes'],
      "field_background_color" => !empty($block['color']) ? ['color' => $block['color']] : NULL,
      "paragraph_background_image" => !empty($block['image']) ? ['target_id' => $block["imageID"]] : NULL,
      "field_position_image_x" => $block['positionImageX'] ?? '',
      "field_position_image_y" => $block['positionImageY'] ?? '',
      "field_size_image" => $block['imageSize'] ?? '',
      "field_paragraph_hide_lg" => !empty($block['hideDesktop']) ? 1 : 0,
      "field_paragraph_hide_sm" => !empty($block['hideMobile']) ? 1 : 0,
      "paragraph_background_parallax" => !empty($block['enableParallax']) ? 1 : 0,
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

      $this->updateParagraphAppearanceSettings($paragraph_entity, $block, $language, 'block');
    }
    else {
      if ($is_new) {
        $paragraph['langcode'] = $language;
        $paragraph_entity = Paragraph::create($paragraph);
        $paragraph_entity->save();
      }
      else {
        $paragraph_entity = Paragraph::load($block['id']);
        $this->updateParagraphAppearanceSettings($paragraph_entity, $block, $language, 'block');
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
    $existing_view_id = $block['block_settings']['id'] ?? NULL;
    $paragraph = [
      "type" => "views_reference",
      "field_vactory_title" => $block['title'],
      "container_spacing" => $block['spacing'],
      "field_views_reference" => [
        "target_id" => $block['blockType'],
        "display_id" => $block['displayID'],
        "settings" => $block['blockType'] === $existing_view_id ? $block['block_settings'] ?? [] : [],
      ],

      /* start configuration */
      "paragraph_container" => $block['width'],
      "paragraph_css_class" => $block['css_classes'],
      "field_background_color" => !empty($block['color']) ? ['color' => $block['color']] : NULL,
      "paragraph_background_image" => !empty($block['image']) ? ['target_id' => $block["imageID"]] : NULL,
      "field_position_image_x" => $block['positionImageX'] ?? '',
      "field_position_image_y" => $block['positionImageY'] ?? '',
      "field_size_image" => $block['imageSize'] ?? '',
      "field_paragraph_hide_lg" => !empty($block['hideDesktop']) ? 1 : 0,
      "field_paragraph_hide_sm" => !empty($block['hideMobile']) ? 1 : 0,
      "paragraph_background_parallax" => !empty($block['enableParallax']) ? 1 : 0,
      /* end configuration */
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
      $this->updateParagraphAppearanceSettings($paragraph_entity, $block, $language, 'view');
    }
    else {
      if ($is_new) {
        $paragraph['langcode'] = $language;
        $paragraph_entity = Paragraph::create($paragraph);
        $paragraph_entity->save();
      }
      else {
        $paragraph_entity = Paragraph::load($block['id']);
        $this->updateParagraphAppearanceSettings($paragraph_entity, $block, $language, 'view');
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
   * Updates or creates a "vactory_paragraph_multi_template" paragraph entity.
   *
   * This method manages both creation and updating of paragraphs that contain
   * multiple "tab" child paragraphs.
   *
   * @param array $block
   *   An associative array containing block data.
   * @param string $language
   *   The current language code.
   * @param string $node_default_lang
   *   The default language code of the node.
   * @param array &$ordered_paragraphs
   *   A reference to the array collecting ordered paragraph entity references
   *   (with target_id and target_revision_id) to later attach to the node.
   */
  private function updateParagraphMultipleInNode($block, $language, $node_default_lang, &$ordered_paragraphs) {
    $field_vactory_paragraph_tab = [];
    if (!empty($block['items']) && is_array($block['items'])) {
      foreach ($block['items'] as $item) {
        // If paragraph exists → update it
        if (!empty($item['id'])) {
          $paragraph_entity = $this->entityTypeManager
            ->getStorage('paragraph')
            ->load($item['id']);

          if ($paragraph_entity) {
            // Update fields if provided
            if (isset($item['title'])) {
              $paragraph_entity->set('field_vactory_title', $item['title']);
            }

            // Update tab_id if provided
            if (isset($item['tab_id'])) {
              $paragraph_entity->set('paragraph_identifier', $item['tab_id']);
            }

            // Save widgets if provided
            if (!empty($item['widgets']) && is_array($item['widgets'])) {
              $components = [];
              foreach ($item['widgets'] as $widget) {
                $components[] = [
                  'widget_id' => $widget['widget_id'] ?? '',
                  'widget_data' => json_encode($widget['widget_data'] ?? []),
                ];
              }
              $paragraph_entity->set('field_tab_templates', $components);
            }

            // Mark as new revision
            $paragraph_entity->setNewRevision(TRUE);
            $paragraph_entity->save();

            $field_vactory_paragraph_tab[] = [
              'target_id' => $paragraph_entity->id(),
              'target_revision_id' => $paragraph_entity->getRevisionId(),
            ];
          }
        }
        // If paragraph doesn't exist → create it
        else {
          $tab_paragraph = Paragraph::create([
            'type' => 'vactory_paragraph_tab',
            'field_vactory_title' => $item['title'] ?? '',
            'paragraph_identifier' => $item['tab_id'] ?? '',
          ]);

          // Save widgets if provided
          if (!empty($item['widgets']) && is_array($item['widgets'])) {
            $components = [];
            foreach ($item['widgets'] as $widget) {
              $components[] = [
                'widget_id' => $widget['widget_id'] ?? '',
                'widget_data' => json_encode($widget['widget_data'] ?? []),
              ];
            }
            $tab_paragraph->set('field_tab_templates', $components);
          }

          $tab_paragraph->save();

          $field_vactory_paragraph_tab[] = [
            'target_id' => $tab_paragraph->id(),
            'target_revision_id' => $tab_paragraph->getRevisionId(),
          ];
        }
      }
    }

    $paragraph = [
      "type" => "vactory_paragraph_multi_template",
      "field_vactory_title" => $block['title'],
      "field_vactory_flag" => $block['show_title'],
      "container_spacing" => $block['spacing'],
      "field_multi_paragraph_type" => $block['display'],
      "field_paragraph_introduction" => $block['introduction'],
      "field_vactory_paragraph_tab" => $field_vactory_paragraph_tab,
      "paragraph_container" => $block['width'],
      "paragraph_css_class" => $block['css_classes'],
      "field_background_color" => !empty($block['color']) ? ['color' => $block['color']] : NULL,
      "paragraph_background_image" => !empty($block['image']) ? ['target_id' => $block["imageID"]] : NULL,
      "field_position_image_x" => $block['positionImageX'] ?? '',
      "field_position_image_y" => $block['positionImageY'] ?? '',
      "field_size_image" => $block['imageSize'] ?? '',
      "field_paragraph_hide_lg" => !empty($block['hideDesktop']) ? 1 : 0,
      "field_paragraph_hide_sm" => !empty($block['hideMobile']) ? 1 : 0,
      "paragraph_background_parallax" => !empty($block['enableParallax']) ? 1 : 0,
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
      $this->updateParagraphAppearanceSettings($paragraph_entity, $block, $language, 'multiple', [
        'field_vactory_paragraph_tab' => $field_vactory_paragraph_tab,
      ]);
    }
    else {
      if ($is_new) {
        $paragraph['langcode'] = $language;
        $paragraph_entity = Paragraph::create($paragraph);
        $paragraph_entity->save();
      }
      else {
        $paragraph_entity = Paragraph::load($block['id']);
        $this->updateParagraphAppearanceSettings($paragraph_entity, $block, $language, 'multiple', [
          'field_vactory_paragraph_tab' => $field_vactory_paragraph_tab,
        ]);
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
    $paragraph_field = $this->getParagraphFieldName($node->bundle());
    $ordered_paragraphs = [];
    if (!empty($blocks)) {
      foreach ($blocks as $block) {
        $bundle = $block['bundle'] ?? "vactory_component";
        $paragraph = [
          "type" => $bundle,
          "field_vactory_title" => $block['title'],
          "field_vactory_flag" => $block['show_title'],
          "container_spacing" => $block['spacing'],

          /* start configuration */
          "paragraph_container" => $block['width'],
          "paragraph_css_class" => $block['css_classes'],
          "field_background_color" => !empty($block['color']) ? ['color' => $block['color']] : NULL,
          "paragraph_background_image" => !empty($block['image']) ? ['target_id' => $block["imageID"]] : NULL,
          "field_position_image_x" => $block['positionImageX'] ?? '',
          "field_position_image_y" => $block['positionImageY'] ?? '',
          "field_size_image" => $block['imageSize'] ?? '',
          "field_paragraph_hide_lg" => !empty($block['hideDesktop']) ? 1 : 0,
          "field_paragraph_hide_sm" => !empty($block['hideMobile']) ? 1 : 0,
          "paragraph_background_parallax" => !empty($block['enableParallax']) ? 1 : 0,
          /* end configuration */
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
          else {
            if ($bundle === 'views_reference') {
              $paragraph['field_views_reference'] = [
                "target_id" => $block['blockType'],
                "display_id" => $block['displayID'],
              ];
            }
            else {
              if ($bundle === 'vactory_paragraph_multi_template') {
                $field_vactory_paragraph_tab = [];

                if (!empty($block['items']) && is_array($block['items'])) {
                  foreach ($block['items'] as $item) {
                    // If paragraph exists → update it
                    if (!empty($item['id'])) {
                      $paragraph_entity = $this->entityTypeManager
                        ->getStorage('paragraph')
                        ->load($item['id']);

                      if ($paragraph_entity) {
                        // Update fields if provided
                        if (isset($item['title'])) {
                          $paragraph_entity->set('field_vactory_title', $item['title']);
                        }

                        // Update tab_id if provided
                        if (isset($item['tab_id'])) {
                          $paragraph_entity->set('paragraph_identifier', $item['tab_id']);
                        }

                        // Save widgets if provided
                        if (!empty($item['widgets']) && is_array($item['widgets'])) {
                          $components = [];
                          foreach ($item['widgets'] as $widget) {
                            $components[] = [
                              'widget_id' => $widget['widget_id'] ?? '',
                              'widget_data' => json_encode($widget['widget_data'] ?? []),
                            ];
                          }
                          $paragraph_entity->set('field_tab_templates', $components);
                        }

                        // Mark as new revision
                        $paragraph_entity->setNewRevision(TRUE);
                        $paragraph_entity->save();

                        $field_vactory_paragraph_tab[] = [
                          'target_id' => $paragraph_entity->id(),
                          'target_revision_id' => $paragraph_entity->getRevisionId(),
                        ];
                      }
                    }
                    // If paragraph doesn't exist → create it
                    else {
                      $tab_paragraph = Paragraph::create([
                        'type' => 'vactory_paragraph_tab',
                        'field_vactory_title' => $item['title'] ?? '',
                        'paragraph_identifier' => $item['tab_id'] ?? '',
                      ]);

                      // Save widgets if provided
                      if (!empty($item['widgets']) && is_array($item['widgets'])) {
                        $components = [];
                        foreach ($item['widgets'] as $widget) {
                          $components[] = [
                            'widget_id' => $widget['widget_id'] ?? '',
                            'widget_data' => json_encode($widget['widget_data'] ?? []),
                          ];
                        }
                        $tab_paragraph->set('field_tab_templates', $components);
                      }

                      $tab_paragraph->save();

                      $field_vactory_paragraph_tab[] = [
                        'target_id' => $tab_paragraph->id(),
                        'target_revision_id' => $tab_paragraph->getRevisionId(),
                      ];
                    }
                  }
                }

                $paragraph['field_multi_paragraph_type'] = $block['display'];
                $paragraph['field_paragraph_introduction'] = $block['introduction'];
                $paragraph['field_vactory_paragraph_tab'] = $field_vactory_paragraph_tab;
              }
            }
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
      $node->set($paragraph_field, $ordered_paragraphs);
    }
  }

  /**
   * Get paragraph blocks list.
   */
  public function getParagraphBlocksList() {
    $field_vactory_block = $this->entityFieldManager->getFieldDefinitions('paragraph', 'vactory_paragraph_block');
    $field_vactory_block = $field_vactory_block['field_vactory_block'] ?? [];
    $paragraph_blocks = [];

    $blocks = $field_vactory_block->getSettings()['selection_settings']['plugin_ids'] ?? [];

    if (empty($blocks)) {
      // Load all blocks.
      $plugin_definitions = \Drupal::service('block_field.manager')
        ->getBlockDefinitions();
      $custom_block_plugins = [];

      foreach ($plugin_definitions as $plugin_id => $definition) {
        $custom_block_plugins[] = [
          'id' => $plugin_id,
          'label' => $definition['admin_label'] . ' (' . $plugin_id . ')',
        ];
      }
      return $custom_block_plugins;
    }

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
   *   (Unused) The machine name of a Paragraph type (kept for potential future
   *   use).
   * @param string $bundle
   *   (Optional) The machine name of the content type (node bundle).
   *   Defaults to 'vactory_page'.
   *
   * @return array
   *   An associative array indicating whether each of the target paragraph
   *   types is enabled, or FALSE if the field or configuration is not found.
   */
  public function isParagraphTypeEnabled($bundle = "vactory_page"): array {
    $paragraph_field = $this->getParagraphFieldName($bundle);
    $field_config = FieldConfig::loadByName('node', $bundle, $paragraph_field);
    if (!$field_config) {
      return [];
    }

    $settings = $field_config->getSettings();
    $target_bundles = $settings['handler_settings']['target_bundles'] ?? NULL;

    $paragraph_types = [
      '#isParagraphViewEnabled' => 'views_reference',
      '#isParagraphBlockEnabled' => 'vactory_paragraph_block',
      '#isParagraphTemplateEnabled' => 'vactory_component',
      '#isParagraphMultipleEnabled' => 'vactory_paragraph_multi_template',
    ];

    $types = [];

    foreach ($paragraph_types as $key => $bundle_name) {
      if ($target_bundles !== NULL) {
        // Check if bundle exists in target_bundles.
        $types[$key] = array_key_exists($bundle_name, $target_bundles);
      }
      else {
        // Check if paragraph type exists.
        $paragraph_type = ParagraphsType::load($bundle_name);
        if ($paragraph_type instanceof ParagraphsTypeInterface) {
          $types[$key] = TRUE;
        }
      }
    }

    return $types;
  }

  /**
   * Update paragraph template appearance settings.
   *
   * @todo: move to paragraph service.
   */
  private function updateParagraphAppearanceSettings($paragraph_entity, $block, $language, $paragraph_type = 'template', $extra_data = []) {
    switch ($paragraph_type) {
      case 'template':
        $paragraph_entity->getTranslation($language)
          ->set('field_vactory_component', [
            "widget_id" => $block['widget_id'],
            "widget_data" => json_encode($block['widget_data']),
          ]);
        break;
      case 'block':
        $existing_block_id = $block['block_settings']['id'] ?? NULL;
        $paragraph_entity->getTranslation($language)
          ->set('field_vactory_block', [
            "plugin_id" => $block['blockType'],
            "settings" => $block['blockType'] === $existing_block_id ? $block['block_settings'] ?? [] : [],
          ]);
        if ($paragraph_entity->hasField('field_vactory_body')) {
          $paragraph_entity->getTranslation($language)
            ->set('field_vactory_body', [
              'value' => $block['content'] ?? '',
              'format' => 'full_html',
            ]);
        }
        break;
      case 'view':
        $existing_view_id = $block['block_settings']['id'] ?? NULL;
        $paragraph_entity->getTranslation($language)
          ->set('field_views_reference', [
            "target_id" => $block['blockType'],
            "display_id" => $block['displayID'],
            "settings" => $block['blockType'] === $existing_view_id ? $block['block_settings'] ?? [] : [],
          ]);
        break;
      case 'multiple':
        $paragraph_entity->getTranslation($language)
          ->set('field_vactory_paragraph_tab', $extra_data['field_vactory_paragraph_tab']);
        if ($paragraph_entity->hasField('field_paragraph_introduction') && isset($block['introduction'])) {
          $paragraph_entity->getTranslation($language)
            ->set('field_paragraph_introduction', $block['introduction']);
        }
        if ($paragraph_entity->hasField('field_multi_paragraph_type') && isset($block['display'])) {
          $paragraph_entity->getTranslation($language)
            ->set('field_multi_paragraph_type', $block['display']);
        }
        break;
    }

    if ($paragraph_entity->hasField('field_vactory_title') && isset($block['title'])) {
      $paragraph_entity->getTranslation($language)
        ->set('field_vactory_title', $block['title']);
    }

    if ($paragraph_entity->hasField('field_vactory_flag')) {
      $paragraph_entity->getTranslation($language)
        ->set('field_vactory_flag', $block['show_title']);
    }

    if ($paragraph_entity->hasField('paragraph_container') && isset($block['width'])) {
      $paragraph_entity->getTranslation($language)
        ->set('paragraph_container', $block['width']);
    }

    if ($paragraph_entity->hasField('spacing') && isset($block['spacing'])) {
      $paragraph_entity->getTranslation($language)
        ->set('container_spacing', $block['spacing']);
    }

    if ($paragraph_entity->hasField('css_classes') && isset($block['css_classes'])) {
      $paragraph_entity->getTranslation($language)
        ->set('paragraph_css_class', $block['css_classes']);
    }
    foreach (DashboardConstants::PARAGARAPH_APPARENCE_FIELDS as $block_key => $field_name) {
      if (!$paragraph_entity->hasField($field_name)) {
        continue;
      }
      if (!isset($block[$block_key])) {
        continue;
      }
      if ($block_key === 'color') {
        $paragraph_entity->getTranslation($language)
          ->set($field_name, ['color' => $block[$block_key]]);
        continue;
      }
      if ($block_key === 'image') {
        $image_id = $block["imageID"] ?? NULL;
        if (isset($image_id) && $image_id !== -1) {
          $paragraph_entity->getTranslation($language)
            ->set($field_name, ['target_id' => $image_id]);
        }
        else {
          $paragraph_entity->getTranslation($language)
            ->set($field_name, []);
        }
        continue;
      }
      $paragraph_entity->getTranslation($language)
        ->set($field_name, $block[$block_key]);
    }
    $paragraph_entity->save();
  }

  /**
   * Save banner in given node.
   */
  public function saveBannerInNode(NodeInterface $node, $banner = []) {
    if ($node->hasField('node_banner_image')) {
      $image_id = $banner['node_banner_image']['id'] ?? NULL;
      if ($image_id !== NULL) {
        $node->set('node_banner_image', $image_id);
      }
    }
    if ($node->hasField('node_banner_mobile_image')) {
      $mobile_image_id = $banner['node_banner_mobile_image']['id'] ?? NULL;
      if ($mobile_image_id !== NULL) {
        $node->set('node_banner_mobile_image', $mobile_image_id);
      }
    }
    if ($node->hasField('node_banner_title')) {
      $node->set('node_banner_title', $banner['node_banner_title'] ?? '');
    }
    if ($node->hasField('node_banner_description')) {
      $node->set('node_banner_description', $banner['node_banner_description'] ?? '');
    }
    if ($node->hasField('node_banner_showbreadcrumb')) {
      $node->set('node_banner_showbreadcrumb', $banner['node_banner_showbreadcrumb'] ?? FALSE);
    }
  }

}
