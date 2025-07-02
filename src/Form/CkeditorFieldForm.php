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
  public function buildForm(array $form, FormStateInterface $form_state, $my_param = NULL): array {
    $form[$my_param] = [
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#attributes' => [
        'x-model' => 'formData.fields[field.name]',
        ':required' => 'field.required',
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
