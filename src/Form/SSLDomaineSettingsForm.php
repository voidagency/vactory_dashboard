<?php

namespace Drupal\vactory_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\vactory_dashboard\Service\SslService;


class SSLDomaineSettingsForm extends ConfigFormBase {

  protected $sslService;

  public function __construct($config_factory, SslService $ssl_service) {
    parent::__construct($config_factory);
    $this->sslService = $ssl_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('vactory_dashboard.ssl_service') 
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'vactory_dashboard_ssl_domaine_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['vactory_dashboard.ssl.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('vactory_dashboard.ssl.settings');
    $domain = $config->get('name_domain');
    $ssl_info = $config->get('ssl_info') ?? [];
  
    $form['ssl'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuration SSL Domaine'),
      '#open' => TRUE,
    ];
  
    $form['ssl']['name_domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Domain name to monitor'),
      '#default_value' => $domain,
      '#description' => $this->t('Enter the domain name without protocol (e.g., www.example.com)'),
      '#required' => TRUE,
    ];
  
    // **N'afficher la section SSL info que si le domaine est défini (non vide)**
    if (!empty($domain)) {
      $form['ssl']['ssl_info'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Statut SSL'),
        '#prefix' => '<div id="ssl-info-wrapper">',
        '#suffix' => '</div>',
      ];
  
      if (!empty($ssl_info)) {
        $form['ssl']['ssl_info']['host'] = [
          '#type' => 'item',
          '#title' => $this->t('Host'),
          '#markup' => $ssl_info['host'] ?? '',
        ];
        $form['ssl']['ssl_info']['issuer'] = [
          '#type' => 'item',
          '#title' => $this->t('Issuer'),
          '#markup' => $ssl_info['issuer_o'] ?? '',
        ];
        $form['ssl']['ssl_info']['valid_till'] = [
          '#type' => 'item',
          '#title' => $this->t('Valid till'),
          '#markup' => $ssl_info['valid_till'] ?? '',
        ];
        $form['ssl']['ssl_info']['days_left'] = [
          '#type' => 'item',
          '#title' => $this->t('Days left'),
          '#markup' => $ssl_info['days_left'] ?? '',
        ];
        $form['ssl']['ssl_info']['cert_valid'] = [
          '#type' => 'item',
          '#title' => $this->t('Certificate valid'),
          '#markup' => !empty($ssl_info['cert_valid']) ? $this->t('Yes') : $this->t('No'),
        ];

        if (!empty($ssl_info['days_left']) && $ssl_info['days_left'] <= 10) {
          \Drupal::messenger()->addWarning($this->t('⚠️ Le certificat SSL expire dans @days jours.', ['@days' => $ssl_info['days_left']]));
        }

      }
      else {
        $form['ssl']['ssl_info']['message'] = [
          '#markup' => '<p>' . $this->t('Aucune donnée SSL stockée pour ce domaine.') . '</p>',
        ];
      }
  
      // Bouton AJAX pour rafraîchir les infos SSL.
      $form['ssl']['refresh_ssl'] = [
        '#type' => 'button',
        '#value' => $this->t('Mettre à jour les infos SSL'),
        '#ajax' => [
          'callback' => [$this, 'refreshSSLInfoCallback'],
          'wrapper' => 'ssl-info-wrapper',
          'effect' => 'fade',
        ],
        '#limit_validation_errors' => [['ssl', 'name_domain']],
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
  
    return parent::buildForm($form, $form_state);
  }
  
  /**
   * Callback AJAX pour mettre à jour les infos SSL.
   */
  public function refreshSSLInfoCallback(array &$form, FormStateInterface $form_state) {
    $domain = $form_state->getValue('name_domain');
    if (!empty($domain)) {
      $this->sslService->getSSLStatus($domain, true);
    }
    return $this->buildForm([], $form_state)['ssl']['ssl_info'];
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $domain = $form_state->getValue('name_domain');
    if (!empty($domain) && !preg_match('/^([a-z0-9\-]+\.)+[a-z]{2,}$/', $domain)) {
      $form_state->setErrorByName('name_domain', $this->t('The domain name appears to be invalid.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $domain = $form_state->getValue('name_domain');
  
    // Sauvegarde du domaine
    $config_editable = $this->configFactory()->getEditable('vactory_dashboard.ssl.settings');
    $config_editable->set('name_domain', $domain)->save();
  
    if (!empty($domain)) {
      $this->sslService->getSSLStatus($domain, true);
    }
  
    parent::submitForm($form, $form_state);
  }
  
}
