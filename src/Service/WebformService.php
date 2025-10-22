<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\webform\WebformInterface;

/**
 * Webform service.
 */
class WebformService {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The WebformService construct.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   */
  public function __construct(ModuleHandlerInterface $moduleHandler) {
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * Anonymize data.
   */
  public function anonymizeData(WebformInterface $webform, $settings = [], $value = '') {
    if (!$this->moduleHandler->moduleExists('vactory_webform_anonymize')) {
      return $value;
    }
    $anonymizeHelper = \Drupal::service('vactory_webform_anonymize.helper');
    if (!$anonymizeHelper->shouldAnonymize($webform)) {
      return $value;
    }
    return $anonymizeHelper->anonymizeValue($value, $settings);
  }

}
