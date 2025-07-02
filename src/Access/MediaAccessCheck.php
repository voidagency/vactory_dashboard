<?php

namespace Drupal\vactory_dashboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

class MediaAccessCheck implements AccessInterface {

  /**
   * Vérifie les permissions pour un type de média spécifique.
   *
   * @param string $type_id
   *   L'identifiant du type de média (par exemple, 'files').
   * @param \Drupal\Core\Session\AccountInterface $account
   *   L'utilisateur actuel.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Le résultat de l'accès.
   */
  public function checkAccess($type_id, AccountInterface $account) {
    // Vérifie si l'utilisateur a l'une des permissions nécessaires pour le type de média.
    $type_id = str_replace('-', '_', $type_id);
    if (
      $account->hasPermission("create $type_id media") ||
      $account->hasPermission("edit own $type_id media") ||
      $account->hasPermission("edit any $type_id media") ||
      $account->hasPermission("delete own $type_id media") ||
      $account->hasPermission("delete any $type_id media")
    ) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

}
