<?php

namespace Drupal\Tests\vactory_dashboard\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Update 9971 runs before the pending updates of other modules.
 *
 * Until 9971 has removed the unusable "slug" field from the dashboard search
 * index, an update of another module that saves a view or the index fails
 * (Redmine #71716).
 *
 * @group vactory_dashboard
 */
class UpdateOrderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'vactory_dashboard_update_order_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once $this->root . '/core/includes/update.inc';
    // vactory_dashboard is not enabled here (its services need many modules):
    // its update functions come from its .install file, as on an update run.
    require_once dirname(__DIR__, 3) . '/vactory_dashboard.install';
    $this->container->get('module_handler')->loadInclude('vactory_dashboard_update_order_test', 'install');
  }

  /**
   * With 9971 pending, the update of the other module waits for it.
   */
  public function testUpdate9971RunsFirst(): void {
    $this->setInstalledVersions(9970);
    $order = $this->resolvedOrder();
    $this->assertLessThan(
      array_search('vactory_dashboard_update_order_test_update_10001', $order, TRUE),
      array_search('vactory_dashboard_update_9971', $order, TRUE),
      'vactory_dashboard_update_9971 runs before the update of the other module.'
    );
  }

  /**
   * Once 9971 has run, no dependency is declared.
   */
  public function testNoDependencyOnceDone(): void {
    $this->setInstalledVersions(9971);
    $this->assertSame([], vactory_dashboard_update_dependencies());
  }

  /**
   * Sets the schema of both modules: the other module has one pending update.
   */
  protected function setInstalledVersions(int $dashboard): void {
    $registry = $this->container->get('update.update_hook_registry');
    $registry->setInstalledVersion('vactory_dashboard', $dashboard);
    $registry->setInstalledVersion('vactory_dashboard_update_order_test', 10000);
  }

  /**
   * Update functions in the order Drupal runs them.
   *
   * The other module comes first in the starting list: without a dependency,
   * its update would run first.
   */
  protected function resolvedOrder(): array {
    $updates = update_resolve_dependencies([
      'vactory_dashboard_update_order_test' => 10001,
      'vactory_dashboard' => 9971,
    ]);
    return array_keys($updates);
  }

}
