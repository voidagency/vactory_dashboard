<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\shield\Form\ShieldSettingsForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the shield settings.
 */
class DashboardShieldController extends ControllerBase {

  /**
   * The form builder service.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * Constructor.
   */
  public function __construct(FormBuilderInterface $formBuilder) {
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder')
    );
  }

  /**
   * Returns the shield settings page.
   *
   * @return array
   *   A render array for the shield settings.
   */
  public function content() {
    if (\Drupal::moduleHandler()->moduleExists('shield')) {
      $form = $this->formBuilder->getForm(form_arg: ShieldSettingsForm::class);

      $form['general']['#access'] = FALSE;
      $form['exceptions']['#access'] = FALSE;
      $form['description']['#access'] = FALSE;
      $form['credentials']['#access'] = FALSE;
      $form['credentials']['credential_provider']['#access'] = FALSE;

      $form['credential_provider_user'] = $form['credentials']['providers']['shield']['user'];
      $form['credential_provider_pass'] = $form['credentials']['providers']['shield']['pass'];

      $form['credential_provider_user']['#title'] = $this->t('<span class="shiled-field-title">User</span>', [], ['context' => '_FRONTEND']);
      $form['credential_provider_pass']['#title'] = $this->t('<span class="shiled-field-title">Password</span>', [], ['context' => '_FRONTEND']);

      $form['credential_provider_user']['#attributes']['class'][] = 'shield-field-input';
      $form['credential_provider_pass']['#attributes']['class'][] = 'shield-field-input';

      $form['credential_provider_user']['#disabled'] = TRUE;
      $form['credential_provider_pass']['#disabled'] = TRUE;

      $form['credential_provider_user']['#attributes']['disabled'] = 'disabled';
      $form['credential_provider_pass']['#attributes']['disabled'] = 'disabled';

      $form['actions']['submit']['#access'] = FALSE;
      $form['actions']['submit']['#disabled'] = TRUE;

      return [
        '#theme' => 'vactory_dashboard_shield',
        '#form' => $form,
        '#isShieldEnabled' => $form['general']['shield_enable']['#default_value'],
      ];
    }

    return $this->redirect('vactory_dashboard.home');
  }

}
