<?php

namespace Drupal\vactory_dashboard\Service;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Service for generating preview URLs for content.
 */
class PreviewUrlService {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;
  

  /**
   * The frontend domain, typically set via environment variable.
   *
   * @var string
   */
  protected $frontendDomain;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;


  /**
   * Constructs the PreviewUrlService object.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   */
  public function __construct(ModuleHandlerInterface $module_handler, LanguageManagerInterface $language_manager) {
    $this->moduleHandler = $module_handler;
    $this->languageManager = $language_manager;
  }

  /**
   * Creates an instance of the service.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   An instance of this class.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('module_handler'),
      $container->get('language_manager')
    );
  } 

  /**
   * Generates the preview URL for a given node.
   *
   * @param \Drupal\Core\Entity\EntityInterface $node
   *   The node entity.
   *
   * @return string
   *   The generated preview URL.
   */
  public function getPreviewUrl(EntityInterface $node): string {
    $this->frontendDomain = getenv('FRONTEND_URL');

    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    // Get translated node if needed.
    $preview_node = $node->hasTranslation($langcode)
      ? $node->getTranslation($langcode)
      : $node;

    if ($this->moduleHandler->moduleExists('vactory_decoupled')) {
      $path = $preview_node->toUrl('canonical')->toString();
      return "{$this->frontendDomain}{$path}";
    }

    return $preview_node->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

}