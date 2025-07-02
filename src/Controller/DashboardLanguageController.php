<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Entity\EntityListBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the language dashboard.
 */
class DashboardLanguageController extends ControllerBase {

  /**
   * The entity list builder for configurable languages.
   *
   * @var \Drupal\Core\Entity\EntityListBuilderInterface
   */
  protected $listBuilder;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new DashboardLanguageController.
   *
   * @param \Drupal\Core\Entity\EntityListBuilderInterface $listBuilder
   *   The list builder for configurable languages.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager service.
   */
  public function __construct(EntityListBuilderInterface $listBuilder, LanguageManagerInterface $languageManager) {
    $this->listBuilder = $listBuilder;
    $this->languageManager = $languageManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $list_builder = $container->get('entity_type.manager')
      ->getListBuilder('configurable_language');
    $language_manager = $container->get('language_manager');
    $twig = $container->get('twig');
    return new static($list_builder, $language_manager);
  }

  /**
   * Returns the render array for the language dashboard page.
   *
   * @return array
   *   A render array using the 'vactory_dashboard_languages' theme.
   */
  public function content() {
    return [
      '#theme' => 'vactory_dashboard_languages',
    ];
  }

  /**
   * Returns a JSON response containing language configuration.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with language data.
   */
  public function getLanguages() {
    $languages = $this->listBuilder->load();
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();

    $data = [];

    foreach ($languages as $language) {
      $data[] = [
        'id' => $language->id(),
        'name' => $language->label(),
        'label' => $language->label(),
        'default' => $language->id() === $default_langcode,
        'direction' => $language->getDirection(),
      ];
    }

    return new JsonResponse($data);
  }

}
