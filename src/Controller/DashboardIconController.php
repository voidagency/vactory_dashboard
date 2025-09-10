<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for dashboard icon API endpoints.
 */
class DashboardIconController extends ControllerBase {

  /**
   * The icon provider plugin manager.
   *
   * @var \Drupal\vactory_icon\VactoryIconProviderPluginManager
   */
  protected $iconProviderPluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    // Check if vactory_icon service exists
    if ($container->has('plugin.manager.vactory_icon')) {
      $instance->iconProviderPluginManager = $container->get('plugin.manager.vactory_icon');
    }
    return $instance;
  }

  /**
   * Returns a JSON response with available icons.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing icons data.
   */
  public function getIcons() {
    try {
      // Check if vactory_icon module is enabled
      if (!\Drupal::moduleHandler()->moduleExists('vactory_icon')) {
        return new JsonResponse([
          'error' => 'Vactory Icon module not enabled',
          'icons' => []
        ], 404);
      }

      // Check if icon provider plugin manager is available
      if (!$this->iconProviderPluginManager) {
        return new JsonResponse([
          'error' => 'Icon provider plugin manager not available',
          'icons' => []
        ], 500);
      }

      $config = $this->config('vactory_icon.settings');
      $provider_plugin = $config->get('provider_plugin');
      
      if (!$provider_plugin) {
        return new JsonResponse([
          'error' => 'No icon provider configured',
          'icons' => []
        ], 404);
      }

      $icon_provider = $this->iconProviderPluginManager->createInstance($provider_plugin);
      $icons_data = $icon_provider->fetchIcons($config);
      $formatted_icons = [];

      if ($provider_plugin === 'xml_icon_provider') {
        if (!empty($icons_data) && isset($icons_data['symbol']) && is_array($icons_data['symbol'])) {
          foreach ($icons_data['symbol'] as $info) {
            $svg_id = $info['@attributes']['id'];
            $svg_ids[] = $svg_id;
            if (count($info['path']) > 1) {
              foreach ($info['path'] as $path) {
                $svg_paths_d[$svg_id][] = $path['@attributes']['d'];
              }
            } else {
              $svg_paths_d[$svg_id] = $info['path']['@attributes']['d'];
            }
            $formatted_icons[] = [
              'name' => $svg_id,
              'label' => $svg_id,
              'class' => 'icon-' . $svg_id,
            ];
          }
        }
      }
      else {
        if (isset($icons_data['icons']) && is_array($icons_data['icons'])) {
          foreach ($icons_data['icons'] as $icon) {
            if (isset($icon['properties']['name'])) {
              $icon_name = $icon['properties']['name'];
              $formatted_icons[] = [
                'name' => $icon_name,
                'label' => $icon_name,
                'class' => 'icon-' . $icon_name,
              ];
            }
          }
        }
      }

      // Determine provider type based on configuration
      $provider_type = 'font'; // default
      if ($config->get('from_xml_svgs')) {
        $provider_type = 'svg';
      }

      return new JsonResponse([
        'icons' => $formatted_icons,
        'total' => count($formatted_icons),
        'provider_type' => $provider_type,
        'debug' => [
          'provider_plugin' => $provider_plugin,
          'icons_count' => count($icons_data['icons'] ?? []),
          'from_xml_svgs' => $config->get('from_xml_svgs')
        ]
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('vactory_dashboard')->error('Error fetching icons: @message', [
        '@message' => $e->getMessage()
      ]);
      
      return new JsonResponse([
        'error' => 'Failed to load icons: ' . $e->getMessage(),
        'icons' => []
      ], 500);
    }
  }

  /**
   * Returns a JSON response with icon search results.
   *
   * @param string $query
   *   The search query.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing filtered icons data.
   */
  public function searchIcons($query = '') {
    $icons_response = $this->getIcons();
    $data = json_decode($icons_response->getContent(), TRUE);
    
    if (empty($query) || !isset($data['icons'])) {
      return $icons_response;
    }
    
    $query = strtolower(trim($query));
    $filtered_icons = array_filter($data['icons'], function($icon) use ($query) {
      return strpos(strtolower($icon['name']), $query) !== FALSE;
    });
    
    return new JsonResponse([
      'icons' => array_values($filtered_icons),
      'total' => count($filtered_icons),
      'query' => $query
    ]);
  }

}
