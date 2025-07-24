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

    protected $formBuilder;
    protected $renderer;

    public function __construct(FormBuilderInterface $formBuilder, RendererInterface $renderer) {
        $this->formBuilder = $formBuilder;
        $this->renderer = $renderer;
    }

    public static function create(ContainerInterface $container) {
        return new static(
        $container->get('form_builder'),
        $container->get('renderer')
        );
    }

    public function getForm(Request $request) {
      try {
        $data = json_decode($request->getContent(), true);

        $isMultiple = $data['isMultiple'] ?? false;
        $isSingle = $data['isSingle'] ?? false;
        $isExtra = $data['isExtra'] ?? false;
        $isGroup = $data['isGroup'] ?? false;
        $defaultValue = $data['defaultValue'] ?? '';

        // Step 1: Build the form.
        $id = uniqid('ck_', true);
        $form = $this->formBuilder->getForm(CkeditorFieldForm::class, $id, true, $isMultiple, $isSingle, $isExtra, $isGroup, $defaultValue);

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
      } catch (\Exception $exception) {
        return new JsonResponse([
          'error' => $exception->getMessage(),
        ], 500);
      }

    }

  
}
