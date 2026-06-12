<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\block_content\BlockContentInterface;
use Drupal\block_content\BlockContentTypeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;
use Drupal\vactory_dashboard\Form\InlineDynamicBlockForm;
use Drupal\vactory_dashboard\Service\NodeService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the dashboard blocks.
 */
class DashboardBlockController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The node service.
   *
   * @var \Drupal\vactory_dashboard\Service\NodeService
   */
  protected $nodeService;

  /**
   * Constructs a DashboardBlockController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\vactory_dashboard\Service\NodeService $node_service
   *   The dashboard node service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, NodeService $node_service) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->nodeService = $node_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('vactory_dashboard.node_service')
    );
  }

  /**
   * Renders the dashboard page.
   *
   * @return array
   *   A render array.
   */
  public function banner() {
    return [
      '#theme' => 'vactory_dashboard_banner_blocks',
      '#attached' => [
        'library' => [
          'vactory_dashboard/banner-blocks',
        ],
      ],
    ];
  }

  /**
   * Renders the content blocks management page.
   *
   * @return array
   *   A render array.
   */
  public function contentBlocks() {
    $types = [];
    $storage = $this->entityTypeManager->getStorage('block_content_type');
    $block_storage = $this->entityTypeManager->getStorage('block_content');
    foreach ($storage->loadMultiple() as $type) {
      $access = $this->entityTypeManager
        ->getAccessControlHandler('block_content')
        ->createAccess($type->id(), $this->currentUser(), [], TRUE);
      $prototype = $block_storage->create(['type' => $type->id()]);
      $types[$type->id()] = [
        'id' => $type->id(),
        'label' => $type->label(),
        'description' => Html::decodeEntities(strip_tags((string) $type->getDescription())),
        'can_create' => $access->isAllowed(),
        'is_dynamic' => $prototype->hasField('field_dynamic_block_components'),
        'add_url' => Url::fromRoute('vactory_dashboard.block_content.add', [
          'block_content_type' => $type->id(),
        ])->toString(),
      ];
    }

    uasort($types, static function (array $a, array $b) {
      return strcasecmp($a['label'], $b['label']);
    });

    $creatable_types = array_values(array_filter($types, static function (array $type) {
      return !empty($type['can_create']);
    }));

    return [
      '#theme' => 'vactory_dashboard_content_blocks',
      '#block_types' => $types,
      '#creatable_block_types' => $creatable_types,
    ];
  }

  /**
   * Returns paginated content block data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The content blocks response.
   */
  public function getContentBlocks(Request $request) {
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = max(1, min(100, (int) $request->query->get('limit', 10)));
    $search = trim((string) $request->query->get('search', ''));
    $type = (string) $request->query->get('type', '');

    $storage = $this->entityTypeManager->getStorage('block_content');
    $query = $storage->getQuery()->accessCheck(TRUE);
    $count_query = $storage->getQuery()->accessCheck(TRUE);

    if ($search !== '') {
      $query->condition('info', $search, 'CONTAINS');
      $count_query->condition('info', $search, 'CONTAINS');
    }
    if ($type !== '') {
      $query->condition('type', $type);
      $count_query->condition('type', $type);
    }

    $total = (int) $count_query->count()->execute();
    $query
      ->sort('changed', 'DESC')
      ->sort('id', 'DESC')
      ->range(($page - 1) * $limit, $limit);

    $ids = $query->execute();
    $entities = $storage->loadMultiple($ids);
    $type_storage = $this->entityTypeManager->getStorage('block_content_type');
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();
    $date_formatter = \Drupal::service('date.formatter');

    $data = [];
    foreach ($entities as $block) {
      if ($block->hasTranslation($current_language)) {
        $block = $block->getTranslation($current_language);
      }

      $block_type = $type_storage->load($block->bundle());
      $data[] = [
        'id' => (int) $block->id(),
        'label' => $block->label(),
        'type' => $block->bundle(),
        'type_label' => $block_type ? $block_type->label() : $block->bundle(),
        'language' => $block->language()->getName(),
        'changed' => $date_formatter->format(
          (int) $block->getChangedTime(),
          'custom',
          'd/m/Y - H:i'
        ),
        'edit_url' => $block->access('update', $this->currentUser())
        ? Url::fromRoute('vactory_dashboard.block_content.edit', [
          'block_content' => $block->id(),
        ])->toString()
        : '',
        'can_delete' => $block->access('delete', $this->currentUser()),
      ];
    }

    return new JsonResponse([
      'data' => $data,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => max(1, (int) ceil($total / $limit)),
    ]);
  }

  /**
   * Builds language switcher variables for block add/edit pages.
   *
   * @param string $route_name
   *   The route name.
   * @param array $route_parameters
   *   The route parameters.
   * @param string $default_language
   *   The entity/default language ID.
   * @param \Drupal\block_content\BlockContentInterface|null $block_content
   *   The content block for edit pages.
   *
   * @return array
   *   Render variables for the shared dashboard language component.
   */
  protected function buildLanguageContext($route_name, array $route_parameters, $default_language, ?BlockContentInterface $block_content = NULL) {
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();
    $config = \Drupal::config('vactory_dashboard.global.settings');
    $enabled_languages = array_filter($config->get('dashboard_languages') ?? []);
    $languages_display_format = $config->get('display_format');
    $existing_translations = $block_content ? $block_content->getTranslationLanguages() : [];
    $available_languages = [];

    foreach (\Drupal::languageManager()->getLanguages() as $language) {
      $lang_id = $language->getId();
      $has_existing_translation = array_key_exists($lang_id, $existing_translations);
      $is_enabled = empty($enabled_languages) || isset($enabled_languages[$lang_id]);

      if ($is_enabled || $has_existing_translation) {
        $available_languages[] = [
          'id' => $lang_id,
          'url' => Url::fromRoute($route_name, $route_parameters, ['language' => $language])->toString(),
          'has_translation' => $block_content ? $has_existing_translation : TRUE,
        ];
      }
    }

    return [
      '#language' => $current_language,
      '#node_default_lang' => $default_language,
      '#available_languages' => $available_languages,
      '#languages_display_format' => $languages_display_format,
    ];
  }

  /**
   * Renders the content block add form in the dashboard.
   *
   * @param \Drupal\block_content\BlockContentTypeInterface $block_content_type
   *   The block content type.
   *
   * @return array
   *   A render array.
   */
  public function addContentBlock(BlockContentTypeInterface $block_content_type) {
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => $block_content_type->id(),
    ]);
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();
    $language_context = $this->buildLanguageContext(
      'vactory_dashboard.block_content.add',
      ['block_content_type' => $block_content_type->id()],
      $current_language
    );
    $page_title = $this->t('Add @type block', [
      '@type' => $block_content_type->label(),
    ]);

    if ($block->hasField('field_dynamic_block_components')) {
      return array_merge([
        '#theme' => 'vactory_dashboard_content_block_form',
        '#mode' => 'dynamic',
        '#operation' => 'add',
        '#page_title' => $page_title,
        '#block_type_id' => $block_content_type->id(),
        '#block_type_label' => $block_content_type->label(),
        '#block_id' => NULL,
        '#block_label' => '',
        '#widget_id' => '',
        '#widget_data' => [],
      ], $language_context);
    }

    $fields = $this->nodeService->getBundleFields($block_content_type->id(), 0, 'block_content');

    return array_merge([
      '#theme' => 'vactory_dashboard_content_block_form',
      '#mode' => 'standard',
      '#operation' => 'add',
      '#page_title' => $page_title,
      '#block_type_id' => $block_content_type->id(),
      '#block_type_label' => $block_content_type->label(),
      '#block_id' => NULL,
      '#fields' => $fields,
      '#field_groups' => $this->nodeService->buildFieldLayout($fields, $block_content_type->id(), 'block_content'),
      '#block' => ['fields' => []],
    ], $language_context);
  }

  /**
   * Renders the content block edit form in the dashboard.
   *
   * @param \Drupal\block_content\BlockContentInterface $block_content
   *   The block content entity.
   *
   * @return array
   *   A render array.
   */
  public function editContentBlock(BlockContentInterface $block_content) {
    $block = \Drupal::service('entity.repository')
      ->getTranslationFromContext($block_content);
    $type = $this->entityTypeManager->getStorage('block_content_type')
      ->load($block->bundle());
    $current_language = \Drupal::languageManager()
      ->getCurrentLanguage()
      ->getId();
    $language_context = $this->buildLanguageContext(
      'vactory_dashboard.block_content.edit',
      ['block_content' => $block_content->id()],
      $block_content->language()->getId(),
      $block_content
    );
    $page_title = $this->t('Edit @label - @type', [
      '@label' => $block->label(),
      '@type' => $type ? $type->label() : $block->bundle(),
    ]);

    if ($block->hasField('field_dynamic_block_components')) {
      $field_value = $block->get('field_dynamic_block_components')->first();
      $widget_id = $field_value ? $field_value->widget_id : '';
      $widget_data = $field_value ? $field_value->widget_data : '';
      $widget_data = $widget_data && is_string($widget_data) ? json_decode($widget_data, TRUE) : [];
      if (is_array($widget_data)) {
        $this->hydrateDynamicBlockMediaData($widget_data);
      }

      return array_merge([
        '#theme' => 'vactory_dashboard_content_block_form',
        '#mode' => 'dynamic',
        '#page_title' => $page_title,
        '#block_type_label' => $type ? $type->label() : $block->bundle(),
        '#block_id' => $block->id(),
        '#block_label' => $block->label(),
        '#widget_id' => $widget_id,
        '#widget_data' => is_array($widget_data) ? $widget_data : [],
        '#operation' => 'edit',
        '#block_type_id' => $block->bundle(),
        '#has_translation' => $block_content->hasTranslation($current_language),
      ], $language_context);
    }

    $fields = $this->nodeService->getBundleFields($block->bundle(), 0, 'block_content');
    $this->excludeSelfFromBlockFieldOptions($fields, $block);

    return array_merge([
      '#theme' => 'vactory_dashboard_content_block_form',
      '#mode' => 'standard',
      '#operation' => 'edit',
      '#page_title' => $page_title,
      '#block_type_label' => $type ? $type->label() : $block->bundle(),
      '#block_type_id' => $block->bundle(),
      '#block_id' => $block->id(),
      '#fields' => $fields,
      '#field_groups' => $this->nodeService->buildFieldLayout($fields, $block->bundle(), 'block_content'),
      '#block' => $this->processBlockContent($block, $fields),
      '#has_translation' => $block_content->hasTranslation($current_language),
    ], $language_context);
  }

  /**
   * Builds a content block form for the dashboard.
   *
   * @param \Drupal\block_content\BlockContentInterface $block
   *   The content block entity.
   * @param string $operation
   *   The entity form operation.
   * @param string $page_title
   *   The dashboard page title.
   * @param string $block_type_label
   *   The content block type label.
   *
   * @return array
   *   The prepared content block form.
   */
  protected function buildContentBlockForm(BlockContentInterface $block, $operation, $page_title, $block_type_label) {
    if ($block->hasField('field_dynamic_block_components')) {
      return \Drupal::formBuilder()->getForm(
        InlineDynamicBlockForm::class,
        $block
      );
    }

    $form = $this->entityFormBuilder()->getForm($block, $operation);
    unset($form['actions']['configure_block']);
    $form['actions']['submit']['#submit'][] = 'vactory_dashboard_block_content_redirect_submit';
    $form['actions']['submit']['#value'] = $this->t('Save');
    $form['actions']['submit']['#attributes']['class'] = [
      'inline-flex',
      'items-center',
      'justify-center',
      'rounded-lg',
      'bg-primary-500',
      'px-5',
      'py-2',
      'text-sm',
      'font-semibold',
      'text-white',
      'shadow-sm',
      'transition-colors',
      'hover:bg-primary-600',
    ];
    $form['actions']['#attributes']['class'] = [
      'flex',
      'items-center',
      'gap-2',
      'm-0',
    ];

    $actions = $form['actions'];
    unset($form['actions']);

    $form['#attributes']['class'][] = 'vactory-dashboard-block-form';
    $form['#attributes']['class'][] = 'space-y-6';
    $form['#attributes']['x-data'] = "{ activeTab: 'content' }";
    $form['#attached']['library'][] = 'vactory_dashboard/content-block-form';

    $form['dashboard_header'] = [
      '#type' => 'container',
      '#weight' => -1000,
      '#attributes' => [
        'class' => [
          'sticky',
          'top-0',
          'z-30',
          'mb-2',
          'flex',
          'flex-col',
          'items-center',
          'justify-between',
          'gap-4',
          'rounded-xl',
          'border-b',
          'border-slate-200',
          'bg-white',
          'px-4',
          'py-4',
          'shadow-sm',
          'md:flex-row',
        ],
      ],
      'heading' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['flex', 'items-center', 'gap-3'],
        ],
        'back' => [
          '#type' => 'link',
          '#title' => [
            '#markup' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"></path></svg>',
          ],
          '#url' => Url::fromRoute('vactory_dashboard.block_content'),
          '#attributes' => [
            'class' => [
              'text-slate-500',
              'transition-colors',
              'hover:text-primary-500',
            ],
            'aria-label' => $this->t('Back to content blocks'),
          ],
        ],
        'title' => [
          '#markup' => '<div><h1 class="text-2xl font-semibold text-slate-900">'
          . Html::escape($page_title)
          . '</h1><p class="mt-1 text-sm text-slate-500">'
          . Html::escape($block_type_label)
          . '</p></div>',
        ],
      ],
      'actions' => $actions,
    ];

    $form['dashboard_tabs'] = [
      '#type' => 'container',
      '#weight' => -999,
      '#attributes' => [
        'class' => [
          'mb-6',
          'flex',
          'items-center',
          'border-b',
          'border-slate-200',
          'bg-white',
        ],
      ],
      'content' => [
        '#type' => 'button',
        '#value' => $this->t('Content'),
        '#attributes' => [
          'type' => 'button',
          '@click' => "activeTab = 'content'",
          ':class' => "{ 'border-primary-500 text-primary-600 bg-primary-50/50': activeTab === 'content', 'border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-50': activeTab !== 'content' }",
          'class' => [
            'rounded-none',
            'border-0',
            'border-b-2',
            'bg-transparent',
            'px-4',
            'py-3',
            'text-sm',
            'font-medium',
            'shadow-none',
          ],
        ],
      ],
      'settings' => [
        '#type' => 'button',
        '#value' => $this->t('Settings'),
        '#attributes' => [
          'type' => 'button',
          '@click' => "activeTab = 'settings'",
          ':class' => "{ 'border-primary-500 text-primary-600 bg-primary-50/50': activeTab === 'settings', 'border-transparent text-slate-500 hover:text-slate-700 hover:bg-slate-50': activeTab !== 'settings' }",
          'class' => [
            'rounded-none',
            'border-0',
            'border-b-2',
            'bg-transparent',
            'px-4',
            'py-3',
            'text-sm',
            'font-medium',
            'shadow-none',
          ],
        ],
      ],
    ];

    $settings_fields = [
      'advanced',
      'block_machine_name',
      'info',
      'langcode',
      'revision',
      'revision_information',
      'revision_log',
      'translation',
    ];

    $content_panel = [
      '#type' => 'container',
      '#weight' => 0,
      '#attributes' => [
        'class' => ['vactory-dashboard-block-form__panel', 'space-y-6'],
        'x-show' => "activeTab === 'content'",
      ],
    ];
    $settings_panel = [
      '#type' => 'container',
      '#weight' => 1,
      '#attributes' => [
        'class' => ['vactory-dashboard-block-form__panel', 'space-y-6'],
        'x-show' => "activeTab === 'settings'",
      ],
    ];

    $form_keys = array_keys($form);
    foreach ($form_keys as $key) {
      if (!is_array($form[$key]) || strpos((string) $key, '#') === 0) {
        continue;
      }
      if (in_array($key, ['dashboard_header', 'dashboard_tabs', 'footer'], TRUE)) {
        continue;
      }

      $element = $form[$key];
      unset($form[$key]);

      if (!isset($element['#attributes']['class'])) {
        $element['#attributes']['class'] = [];
      }
      if (!is_array($element['#attributes']['class'])) {
        $element['#attributes']['class'] = [$element['#attributes']['class']];
      }
      $element['#attributes']['class'][] = 'rounded-xl';
      $element['#attributes']['class'][] = 'bg-white';
      $element['#attributes']['class'][] = 'p-6';
      $element['#attributes']['class'][] = 'shadow-sm';
      $element['#attributes']['class'][] = 'ring-1';
      $element['#attributes']['class'][] = 'ring-slate-200';

      if (in_array($key, $settings_fields, TRUE)) {
        $settings_panel[$key] = $element;
      }
      else {
        $content_panel[$key] = $element;
      }
    }

    $form['dashboard_content_panel'] = $content_panel;
    $form['dashboard_settings_panel'] = $settings_panel;

    return $form;
  }

  /**
   * Saves a dynamic content block edited with the dashboard block builder.
   *
   * @param \Drupal\block_content\BlockContentInterface $block_content
   *   The content block entity.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The save response.
   */
  public function saveDynamicContentBlock(BlockContentInterface $block_content, Request $request) {
    if (!$block_content->hasField('field_dynamic_block_components')) {
      return new JsonResponse([
        'message' => $this->t('This block does not support dynamic components.'),
      ], 400);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse([
        'message' => $this->t('Invalid request data.'),
      ], 400);
    }

    $widget_id = $data['widget_id'] ?? '';
    $widget_data = $data['widget_data'] ?? [];
    if (empty($widget_id) || !is_array($widget_data)) {
      return new JsonResponse([
        'message' => $this->t('Invalid block template data.'),
      ], 400);
    }

    $language = $data['language'] ?? \Drupal::languageManager()
      ->getDefaultLanguage()
      ->getId();
    $has_translation = $data['has_translation'] ?? TRUE;
    $has_translation = $has_translation !== '' ? $has_translation : FALSE;

    if (!$has_translation && !$block_content->hasTranslation($language)) {
      $block_content->addTranslation($language);
    }
    $target_block = $block_content->hasTranslation($language)
      ? $block_content->getTranslation($language)
      : $block_content;

    $target_block->set('info', $data['label'] ?? $target_block->label());
    $target_block->set('field_dynamic_block_components', [
      'widget_id' => $widget_id,
      'widget_data' => json_encode($widget_data),
    ]);
    $target_block->save();

    return new JsonResponse([
      'message' => $this->t('Content block saved successfully.'),
      'block_id' => $block_content->id(),
    ]);
  }

  /**
   * Creates a dynamic content block edited with the dashboard block builder.
   *
   * @param \Drupal\block_content\BlockContentTypeInterface $block_content_type
   *   The content block type.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The save response.
   */
  public function createDynamicContentBlock(BlockContentTypeInterface $block_content_type, Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse([
        'message' => $this->t('Invalid request data.'),
      ], 400);
    }

    $widget_id = $data['widget_id'] ?? '';
    $widget_data = $data['widget_data'] ?? [];
    if (empty($widget_id) || !is_array($widget_data)) {
      return new JsonResponse([
        'message' => $this->t('Invalid block template data.'),
      ], 400);
    }

    $block_content = $this->entityTypeManager->getStorage('block_content')
      ->create([
        'type' => $block_content_type->id(),
        'langcode' => $data['language'] ?? \Drupal::languageManager()
          ->getDefaultLanguage()
          ->getId(),
        'info' => $data['label'] ?? $block_content_type->label(),
        'field_dynamic_block_components' => [
          'widget_id' => $widget_id,
          'widget_data' => json_encode($widget_data),
        ],
      ]);
    $block_content->save();

    return new JsonResponse([
      'message' => $this->t('Content block created successfully.'),
      'block_id' => $block_content->id(),
      'list' => Url::fromRoute('vactory_dashboard.block_content')->toString(),
    ]);
  }

  /**
   * Adds media preview data to saved dynamic block widget values.
   *
   * Older widget_data can store only media target IDs. The dashboard block
   * editor needs URLs and names to show existing image/video selections.
   *
   * @param array $data
   *   Widget data, modified in-place.
   */
  protected function hydrateDynamicBlockMediaData(array &$data) {
    foreach ($data as &$value) {
      if (!is_array($value)) {
        continue;
      }

      if (isset($value['selection']) && is_array($value['selection'])) {
        foreach ($value['selection'] as &$selection) {
          if (!is_array($selection) || empty($selection['target_id'])) {
            continue;
          }

          $media = $this->entityTypeManager->getStorage('media')
            ->load($selection['target_id']);
          if (!$media) {
            continue;
          }

          if (empty($selection['url'])) {
            $selection['url'] = $this->getDynamicBlockMediaUrl($media);
          }
          if (empty($selection['name'])) {
            $selection['name'] = $media->label();
          }
          if (empty($selection['type'])) {
            $selection['type'] = $media->bundle();
          }
          if (empty($value['name'])) {
            $value['name'] = $media->label();
          }
        }
        unset($selection);
      }

      $this->hydrateDynamicBlockMediaData($value);
    }
    unset($value);
  }

  /**
   * Gets the URL used by dashboard media selectors.
   *
   * @param mixed $media
   *   The media entity.
   *
   * @return string|null
   *   The media URL, when available.
   */
  protected function getDynamicBlockMediaUrl($media) {
    $bundle = $media->bundle();

    if ($bundle === 'image' && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
      $file = $media->get('field_media_image')->entity;
      if ($file) {
        return \Drupal::service('file_url_generator')
          ->generateString($file->getFileUri());
      }
    }

    if ($bundle === 'remote_video' && $media->hasField('field_media_oembed_video') && !$media->get('field_media_oembed_video')->isEmpty()) {
      return $media->get('field_media_oembed_video')->value;
    }

    if ($bundle === 'file' && $media->hasField('field_media_file') && !$media->get('field_media_file')->isEmpty()) {
      $file = $media->get('field_media_file')->entity;
      return $file ? $file->createFileUrl() : NULL;
    }

    if ($bundle === 'private_file' && $media->hasField('field_media_file_1') && !$media->get('field_media_file_1')->isEmpty()) {
      $file = $media->get('field_media_file_1')->entity;
      return $file ? $file->createFileUrl() : NULL;
    }

    if ($media->hasField('thumbnail') && !$media->get('thumbnail')->isEmpty()) {
      $file = $media->get('thumbnail')->entity;
      if ($file) {
        return \Drupal::service('file_url_generator')
          ->generateString($file->getFileUri());
      }
    }

    return NULL;
  }

  /**
   * Deletes a content block.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The delete response.
   */
  public function deleteContentBlock(Request $request) {
    $content = json_decode($request->getContent(), TRUE);
    $ids = $content['ids'] ?? [$content['id'] ?? 0];
    $ids = array_values(array_filter(array_map('intval', (array) $ids)));
    $blocks = $this->entityTypeManager
      ->getStorage('block_content')
      ->loadMultiple($ids);

    if (!$ids || count($blocks) !== count($ids)) {
      return new JsonResponse([
        'message' => $this->t('Content block not found.'),
      ], 404);
    }
    foreach ($blocks as $block) {
      if (!$block->access('delete', $this->currentUser())) {
        return new JsonResponse([
          'message' => $this->t('Access denied.'),
        ], 403);
      }
    }

    $this->entityTypeManager->getStorage('block_content')->delete($blocks);
    return new JsonResponse([
      'message' => $this->formatPlural(
        count($blocks),
        'Content block deleted successfully.',
        'Content blocks deleted successfully.'
      ),
    ]);
  }

  /**
   * API endpoint to fetch blocks data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing blocks data.
   */
  public function getBlocks(Request $request) {
    // Get query parameters.
    $page = $request->query->get('page', 1);
    $search = $request->query->get('search', '');
    $limit = 100;
    $offset = ($page - 1) * $limit;

    // Get all blocks from bridge region.
    $query = $this->entityTypeManager->getStorage('block')->getQuery()
      ->condition('region', 'bridge');

    // Add search condition if search term is provided.
    if (!empty($search)) {
      $query->condition('region', $search, 'like');
    }

    // Get total count before adding range.
    $query_count = clone $query;
    $total = $query_count->count()->execute();

    // Add range for pagination.
    $block_ids = $query->range($offset, $limit)->execute();
    $blocks = $this->entityTypeManager->getStorage('block')
      ->loadMultiple($block_ids);

    $block_data = [];
    foreach ($blocks as $block) {
      // Get block configuration.
      $config = $block->toArray();
      // Get block content if it's a custom block.
      $content = '';
      $image = '';
      $edit_url = '';
      if (strpos($config['plugin'], 'block_content:') === 0) {
        $uuid = str_replace('block_content:', '', $config['plugin']);
        $block_content = $this->entityTypeManager->getStorage('block_content')
          ->loadByProperties(['uuid' => $uuid]);
        if (!empty($block_content)) {
          $block_content = reset($block_content);
          $edit_url = Url::fromRoute('entity.block_content.canonical', ['block_content' => $block_content->id()], [
            'query' => [
              'destination' => Url::fromRoute('vactory_dashboard.settings.banner_blocks')
                ->toString(),
            ],
          ])
            ->toString();
          $content = $block_content->label();
          // Get the dynamic block components.
          if ($block_content->hasField('field_dynamic_block_components')) {
            $components_field = $block_content->field_dynamic_block_components;
            $widget_id = $components_field->widget_id;
            $widget = !empty($widget_id) ? \Drupal::service('vactory_dynamic_field.vactory_provider_manager')
              ->loadSettings($widget_id) : NULL;
            $image = !empty($widget['screenshot']) ? $widget['screenshot'] : "";
          }
        }
        $block_data[] = [
          'id' => $block->id(),
          'label' => $block->label(),
          'region' => $config['region'],
          'theme' => $config['theme'],
          'visibility' => $config['visibility'],
          'content' => $content,
          'status' => !empty($config['status']),
          'weight' => $config['weight'],
          'image' => $image,
          'edit_path' => $edit_url,
        ];
      }
    }

    return new JsonResponse([
      'data' => $block_data,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => ceil($total / $limit),
    ]);
  }

  /**
   * Processes block content data for the dashboard editor.
   */
  protected function processBlockContent(BlockContentInterface $block, array $fields) {
    return [
      'id' => $block->id(),
      'fields' => $this->nodeService->processNode($block, $fields),
    ];
  }

  /**
   * Creates a standard content block from dashboard JSON data.
   */
  public function createStandardContentBlock(BlockContentTypeInterface $block_content_type, Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['message' => $this->t('Invalid request data.')], 400);
    }

    $language = $data['language'] ?? $this->languageManager()->getCurrentLanguage()->getId();
    $fields = $data['fields'] ?? [];

    try {
      $block = $this->entityTypeManager->getStorage('block_content')->create([
        'type' => $block_content_type->id(),
        'langcode' => $language,
      ]);
      $this->applyStandardBlockFieldValues($block, $fields);
      $block->save();

      return new JsonResponse([
        'message' => $this->t('Content block created successfully.'),
        'block_id' => $block->id(),
        'list' => Url::fromRoute('vactory_dashboard.block_content')->toString(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'message' => $this->t('Error creating content block: @message', ['@message' => $e->getMessage()]),
      ], 500);
    }
  }

  /**
   * Saves a standard content block from dashboard JSON data.
   */
  public function saveStandardContentBlock(BlockContentInterface $block_content, Request $request) {
    $data = json_decode($request->getContent(), TRUE);
    if (!is_array($data)) {
      return new JsonResponse(['message' => $this->t('Invalid request data.')], 400);
    }

    $language = $data['language'] ?? $this->languageManager()->getCurrentLanguage()->getId();
    $has_translation = !empty($data['has_translation']);
    $fields = $data['fields'] ?? [];

    try {
      if (!$has_translation && !$block_content->hasTranslation($language)) {
        $block_content->addTranslation($language);
      }
      $block = $block_content->hasTranslation($language)
        ? $block_content->getTranslation($language)
        : $block_content;

      $this->applyStandardBlockFieldValues($block, $fields);
      $block->save();

      return new JsonResponse([
        'message' => $this->t('Content block saved successfully.'),
        'block_id' => $block_content->id(),
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'message' => $this->t('Error saving content block: @message', ['@message' => $e->getMessage()]),
      ], 500);
    }
  }

  /**
   * Applies submitted field values to a block content entity.
   */
  protected function applyStandardBlockFieldValues(BlockContentInterface $block, array $fields) {
    $field_definitions = $block->getFieldDefinitions();

    foreach ($fields as $field_name => $field_value) {
      if (!$block->hasField($field_name)) {
        continue;
      }

      $definition = $field_definitions[$field_name] ?? NULL;
      $field_type = $definition ? $definition->getType() : '';

      if ($field_name === 'info') {
        $block->set('info', $field_value);
        continue;
      }

      if ($field_type === 'boolean') {
        $block->set($field_name, (bool) $field_value);
        continue;
      }

      if ($field_type === 'colorapi_color_field') {
        $hex = is_array($field_value)
          ? ($field_value['color']['hexadecimal'] ?? '')
          : (string) $field_value;
        $block->set($field_name, $hex ? ['color' => ['hexadecimal' => $hex]] : NULL);
        continue;
      }

      if ($field_type === 'text_with_summary') {
        $block->set($field_name, [
          'value' => is_string($field_value) ? $field_value : '',
          'format' => 'full_html',
        ]);
        continue;
      }

      if ($field_type === 'entity_reference' && is_array($field_value) && isset($field_value['id'])) {
        $block->set($field_name, $field_value['id']);
        continue;
      }

      if ($field_type === 'block_field') {
        $block->set($field_name, $this->nodeService->buildBlockFieldValues(is_array($field_value) ? $field_value : []));
        continue;
      }

      if ($field_value !== NULL && $field_value !== '') {
        $block->set($field_name, $field_value);
      }
    }
  }

  /**
   * Removes the current block from its own block_field option lists.
   */
  protected function excludeSelfFromBlockFieldOptions(array &$fields, BlockContentInterface $block) {
    $self_plugin_id = 'block_content:' . $block->uuid();
    foreach ($fields as &$field) {
      if (($field['type'] ?? '') !== 'block_field' || empty($field['options'])) {
        continue;
      }
      unset($field['options'][$self_plugin_id]);
    }
  }

}
