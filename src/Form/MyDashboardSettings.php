<?php

namespace Drupal\vactory_dashboard\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure site information settings for this site.
 *
 * @internal
 */
class MyDashboardSettings extends ConfigFormBase {

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * The request context.
   *
   * @var \Drupal\Core\Routing\RequestContext
   */
  protected $requestContext;

  /**
   * Constructs a SiteInformationForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typedConfigManager
   *   The typed config manager.
   * @param \Drupal\path_alias\AliasManagerInterface $alias_manager
   *   The path alias manager.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The path validator.
   * @param \Drupal\Core\Routing\RequestContext $request_context
   *   The request context.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typedConfigManager, AliasManagerInterface $alias_manager, PathValidatorInterface $path_validator, RequestContext $request_context) {
    parent::__construct($config_factory, $typedConfigManager);
    $this->aliasManager = $alias_manager;
    $this->pathValidator = $path_validator;
    $this->requestContext = $request_context;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('path_alias.manager'),
      $container->get('path.validator'),
      $container->get('router.request_context')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'advanced_dashboard_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['vactory_dashboard.advanced.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $site_config = $this->config('vactory_dashboard.advanced.settings');

    $form['advanced_dashboard'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Dashboard'),
      '#open' => TRUE,
    ];
    $form['advanced_dashboard']['video_tutoriel'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Video tutoriel url'),
      '#default_value' => $site_config->get('video_tutoriel'),
    ];

    $form['advanced_dashboard']['tutorial_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('User manual'),
      '#description' => $this->t('Upload a PDF.'),
      '#upload_location' => 'public://user_manuals/',
      '#default_value' => $site_config->get('tutorial_file'),
      '#upload_validators' => [
        'file_validate_extensions' => ['pdf'],
        'file_validate_size' => [20 * 1024 * 1024],
      ],
      '#cardinality' => 1,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('vactory_dashboard.advanced.settings')
      ->set('video_tutoriel', $form_state->getValue('video_tutoriel'))
      ->set('tutorial_file', $form_state->getValue('tutorial_file'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
