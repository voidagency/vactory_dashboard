<?php

namespace Drupal\vactory_dashboard\Plugin\vactory_dynamic_field\Platform;

use Drupal\vactory_dynamic_field\VactoryDynamicFieldPluginBase;

/**
 * A Tour guide plugin.
 *
 * @PlatformProvider(
 *   id = "vactory_dashboard",
 *   title = @Translation("New Tour guide")
 * )
 */
class Tourguide extends VactoryDynamicFieldPluginBase {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, $widgetsPath) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, \Drupal::service('extension.path.resolver')->getPath('module', 'vactory_dashboard') . '/widgets');
  }

}