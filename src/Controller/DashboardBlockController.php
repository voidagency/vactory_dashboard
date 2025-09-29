<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the dashboard blocks.
 */
class DashboardBlockController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a DashboardBlockController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database')
    );
  }

  /**
   * Renders the dashboard page.
   *
   * @return array
   *   A render array.
   */
  public function banner() {
    return [
      '#theme' => 'vactory_dashboard_banner_blocks',
      '#attached' => [
        'library' => [
          'vactory_dashboard/banner-blocks',
        ],
      ],
    ];
  }

  /**
   * API endpoint to fetch blocks data.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response containing blocks data.
   */
  public function getBlocks(Request $request) {
    // Get query parameters
    $page = $request->query->get('page', 1);
    $search = $request->query->get('search', '');
    $limit = 100;
    $offset = ($page - 1) * $limit;

    // Get all blocks from bridge region
    $query = $this->entityTypeManager->getStorage('block')->getQuery()
      ->condition('region', 'bridge');

    // Add search condition if search term is provided
    if (!empty($search)) {
      $query->condition('region', $search, 'like');
    }

    // Get total count before adding range
    $query_count = clone $query;
    $total = $query_count->count()->execute();

    // Add range for pagination
    $block_ids = $query->range($offset, $limit)->execute();
    $blocks = $this->entityTypeManager->getStorage('block')
      ->loadMultiple($block_ids);

    $block_data = [];
    foreach ($blocks as $block) {
      // Get block configuration
      $config = $block->toArray();
      // Get block content if it's a custom block
      $content = '';
      $image = '';
      $edit_url = '';
      if (strpos($config['plugin'], 'block_content:') === 0) {
        $uuid = str_replace('block_content:', '', $config['plugin']);
        $block_content = $this->entityTypeManager->getStorage('block_content')
          ->loadByProperties(['uuid' => $uuid]);
        if (!empty($block_content)) {
          $block_content = reset($block_content);
          $edit_url = Url::fromRoute('entity.block_content.canonical', ['block_content' => $block_content->id()], [
            'query' => [
              'destination' => Url::fromRoute('vactory_dashboard.settings.banner_blocks')
                ->toString(),
            ],
          ])
            ->toString();
          $content = $block_content->label();
          // Get the dynamic block components
          if ($block_content->hasField('field_dynamic_block_components')) {
            $components_field = $block_content->field_dynamic_block_components;
            $widget_id = $components_field->widget_id;
            $widget = !empty($widget_id) ? \Drupal::service('vactory_dynamic_field.vactory_provider_manager')
              ->loadSettings($widget_id) : NULL;
            $image = !empty($widget['screenshot']) ? $widget['screenshot'] : "";
          }
        }
        $block_data[] = [
          'id' => $block->id(),
          'label' => $block->label(),
          'region' => $config['region'],
          'theme' => $config['theme'],
          'visibility' => $config['visibility'],
          'content' => $content,
          'status' => !empty($config['status']),
          'weight' => $config['weight'],
          'image' => $image,
          'edit_path' => $edit_url,
        ];
      }
    }

    return new JsonResponse([
      'data' => $block_data,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => ceil($total / $limit),
    ]);
  }

}
