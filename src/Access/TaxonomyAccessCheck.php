<?php

namespace Drupal\vactory_dashboard\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;

class TaxonomyAccessCheck implements AccessInterface {

  /**
   * Vérifie les permissions pour créer, éditer ou supprimer des termes.
   *
   * @param string $vid
   *   L'identifiant du vocabulaire.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   L'utilisateur actuel.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   Le résultat de l'accès.
   */
  public function checkAccess($vid, AccountInterface $account) {
    // Vérifie si l'utilisateur a l'une des permissions nécessaires.
    if (
      $account->hasPermission("create terms in $vid") ||
      $account->hasPermission("edit terms in $vid") ||
      $account->hasPermission("delete terms in $vid")
    ) {
      return AccessResult::allowed();
    }
    return AccessResult::forbidden();
  }

}
