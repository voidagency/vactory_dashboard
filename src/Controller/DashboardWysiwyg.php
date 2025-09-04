<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\vactory_dashboard\Form\CkeditorFieldForm;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DashboardWysiwyg extends ControllerBase {

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * @param \Drupal\Core\Form\FormBuilderInterface $formBuilder
   * @param \Drupal\Core\Render\RendererInterface $renderer
   */
  public function __construct(FormBuilderInterface $formBuilder, RendererInterface $renderer) {
    $this->formBuilder = $formBuilder;
    $this->renderer = $renderer;
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return \Drupal\vactory_dashboard\Controller\DashboardWysiwyg|static
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('form_builder'),
      $container->get('renderer')
    );
  }

  /**
   * Get ckeditor field.
   */
  public function prepareCkeditorField(Request $request) {
    try {
      $data = json_decode($request->getContent(), TRUE);

      $isMultiple = $data['isMultiple'] ?? FALSE;
      $isSingle = $data['isSingle'] ?? FALSE;
      $isExtra = $data['isExtra'] ?? FALSE;
      $isGroup = $data['isGroup'] ?? FALSE;
      $x_model = $data['xmodel'] ?? 'formData.fields[field.name]';
      $required = $data['required'] ?? 'field.required';
      $defaultValue = $data['defaultValue'] ?? '';

      // Step 1: Build the form.
      $id = uniqid('ck_', TRUE);
      $form = $this->formBuilder->getForm(CkeditorFieldForm::class, $id, TRUE, $isMultiple, $isSingle, $isExtra, $isGroup, $defaultValue, $x_model, $required);

      // Step 2: Render the form to HTML.
      $form_html = $this->renderer->renderRoot($form);

      // Step 3: Extract JS/CSS asset libraries.
      $attached_libraries = $form['#attached']['library'] ?? [];

      // Optional: Extract other asset types if needed.
      // e.g., $form['#attached']['drupalSettings'] if you want to include those.

      return new JsonResponse([
        'html' => $form_html,
        'libraries' => $attached_libraries,
        'id' => $id,
      ]);
    }
    catch (\Exception $exception) {
      return new JsonResponse([
        'error' => $exception->getMessage(),
      ], 500);
    }
  }

}
