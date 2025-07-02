<?php

namespace Drupal\vactory_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Formulaire de configuration Redmine pour Vactory Dashboard.
 */
class RedmineSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'vactory_dashboard_redmine_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['vactory_dashboard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $config = $this->config('vactory_dashboard.settings');

    $form['redmine'] = [
      '#type' => 'details',
      '#title' => $this->t('Redmine integration'),
      '#open' => TRUE,
    ];

    $form['redmine']['redmine_project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project identifier'),
      '#default_value' => $config->get('redmine_project_id'),
      '#required' => TRUE,
      '#description' => $this->t('Redmine project identifier (e.g., elsan-migration). Found in project API.'),
    ];

    $form['redmine']['redmine_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#default_value' => $config->get('redmine_api_key'),
      '#required' => TRUE,
      '#description' => $this->t('Redmine API key .'),
    ];

    $form['redmine']['redmine_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Redmine URL'),
      '#default_value' => $config->get('redmine_url') ?? "https://redmine-api.leserveurdetest.com/issues",
      '#required' => TRUE,
      '#description' => $this->t('Enter the full Redmine URL (e.g., https://redmine.example.com).'),
      '#size' => 60,  
      '#maxlength' => 255,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $api_key = trim($form_state->getValue('redmine_api_key'));
    $project_id = $form_state->getValue('redmine_project_id');
    $redmine_url = trim($form_state->getValue('redmine_url'));

    if (empty($redmine_url)) {
      $form_state->setErrorByName('redmine_url', $this->t('The Redmine URL is required.'));
    } elseif (!filter_var($redmine_url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('redmine_url', $this->t('The Redmine URL is not valid. Please enter a valid URL (e.g., https://redmine.example.com).'));
    } else {
      $form_state->set('redmine_url', $redmine_url);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('vactory_dashboard.settings')
      ->set('redmine_project_id', $form_state->getValue('redmine_project_id'))
      ->set('redmine_api_key', trim($form_state->getValue('redmine_api_key')))
      ->set('redmine_url', trim($form_state->getValue('redmine_url')))
      ->save();

    parent::submitForm($form, $form_state);
  }

} 