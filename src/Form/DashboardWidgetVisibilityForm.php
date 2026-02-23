<?php

namespace Drupal\vactory_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure visibility of dashboard and sidebar widgets.
 *
 * This form allows administrators to enable or disable UI components
 * displayed in the back-office dashboard and the sidebar settings.
 *
 * Configuration is stored in:
 * - my_module.dashboard_widget_visibility
 */
class DashboardWidgetVisibilityForm extends ConfigFormBase {

  /**
   * Configuration name.
   */
  const CONFIG_NAME = 'vactory_dashboard.dashboard_widget_visibility';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dashboard_widget_visibility_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * Builds the configuration form.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG_NAME);

    /* ---------------- Dashboard ---------------- */
    $form['dashboard'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Dashboard'),
    ];

    $form['dashboard']['stats'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stats'),
      '#default_value' => $config->get('stats') ?? TRUE,
    ];

    $form['dashboard']['redmine'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Redmine widget'),
      '#default_value' => $config->get('redmine') ?? TRUE,
    ];

    $form['dashboard']['recent_pages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pages récemment modifiées'),
      '#default_value' => $config->get('recent_pages') ?? TRUE,
    ];

    $form['dashboard']['quick_actions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Actions rapides'),
      '#default_value' => $config->get('quick_actions') ?? TRUE,
    ];

    /* ---------------- Sidebar ---------------- */
    $form['sidebar'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sidebar Paramètres'),
    ];

    $sidebar_items = [
      'translations' => 'Traductions mutualisées',
      'captcha' => 'Captcha',
      'languages' => 'Languages',
      'sitemaps' => 'Sitemaps',
      'shield' => 'Shield',
      'banner_blocks' => 'Banner Blocks',
    ];

    foreach ($sidebar_items as $key => $label) {
      $form['sidebar'][$key] = [
        '#type' => 'checkbox',
        '#title' => $this->t($label),
        '#default_value' => $config->get($key) ?? TRUE,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * Form submission handler.
   *
   * Saves the widget visibility configuration.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::CONFIG_NAME)
      ->set('stats', $form_state->getValue('stats'))
      ->set('redmine', $form_state->getValue('redmine'))
      ->set('recent_pages', $form_state->getValue('recent_pages'))
      ->set('quick_actions', $form_state->getValue('quick_actions'))
      ->set('translations', $form_state->getValue('translations'))
      ->set('captcha', $form_state->getValue('captcha'))
      ->set('languages', $form_state->getValue('languages'))
      ->set('sitemaps', $form_state->getValue('sitemaps'))
      ->set('shield', $form_state->getValue('shield'))
      ->set('banner_blocks', $form_state->getValue('banner_blocks'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
