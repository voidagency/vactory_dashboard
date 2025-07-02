<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Service to validate URL aliases.
 */
class AliasValidationService {

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the AliasValidationService.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(LanguageManagerInterface $languageManager, EntityTypeManagerInterface $entityTypeManager) {
    $this->languageManager = $languageManager;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Validates an alias and throws an exception if invalid.
   *
   * @param string $alias
   *   The alias to validate.
   * @param int|null $current_nid
   *   The current node ID.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   If the alias is invalid.
   */
  public function validate(string $alias, ?int $current_nid = 0): void {
    $alias = trim(urldecode($alias));

    // Check if alias is empty
    if (empty($alias)) {
      throw new BadRequestHttpException('Alias cannot be empty.');
    }
    
    // Ensure alias starts with a slash
    if (strpos($alias, '/') !== 0) {
      $alias = "/$alias";
    }

    // Disallow spaces, dots, or special characters except dashes and slashes
    if (!preg_match('#^\/[a-zA-Z0-9\/\-]*$#', $alias)) {
      throw new BadRequestHttpException('Alias must only contain letters, numbers, slashes (/) and dashes (-).');
    }

    // Check for language code in alias
    preg_match('#^/([^/]+)#', $alias, $matches);
    $first_segment = $matches[1] ?? null;

    $langcodes = array_keys($this->languageManager->getLanguages());
    if ($first_segment && in_array($first_segment, $langcodes)) {
      throw new BadRequestHttpException("Alias must not include language codes like \"$first_segment\".");
    }

    $langcode = $this->languageManager->getDefaultLanguage()->getId();

    $path_alias_storage = $this->entityTypeManager->getStorage('path_alias');

    // Check for existing alias conflict
    $existing_aliases = $path_alias_storage->loadByProperties([
      'alias' => $alias,
      'langcode' => $langcode,
    ]);

    foreach ($existing_aliases as $existing_alias) {
      if (preg_match('/^\/node\/(\d+)$/', $existing_alias->getPath(), $matches)) {
        $existing_nid = (int) $matches[1];
        if ($current_nid === null || $existing_nid !== $current_nid) {
          throw new BadRequestHttpException('Alias is already used by another node.');
        }
      }
    }
  }

}
