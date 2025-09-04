<?php declare(strict_types=1);

namespace Drupal\vactory_dashboard\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a Vactory Ckeditor field form.
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
  public function buildForm(array $form, FormStateInterface $form_state, $my_param = NULL, $isDF = FALSE, $isMultiple = FALSE, $isSingle = FALSE, $isExtra = FALSE, $isGroup = FALSE, $defaultValue = '', $x_model = 'formData.fields[field.name]', $required = 'field.required'): array {
    
    if (!$isDF) {
      return $this->buildContentTypeForm($form, $my_param, $x_model);
    }

    return $this->buildDynamicFieldForm($form, $my_param, $isMultiple, $isSingle, $isExtra, $isGroup, $defaultValue, $required);
  }

  /**
   * Builds form for content types (add/edit) or taxonomy (add/edit) context.
   *
   * @param array $form
   *   The form array.
   * @param string $my_param
   *   The field parameter name.
   * @param string $x_model
   *   The Alpine.js x-model attribute value.
   *
   * @return array
   *   The form array with the CKEditor field.
   */
  private function buildContentTypeForm(array $form, $my_param, $x_model): array {
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

  /**
   * Builds form for dynamic field context.
   *
   * @param array $form
   *   The form array.
   * @param string $my_param
   *   The field parameter name.
   * @param bool|string $isMultiple
   *   Whether the field is multiple.
   * @param bool|string $isSingle
   *   Whether the field is single.
   * @param bool|string $isExtra
   *   Whether the field is extra.
   * @param bool|string $isGroup
   *   Whether the field is group.
   * @param mixed $defaultValue
   *   The default value for the field.
   * @param string $required
   *   The required attribute value.
   *
   * @return array
   *   The form array with the CKEditor field.
   */
  private function buildDynamicFieldForm(array $form, $my_param, $isMultiple, $isSingle, $isExtra, $isGroup, $defaultValue, $required): array {
    $x_model = $this->buildXModel($isMultiple, $isSingle, $isExtra, $isGroup);
    $x_init = $this->buildXInit($isMultiple, $isSingle, $isExtra, $isGroup);

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
   * Builds the x-model attribute value based on field context.
   *
   * @param bool|string $isMultiple
   *   Whether the field is multiple.
   * @param bool|string $isSingle
   *   Whether the field is single.
   * @param bool|string $isExtra
   *   Whether the field is extra.
   * @param bool|string $isGroup
   *   Whether the field is group.
   *
   * @return string
   *   The x-model attribute value.
   */
  private function buildXModel($isMultiple, $isSingle, $isExtra, $isGroup): string {
    // Handle group contexts first
    if ($isGroup == "true") {
      if ($isMultiple == "true") {
        return "item[fieldName][itemName]";
      }
      if ($isSingle == "true") {
        return "blockForm.fields[fieldName][itemName]";
      }
      if ($isExtra == "true") {
        return "blockForm.extra_fields[fieldName][itemName]";
      }
    }

    // Handle non-group contexts
    if ($isMultiple == "true") {
      return "item[fieldName]";
    }
    if ($isSingle == "true") {
      return "blockForm.fields[fieldName]";
    }
    if ($isExtra == "true") {
      return "blockForm.extra_fields[fieldName]";
    }

    // Default fallback
    return "item[fieldName]";
  }

  /**
   * Builds the x-init attribute value based on field context.
   *
   * @param bool|string $isMultiple
   *   Whether the field is multiple.
   * @param bool|string $isSingle
   *   Whether the field is single.
   * @param bool|string $isExtra
   *   Whether the field is extra.
   * @param bool|string $isGroup
   *   Whether the field is group.
   *
   * @return string
   *   The x-init attribute value.
   */
  private function buildXInit($isMultiple, $isSingle, $isExtra, $isGroup): string {
    // Handle group contexts first
    if ($isGroup == "true") {
      if ($isMultiple == "true") {
        return "if (\$el) { \$el.setAttribute('name', `item[\${fieldName}][\${itemName}]`);}";
      }
      if ($isSingle == "true") {
        return "if (\$el) { \$el.setAttribute('name', `blockForm.fields[\${fieldName}][\${itemName}]`);}";
      }
      if ($isExtra == "true") {
        return "if (\$el) { \$el.setAttribute('name', `blockForm.extra_fields[\${fieldName}][\${itemName}]`);}";
      }
    }

    // Handle non-group contexts
    if ($isMultiple == "true") {
      return "if (\$el) { \$el.setAttribute('name', `item[\${fieldName}]`);}";
    }
    if ($isSingle == "true") {
      return "if (\$el) { \$el.setAttribute('name', `blockForm.fields[\${fieldName}]`);}";
    }
    if ($isExtra == "true") {
      return "if (\$el) { \$el.setAttribute('name', `blockForm.extra_fields[\${fieldName}]`);}";
    }

    // Default fallback
    return "if (\$el) { \$el.setAttribute('name', `item[\${fieldName}]`);}";
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

}
