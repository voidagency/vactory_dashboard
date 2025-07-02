<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\locale\SourceString;
use Drupal\locale\StringStorageInterface;

/**
 * Service for managing custom translatable strings in the frontend context.
 */
class TranslationService {

  /**
   * The locale string storage service.
   *
   * Handles storage and retrieval of translatable strings.
   *
   * @var \Drupal\locale\StringStorageInterface
   */
  protected $localeStorage;

  /**
   * Constructs a new TranslationService instance.
   *
   * @param \Drupal\locale\StringStorageInterface $localeStorage
   *   The locale string storage service.
   */
  public function __construct(StringStorageInterface $localeStorage) {
    $this->localeStorage = $localeStorage;
  }

  /**
   * Creates a new source string if it doesn't already exist.
   *
   * Adds the string to the '_FRONTEND' context to enable translation.
   *
   * @param string $source_string
   *   The source string to register for translation.
   */
  public function createString(string $source_string): void {
    $string = $this->localeStorage->findString(['source' => $source_string]);

    if ($string === NULL) {
      $string = new SourceString();
      $string->context = '_FRONTEND';
      $string->setString($source_string);
      $string->setStorage($this->localeStorage);
      $string->save();
    }
  }

}
