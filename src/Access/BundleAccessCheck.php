<?php

namespace Drupal\vactory_dashboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

class BundleAccessCheck implements AccessInterface {

  /**
   * Vérifie les permissions dynamiques pour un bundle spécifique.
   *
   * @param string $bundle
   *   Le type de contenu (bundle).
   * @param \Drupal\Core\Session\AccountInterface $account
   *   L'utilisateur actuel.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Le résultat de l'accès.
   */
  public function access($bundle, AccountInterface $account) {
    // Vérifie si l'utilisateur a l'une des permissions nécessaires pour le bundle.
    if (
      $account->hasPermission("create $bundle content") ||
      $account->hasPermission("edit own $bundle content") ||
      $account->hasPermission("edit any $bundle content") ||
      $account->hasPermission("delete own $bundle content") ||
      $account->hasPermission("delete any $bundle content") ||
      $account->hasPermission("view $bundle revisions") ||
      $account->hasPermission("revert $bundle revisions") ||
      $account->hasPermission("delete $bundle revisions")
    ) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

}
