<?php

namespace Drupal\vactory_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Regroupement de la configuration generale de la Dashboard.
 */
class VactoryDashboardSettingsForm extends ConfigFormBase {

  protected $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  public function getFormId() {
    return 'vactory_dashboard_settings_form';
  }

  protected function getEditableConfigNames() {
    return [
      'vactory_dashboard.global.settings',
    ];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('vactory_dashboard.global.settings');

    $form['image'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Logo'),
      '#default_value' => $config->get('image') ?? "",
      '#upload_location' => 'public://logos/',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg webp'],
        'file_validate_size' => [5242880],
      ],
      '#description' => $this->t('Upload an image (jpg, jpeg, png, webp).'),
    ];

    // --- SECTION MENU --- //
    $menu_config = $this->config('vactory_dashboard.global.settings');
    $menu_storage = $this->entityTypeManager->getStorage('menu');
    $menus = $menu_storage->loadMultiple();

    $menu_options = [];
    foreach ($menus as $menu) {
      $menu_options[$menu->id()] = $menu->label() . ' (' . $menu->id() . ')';
    }

    $form['menu_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Menu Settings'),
      '#open' => TRUE,
    ];
    $form['menu_settings']['menu_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Menu'),
      '#options' => $menu_options,
      '#default_value' => $menu_config->get('menu_id') ?? 'main',
      '#required' => TRUE,
      '#description' => $this->t('Select the menu machine name you want to use.'),

    ];

    // SECTION WEBFORMS //
    $form['webforms'] = [
      '#type' => 'details',
      '#title' => $this->t('Webforms to display in dashboard'),
      '#open' => TRUE,
    ];

    // Charger tous les webforms existants
    $webforms = \Drupal::entityTypeManager()
      ->getStorage('webform')
      ->loadMultiple();

    $options = [];
    foreach ($webforms as $webform) {
      $options[$webform->id()] = $webform->label();
    }

    // Lire la config existante
    $selected_webforms = $config->get('dashboard_webforms') ?? [];

    // Checkbox group
    $form['webforms']['dashboard_webforms'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Webforms to display in dashboard'),
      '#description' => $this->t('Select the webforms you want to display in the dashboard.'),
      '#options' => $options,
      '#default_value' => $selected_webforms,
    ];

    // Content types
    $form['content_types'] = [
      '#type' => 'details',
      '#title' => $this->t('Content types to display on dashboard sidebar'),
      '#open' => TRUE,
    ];

    $content_types = \Drupal::entityTypeManager()
      ->getStorage('node_type')
      ->loadMultiple();

    $options = [];
    foreach ($content_types as $content_type) {
      $options[$content_type->id()] = $content_type->label();
    }

    $config = $this->config('vactory_dashboard.global.settings');
    $selected_content_types = $config->get('dashboard_content_types') ?? [];

    $form['content_types']['dashboard_content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#description' => $this->t('Select the content types you want to display on the dashboard sidebar.'),
      '#options' => $options,
      '#default_value' => $selected_content_types,
    ];

    // Taxonomies
    $form['taxonomies'] = [
      '#type' => 'details',
      '#title' => $this->t('Taxonomies to display on dashboard sidebar'),
      '#open' => TRUE,
    ];

    $taxonomies = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_vocabulary')
      ->loadMultiple();

    $options = [];
    foreach ($taxonomies as $taxonomy) {
      $options[$taxonomy->id()] = $taxonomy->label();
    }

    $config = $this->config('vactory_dashboard.global.settings');
    $selected_taxonomies = $config->get('dashboard_taxonomies') ?? [];

    $form['taxonomies']['dashboard_taxonomies'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Taxonomies'),
      '#description' => $this->t('Select the taxonomies you want to display on the dashboard sidebar.'),
      '#options' => $options,
      '#default_value' => $selected_taxonomies,
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    // Sauvegarde menu
    $menu_id = $form_state->getValue('menu_id');
    $this->config('vactory_dashboard.global.settings')
      ->set('menu_id', $menu_id)
      ->save();

    $selected_webforms = array_filter($form_state->getValue('dashboard_webforms'));
    $this->configFactory()->getEditable('vactory_dashboard.global.settings')
      ->set('dashboard_webforms', $selected_webforms)
      ->save();

    $selected_content_types = array_filter($form_state->getValue('dashboard_content_types'));
    $this->configFactory()->getEditable('vactory_dashboard.global.settings')
      ->set('dashboard_content_types', $selected_content_types)
      ->save();

    $selected_taxonomies = array_filter($form_state->getValue('dashboard_taxonomies'));
    $this->configFactory()->getEditable('vactory_dashboard.global.settings')
      ->set('dashboard_taxonomies', $selected_taxonomies)
      ->save();

    $this->configFactory()->getEditable('vactory_dashboard.global.settings')
      ->set('image', $form_state->getValue('image') ?? "")
      ->save();

    \Drupal::cache()->delete('vactory_dashboard.vocabularies');
    \Drupal::cache()->delete('vactory_dashboard.content_types_items');
    \Drupal::cache()->delete('vactory_dashboard.forms');
    \Drupal::cache()->delete('vactory_dashboard.principal_menu_items');

    $this->messenger()
      ->addStatus($this->t('Dashboard settings saved successfully.'));
  }

}
