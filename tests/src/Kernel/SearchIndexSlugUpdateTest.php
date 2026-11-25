<?php

namespace Drupal\Tests\vactory_dashboard\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;

/**
 * Update 9971: the "slug" field of the dashboard search index.
 *
 * On a site without the module that provides the term "slug" property,
 * search_api cannot load the field definition and, since search_api 1.43,
 * saving a view or the index fails. The update removes the field and its
 * references in the processors only when its definition cannot be loaded.
 *
 * @group vactory_dashboard
 */
class SearchIndexSlugUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'taxonomy',
    'search_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api']);
    require_once dirname(__DIR__, 3) . '/vactory_dashboard.install';
  }

  /**
   * A "slug" field whose property does not exist is removed everywhere.
   */
  public function testUnusableSlugFieldIsRemoved(): void {
    $this->createIndex('slug');

    $message = (string) vactory_dashboard_update_9971();

    $index = Index::load('vactory_dashboard_search');
    $this->assertStringContainsString('removed', $message);
    $this->assertNull($index->getField('slug'), 'The unusable field is removed.');
    $this->assertNotNull($index->getField('name'), 'The other fields are kept.');
    $fields = $index->getProcessor('html_filter')->getConfiguration()['fields'];
    $this->assertSame(['name'], $fields, 'The processor no longer refers to the field.');
  }

  /**
   * A "slug" field whose property exists is kept.
   */
  public function testUsableSlugFieldIsKept(): void {
    // Same field id, pointing to a property that exists on terms, as on a site
    // where the module providing "slug" is enabled.
    $this->createIndex('name');

    $message = (string) vactory_dashboard_update_9971();

    $index = Index::load('vactory_dashboard_search');
    $this->assertStringContainsString('kept', $message);
    $this->assertNotNull($index->getField('slug'), 'The usable field is kept.');
    $fields = $index->getProcessor('html_filter')->getConfiguration()['fields'];
    $this->assertSame(['name', 'slug'], $fields);
  }

  /**
   * Nothing to do when the index has no "slug" field.
   */
  public function testIndexWithoutSlugField(): void {
    $this->createIndex(NULL);
    $message = (string) vactory_dashboard_update_9971();
    $this->assertStringContainsString('nothing to do', $message);
  }

  /**
   * Creates the dashboard index with a "name" field and an optional "slug".
   *
   * The "slug" field is written straight into the configuration, as
   * config/install did on existing sites: search_api would drop an unusable
   * field when saving the index entity.
   *
   * @param string|null $slug_property
   *   The term property read by the "slug" field, or NULL for no such field.
   */
  private function createIndex(?string $slug_property): void {
    Index::create([
      'id' => 'vactory_dashboard_search',
      'name' => 'Dashboard Search',
      'datasource_settings' => [
        'entity:taxonomy_term' => [],
      ],
      'field_settings' => [
        'name' => [
          'label' => 'Name',
          'datasource_id' => 'entity:taxonomy_term',
          'property_path' => 'name',
          'type' => 'text',
        ],
      ],
      'processor_settings' => [
        'html_filter' => [
          'weights' => ['preprocess_index' => -15, 'preprocess_query' => -15],
          'all_fields' => FALSE,
          'fields' => ['name'],
          'title' => TRUE,
          'alt' => TRUE,
          'tags' => ['b' => 2],
        ],
      ],
    ])->save();
    if (!$slug_property) {
      return;
    }
    $this->config('search_api.index.vactory_dashboard_search')
      ->set('field_settings.slug', [
        'label' => 'Term slug',
        'datasource_id' => 'entity:taxonomy_term',
        'property_path' => $slug_property,
        'type' => 'text',
      ])
      ->set('processor_settings.html_filter.fields', ['name', 'slug'])
      ->save();
    \Drupal::entityTypeManager()->getStorage('search_api_index')->resetCache();
  }

}
