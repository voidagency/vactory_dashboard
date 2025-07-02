<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\xmlsitemap\XmlSitemapListBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for the language dashboard.
 */
class DashboardSitemapController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a SitemapController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ModuleHandlerInterface $module_handler,
    LanguageManagerInterface $language_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->moduleHandler = $module_handler;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('language_manager')
    );
  }

  /**
   * Returns the render array for the sitemaps dashboard page.
   *
   * @return array
   *   A render array using the 'vactory_dashboard_sitemaps' theme.
   */
  public function content() {
    return [
      '#theme' => 'vactory_dashboard_sitemap',
    ];
  }

  /**
   * Returns a JSON response containing sitemaps configuration.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with sitemaps data.
   */
  public function getSitemaps() {
    $entity_type = $this->entityTypeManager->getDefinition('xmlsitemap');
    $storage = $this->entityTypeManager->getStorage('xmlsitemap');

    $list_builder = new XmlSitemapListBuilder(
      $entity_type,
      $storage,
      $this->moduleHandler,
      $this->languageManager
    );

    $sitemaps = [];
    foreach ($list_builder->load() as $entity) {
      $sitemaps[] = $list_builder->buildRow($entity);
    }

    return new JsonResponse($sitemaps);
  }

}
