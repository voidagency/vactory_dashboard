<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\metatag\MetatagManagerInterface;
use Drupal\node\NodeInterface;

class MetatagService {

  protected MetatagManagerInterface $metatagManager;

  public function __construct(MetatagManagerInterface $metatagManager) {
    $this->metatagManager = $metatagManager;
  }

  /**
 * Prepares the meta tags for a node.
 *
 * @param \Drupal\node\NodeInterface $node
 *   The node for which to generate the metatags.
 *
 * @return array
 *   An array of meta tags.
 */
  public function prepareMetatags(NodeInterface $node): array {
    $tags = $this->metatagManager->tagsFromEntityWithDefaults($node);
    foreach ($tags as $key => $value) {
      if (is_array($value)) {
        $tags[$key] = isset($value[0]) ? (string) $value[0] : '';
      }
    }

    return $tags;
  }

}
