<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Provides search functionality for the content.
 */
class ContentSearchService {

  /**
   * The alias manager service.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs the DashboardSearchService.
   *
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The alias manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    AliasManagerInterface $aliasManager,
    LanguageManagerInterface $languageManager,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->aliasManager = $aliasManager;
    $this->languageManager = $languageManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  public function crossSearch(string $query, string $bundle) {
    $results = [];
    $lang = $this->languageManager->getCurrentLanguage()
      ->getId();
    $query = \Drupal::entityQuery('node')
      ->condition('type', $bundle)
      ->condition('title', '%' . \Drupal::database()
          ->escapeLike($query) . '%', 'LIKE')
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('langcode', $lang)
      ->sort('created', 'DESC')
      ->range(0, 10)
      ->execute();

    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadMultiple($query);

    foreach ($nodes as $node) {
      $results[] = [
        'id' => $node->id(),
        'title' => $node->hasTranslation($lang) ? $node->getTranslation($lang)
          ->label() : $node->label(),
      ];
    }
    return $results;
  }

  /**
   * Searches for content by URL or keyword query.
   *
   * @param string $query
   *   The search query.
   * @param string|null $bundle
   *   The node bundle to filter by.
   *
   */
  public function search(string $query) {
    $results = [];

    // Handle search by path or full URL.
    $parsed_url = parse_url($query);
    $path = $parsed_url['path'] ?? '';
    $path = '/' . ltrim($path, '/');

    $lang = $this->languageManager->getCurrentLanguage()
      ->getId();

    $system_path = $this->aliasManager->getPathByAlias($path, $lang);

    if (preg_match('#^(/([a-z]{2})?)?/node/(\d+)$#', $system_path, $matches)) {
      $nid = $matches[3];
      $node = Node::load($nid);
      if ($node->hasTranslation($lang)) {
        $results[] = [
          'id' => $node->id(),
          'label' => $node->hasTranslation($lang) ? $node->getTranslation($lang)
            ->label() : $node->label(),
          'bundle' => $node->bundle(),
          'isImage' => FALSE,
          'entity_type' => $node->getEntityTypeId(),
          'entity_label' => \Drupal::entityTypeManager()
            ->getDefinition($node->getEntityTypeId())
            ->getLabel(),
          'url' => Url::fromRoute('vactory_dashboard.vactory_page.edit', ['id' => $node->id()], ['language' => $this->languageManager->getCurrentLanguage()])
            ->toString(),
        ];
      }
    }

    return $results;
  }

  /**
   * Searches for nodes by title.
   *
   * @param string $query
   *   The search string.
   * @param string|null $bundle
   *   The node bundle to restrict search.
   *
   * @return array
   *   An array of matched nodes with basic info.
   */
  private function contentSearch(string $query, string $bundle): array {
    $results = [];

    if (strlen($query) >= 2 && !empty($bundle)) {
      $nids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', $bundle)
        ->condition('title', '%' . \Drupal::database()
            ->escapeLike($query) . '%', 'LIKE')
        ->accessCheck(TRUE)
        ->sort('created', 'DESC')
        ->range(0, 10)
        ->execute();

      $nodes = $this->entityTypeManager->getStorage('node')
        ->loadMultiple($nids);

      foreach ($nodes as $node) {
        $results[] = [
          'id' => $node->id(),
          'title' => $node->label(),
          'type' => $node->bundle(),
          'url' => '/' . Url::fromRoute('vactory_dashboard.vactory_page.edit', ['id' => $node->id()])
              ->getInternalPath(),
        ];
      }
    }

    return $results;
  }

}
