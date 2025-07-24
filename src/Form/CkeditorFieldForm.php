<?php declare(strict_types=1);

namespace Drupal\vactory_dashboard\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a Vactory Dashboard form.
 */
final class CkeditorFieldForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vactory_dashboard_ckeditor_field';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $my_param = NULL, $isDF=FALSE, $isMultiple=FALSE, $isSingle=FALSE, $isExtra=FALSE, $isGroup=FALSE, $defaultValue='', $x_model = 'formData.fields[field.name]', $required = 'field.required'): array {
    // content type - add, edit page
    if (!$isDF) {
      $form[$my_param] = [
        '#type' => 'text_format',
        '#format' => 'full_html',
        '#attributes' => [
          'x-model' => $x_model,
          ':required' => 'field.required',
        ],
      ];

      return $form;
    }

    if ($isMultiple == "true") {
      $x_model = "item[fieldName]";
      $x_init = "if (\$el) { \$el.setAttribute('name', `item[\${fieldName}]`);}";
    }
    else if ($isSingle == "true") {
      $x_model = "blockForm.fields[fieldName]";
      $x_init = "if (\$el) { \$el.setAttribute('name', `blockForm.fields[\${fieldName}]`);}";
    } 
    else if ($isExtra == "true") {
      $x_model = "blockForm.extra_fields[fieldName]";
      $x_init = "if (\$el) { \$el.setAttribute('name', `blockForm.extra_fields[\${fieldName}]`);}";
    } 

    if ($isGroup == "true" && $isMultiple == "true") {
      $x_model = "item[fieldName][itemName]";
      $x_init = "if (\$el) { \$el.setAttribute('name', `item[\${fieldName}][\${itemName}]`);}";
    }

    if ($isGroup == "true" && $isSingle == "true") {
      $x_model = "blockForm.fields[fieldName][itemName]";
      $x_init = "if (\$el) { \$el.setAttribute('name', `blockForm.fields[\${fieldName}][\${itemName}]`);}";
    }

    if ($isGroup == "true" && $isExtra == "true") {
      $x_model = "blockForm.extra_fields[fieldName][itemName]";
      $x_init = "if (\$el) { \$el.setAttribute('name', `blockForm.extra_fields[\${fieldName}][\${itemName}]`);}";
    }

    // Dynamic field
    $form[$my_param] = [
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#default_value' => is_array($defaultValue) ? $defaultValue['value'] ?? '' : $defaultValue,
      '#attributes' => [
        'x-model' => $x_model,
        'x-init' => $x_init,
        ':required' => $required,
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->messenger()->addStatus($this->t('The message has been sent.'));
    $form_state->setRedirect('<front>');
  }

}
