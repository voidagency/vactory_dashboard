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
  public function buildForm(array $form, FormStateInterface $form_state, $my_param = NULL, $isDF=FALSE, $x_model = 'formData.fields[field.name]', $required = 'field.required'): array {
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
    
    // Dynamic field - single
    $form[$my_param] = [
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#attributes' => [
        'x-model' => $x_model,
        'x-init' => "if (\$el) {\$el.setAttribute('name', `blockForm.fields[\${fieldName}]`); \$el.setAttribute('id', edit-`blockForm.fields[\${fieldName}]`-value); }",
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
