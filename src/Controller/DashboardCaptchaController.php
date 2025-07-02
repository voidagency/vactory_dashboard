<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\recaptcha\Form\ReCaptchaAdminSettingsForm;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the webform dashboard.
 */
class DashboardCaptchaController extends ControllerBase {

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
   * Returns the captcha dashboard page.
   *
   * @return array
   *   A render array for the captcha dashboard.
   */
  public function content() {
    $form = $this->formBuilder->getForm(ReCaptchaAdminSettingsForm::class);

    $form['general']['recaptcha_verify_hostname']['#access'] = FALSE;
    $form['general']['recaptcha_use_globally']['#access'] = FALSE;
    $form['widget']['#access'] = FALSE;
    $form['general']['#access'] = FALSE;

    $form['recaptcha_site_key'] = $form['general']['recaptcha_site_key'];
    $form['recaptcha_secret_key'] = $form['general']['recaptcha_secret_key'];

    $form['recaptcha_site_key']['#disabled'] = TRUE;
    $form['recaptcha_secret_key']['#disabled'] = TRUE;

    $form['recaptcha_site_key']['#attributes']['disabled'] = 'disabled';
    $form['recaptcha_secret_key']['#attributes']['disabled'] = 'disabled';

    $form['recaptcha_site_key']['#title'] = $this->t('<span class="captcha-field-title">Site key</span>', [], ['context' => '_FRONTEND']);
    $form['recaptcha_secret_key']['#title'] = $this->t('<span class="captcha-field-title">Secret key</span>', [], ['context' => '_FRONTEND']);

    $form['recaptcha_site_key']['#description'] = $this->t('<span class="captcha-field-text">The site key given to you when you <a href=":url" class="captcha-field-link" target="_blank">register for reCAPTCHA</a>.</span>', [
      ':url' => 'https://www.google.com/recaptcha/admin',
    ], ['context' => '_FRONTEND']);
    $form['recaptcha_secret_key']['#description'] = $this->t('<span class="captcha-field-text">The secret key given to you when you <a href=":url" class="captcha-field-link" target="_blank">register for reCAPTCHA</a>.</span>', [
      ':url' => 'https://www.google.com/recaptcha/admin',
    ], ['context' => '_FRONTEND']);

    $form['recaptcha_site_key']['#attributes']['class'][] = 'captcha-field-input';
    $form['recaptcha_secret_key']['#attributes']['class'][] = 'captcha-field-input';

    $form['actions']['submit']['#prefix'] = '<div class="captcha-field-btn">';
    $form['actions']['submit']['#suffix'] = '</div>';

    $form['actions']['submit']['#access'] = FALSE;
    $form['actions']['submit']['#disabled'] = TRUE;

    return [
      '#theme' => 'vactory_dashboard_captcha',
      '#form' => $form,
    ];
  }

}
