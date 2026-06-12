<?php

namespace Drupal\vactory_dashboard\Form;

use Drupal\block_content\BlockContentInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\vactory_dynamic_field\Form\ModalForm;

/**
 * Renders a dynamic content block template as an inline dashboard form.
 */
class InlineDynamicBlockForm extends ModalForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'vactory_dashboard_inline_dynamic_block_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?BlockContentInterface $block_content = NULL) {
    if (!$block_content) {
      return $form;
    }

    $field_name = 'field_dynamic_block_components';
    $field_value = $block_content->get($field_name)->first();
    $widget_id = $field_value?->widget_id;
    $widget_data = $field_value?->widget_data;

    $this->fieldId = $field_name . '-dashboard-0';
    $this->cardinality = 1;
    $this->wrapperId = $field_name . '-dashboard-wrapper';
    $this->context = [
      'entity_type' => 'block_content',
      'entity_id' => $block_content->id(),
      'paragraph_id' => NULL,
    ];

    if ($widget_id) {
      $this->widget = $widget_id;
    }

    if ($widget_data && is_string($widget_data)) {
      $this->initializeWidgetData($widget_data, $form_state);
    }
    elseif ($this->widgetRows === NULL) {
      $form_state->set('num_widgets', 1);
      $this->widgetRows = 1;
    }

    $request = \Drupal::request();
    $request->query->set('field_name', $field_name);
    $request->query->set('field_bundle', $block_content->bundle());
    $request->query->set('entity_type_id', 'block_content');
    $request->query->set('field_id', $this->fieldId);
    $request->query->set('wrapper_id', $this->wrapperId);
    $request->query->set('cardinality', 1);
    if ($this->widget) {
      $request->query->set('widget_id', $this->widget);
    }

    $form = $this->widget
      ? $this->buildWidgetForm($form, $form_state)
      : $this->buildWidgetSelectorForm($form, $form_state);

    unset($form['#prefix'], $form['#suffix']);
    $form['#attributes']['class'][] = 'dashboard-inline-dynamic-form';
    $form['#attached']['library'][] = 'vactory_dashboard/inline-block-editor';

    $type = \Drupal::entityTypeManager()
      ->getStorage('block_content_type')
      ->load($block_content->bundle());
    $type_label = $type ? $type->label() : $block_content->bundle();
    $page_title = $block_content->isNew()
      ? $this->t('Add @type block', ['@type' => $type_label])
      : $this->t('Edit @label - @type', [
        '@label' => $block_content->label(),
        '@type' => $type_label,
      ]);
    $back_url = Url::fromRoute('vactory_dashboard.block_content')->toString();

    $form['dashboard_header'] = [
      '#type' => 'container',
      '#weight' => -1000,
      '#attributes' => [
        'class' => [
          'dashboard-inline-dynamic-form__header',
          'sticky',
          'top-0',
          'z-30',
          'mb-6',
          'flex',
          'items-center',
          'justify-between',
          'rounded-xl',
          'border-b',
          'border-slate-200',
          'bg-white',
          'px-6',
          'py-4',
          'shadow-sm',
        ],
      ],
      'heading' => [
        '#weight' => -10,
        '#markup' => Markup::create('<div class="flex items-center gap-3"><a href="'
        . $back_url . '"'
        . ' class="text-slate-400 transition-colors hover:text-primary-500"'
        . ' aria-label="' . Html::escape((string) $this->t('Back to content blocks')) . '">'
        . '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"'
        . ' stroke-width="1.5" stroke="currentColor" class="h-4 w-4" aria-hidden="true">'
        . '<path stroke-linecap="round" stroke-linejoin="round"'
        . ' d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"></path></svg></a>'
        . '<div><h1 class="text-2xl font-semibold text-slate-900">'
        . Html::escape((string) $page_title)
        . '</h1><p class="mt-1 text-sm text-slate-500">'
        . Html::escape((string) $type_label)
        . '</p></div></div>'),
      ],
    ];

    $form['block_info'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Block description'),
      '#default_value' => $block_content->label(),
      '#required' => TRUE,
      '#weight' => -900,
      '#attributes' => [
        'class' => ['dashboard-inline-dynamic-form__title'],
      ],
      '#prefix' => '<div class="dashboard-inline-dynamic-form__settings">',
      '#suffix' => '</div>',
    ];

    if ($this->widget && isset($form['actions']['send'])) {
      $form['actions']['send']['#value'] = $this->t('Save');
      $form['actions']['send']['#ajax'] = NULL;
      $form['actions']['send']['#attributes']['class'] = [
        'dashboard-inline-dynamic-form__save',
      ];
      $form['actions']['send']['#submit'] = ['::saveBlock'];
      unset($form['actions']['#weight']);
      $form['actions']['#attributes']['class'][] = 'dashboard-inline-dynamic-form__actions';
      $form['dashboard_header']['actions'] = $form['actions'];
      $form['dashboard_header']['actions']['#weight'] = 10;
      unset($form['actions']);
    }

    if (isset($form['components'])) {
      $form['components']['#attributes']['class'][] = 'dashboard-inline-dynamic-form__components';
      if (isset($form['components']['extra_field'])) {
        $form['components']['extra_field']['#title'] = $this->t('Global settings');
        $form['components']['extra_field']['#title_display'] = 'before';
        $form['components']['extra_field']['#attributes']['class'][] = 'dashboard-inline-dynamic-form__global-settings';
        unset($form['components']['extra_field']['#attributes']['style']);
      }

      foreach ($form['components'] as $key => &$component) {
        if (!is_numeric($key) || !is_array($component)) {
          continue;
        }
        $component['#attributes']['class'][] = 'dashboard-inline-dynamic-form__component';
        unset($component['#attributes']['style']);
      }
      unset($component);
    }

    return $form;
  }

  /**
   * Initializes widget data using the same structure as the modal form.
   */
  protected function initializeWidgetData(string $widget_data, FormStateInterface $form_state) {
    $decoded = json_decode($widget_data, TRUE) ?: [];
    $this->widgetData = $decoded;
    $extra_fields = $decoded['extra_field'] ?? NULL;

    unset(
      $this->widgetData['extra_field'],
      $this->widgetData['pending_content'],
      $decoded['extra_field'],
      $decoded['pending_content']
    );

    $weight = 1;
    foreach ($this->widgetData as &$component) {
      if (is_array($component) && !isset($component['_weight'])) {
        $component['_weight'] = $weight++;
      }
    }
    unset($component);

    usort($this->widgetData, static function ($first, $second) {
      return (int) (($first['_weight'] ?? 0) <=> ($second['_weight'] ?? 0));
    });

    if ($extra_fields !== NULL) {
      $this->widgetData['extra_field'] = $extra_fields;
    }

    if ($form_state->get('num_widgets') === NULL) {
      $form_state->set('num_widgets', max(1, count($decoded)));
    }
    $this->widgetRows = $form_state->get('num_widgets');
  }

  /**
   * Saves the inline dynamic template directly on the content block.
   */
  public function saveBlock(array &$form, FormStateInterface $form_state) {
    $block_content = $form_state->getBuildInfo()['args'][0] ?? NULL;
    if (!$block_content instanceof BlockContentInterface) {
      return;
    }

    $data = $form_state->getValue('components') ?: [];
    $this->findDatetimeElement($data);
    $pending = $this->autoPopulateManager
      ->findParentKeysStartingWith($data, 'dummy_');
    $data['pending_content'] = array_map(
      static fn($item) => is_array($item) ? implode('.', $item) : $item,
      $pending
    );

    $block_content->set('info', $form_state->getValue('block_info'));
    $block_content->set('field_dynamic_block_components', [
      'widget_id' => $this->widget,
      'widget_data' => json_encode($data),
    ]);
    $block_content->save();

    $this->messenger()->addStatus($this->t('Content block saved successfully.'));
    $form_state->setRedirect('vactory_dashboard.block_content');
  }

}
