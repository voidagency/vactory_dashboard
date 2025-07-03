<?php

namespace Drupal\vactory_dashboard\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * Configure content type to taxonomy references.
 */
class ContentTypesSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  protected $configFactory;
  protected $entityFieldManager;


  /**
   * Constructs a new ContentTypesSettingsForm.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config
   *   The typed config manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $configFactory,
    EntityFieldManagerInterface $entityFieldManager
  ) {
    parent::__construct($config_factory, $typedConfigManager);
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $configFactory;
    $this->entityFieldManager = $entityFieldManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
          $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'advanced_dashboard_content_types';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['vactory_dashboard.advanced.content_types'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('vactory_dashboard.advanced.content_types');

    $content_types = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple();

    $form['#tree'] = TRUE;

    foreach ($content_types as $type_id => $type) {

      // Load only fields of the current content type.
      $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $type_id);

      $options = [];
      $taxonomy_selections = $config->get('taxonomy_selections') ?? [];
      $default_vocabularies = $taxonomy_selections[$type_id] ?? [];

      foreach ($field_definitions as $field_name => $definition) {
        if (
          $definition->getType() === 'entity_reference' &&
          $definition->getSetting('target_type') === 'taxonomy_term'
        ) {
          $target_bundles = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
          foreach ($target_bundles as $vocab_id) {
            $vocab = $this->entityTypeManager
              ->getStorage('taxonomy_vocabulary')
              ->load($vocab_id);

            if ($vocab) {
              $options[$vocab_id] = $vocab->label();
            }
          }
        }
      }

      if (count($options) > 0) {
        $form[$type_id] = [
          '#type' => 'details',
          '#title' => $type->label(),
          '#open' => FALSE,
          'taxonomies' => [
            '#type' => 'checkboxes',
            '#title' => $this->t('Taxonomies'),
            '#options' => $options,
            '#default_value' => $default_vocabularies,
          ],
        ];
      }
    }

    return parent::buildForm($form, $form_state) + $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $config = $this->configFactory->getEditable('vactory_dashboard.advanced.content_types');

    $content_types = $this->entityTypeManager
      ->getStorage('node_type')
      ->loadMultiple();

    $saved_config = [];

    foreach ($content_types as $type_id => $type) {
      $values = $form_state->getValue($type_id);
      $taxonomy_values = $values['taxonomies'] ?? [];

      // Collect only selected vocabularies (i.e., checked ones)
      $selected_vocabularies = [];
      foreach ($taxonomy_values as $vocab_id => $checked) {
        if ($checked) {
          $selected_vocabularies[] = $vocab_id;
        }
      }

      $saved_config[$type_id] = $selected_vocabularies;
    }

    $config->set('taxonomy_selections', $saved_config);
    $config->save();
  }

}
