<?php

namespace Drupal\vactory_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\vactory_dashboard\Service\TranslationService;

/**
 * Controller for the translations dashboard.
 */
class DashboardTranslationsController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The language manager service.
   *
   * Provides access to available languages and their configurations.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The translation service.
   *
   * Handles creation and management of translatable strings.
   *
   * @var \Drupal\vactory_dashboard\Service\TranslationService
   */
  protected $translationService;

  /**
   * Constructs a DashboardTranslationsController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database, LanguageManagerInterface $languageManager, TranslationService $translationService) {
    $this->database = $database;
    $this->languageManager = $languageManager;
    $this->translationService = $translationService;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('language_manager'),
      $container->get('vactory_dashboard.translation_service'),
    );
  }

  /**
   * Returns the translations dashboard page.
   *
   * @return array
   *   A render array for the translations dashboard.
   */
  public function content() {
    return [
      '#theme' => 'vactory_dashboard_translations',
      '#attached' => [
        'library' => ['vactory_dashboard/translation'],
        'drupalSettings' => [
          'vactoryDashboard' => [
            'dataPath' => Url::fromRoute('vactory_dashboard.translations.data')->toString(),
            'langsPath' => Url::fromRoute('vactory_dashboard.translations.languages')->toString(),
            'deletePath' => Url::fromRoute('vactory_dashboard.translations.delete')->toString(),
            'bulkDeletePath' => Url::fromRoute('vactory_dashboard.translations.bulk_delete')->toString(),
            'importPath' => Url::fromRoute('vactory_dashboard.translations.import_front')->toString(),
            'editPath' => Url::fromRoute('vactory_dashboard.translations.edit')->toString(),
          ],
        ],
      ],
    ];
  }

  /**
   * Returns translations data with pagination.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response with translations data.
   */
  public function getTranslations(Request $request) {
    $search = $request->query->get('search', '');
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = (int) $request->query->get('limit', 10);
    $nx_only = (bool) $request->query->get('nx_only', FALSE);
    $offset = ($page - 1) * $limit;

    // Get total count first
    $countQuery = $this->database->select('locales_source', 'ls');
    $countQuery->addExpression('COUNT(DISTINCT ls.lid)', 'count');

    if (!empty($search)) {
      $countQuery->condition('ls.source', '%' . $this->database->escapeLike($search) . '%', 'LIKE');
    }

    if ($nx_only) {
      $countQuery->condition('ls.source', '%' . $this->database->escapeLike('Nx') . '%', 'LIKE');
    }

    $total = $countQuery->execute()->fetchField();

    // Get paginated translations
    $query = $this->database->select('locales_source', 'ls');
    $query->fields('ls', ['source', 'context', 'lid']);

    if (!empty($search)) {
      $query->condition('ls.source', '%' . $this->database->escapeLike($search) . '%', 'LIKE');
    }

    if ($nx_only) {
      $query->condition('ls.source', '%' . $this->database->escapeLike('Nx') . '%', 'LIKE');
    }

    $query->orderBy('ls.lid', 'DESC');
    $query->range($offset, $limit);
    $sources = $query->execute()->fetchAll();

    // Get translations for the fetched sources
    $translations = [];
    if (!empty($sources)) {
      $lids = array_map(function($source) {
        return $source->lid;
      }, $sources);

      $translationsQuery = $this->database->select('locales_target', 'lt');
      $translationsQuery->fields('lt', ['lid', 'language', 'translation']);
      $translationsQuery->condition('lt.lid', $lids, 'IN');
      $translationResults = $translationsQuery->execute()->fetchAll();

      // Group translations by lid
      $translationsByLid = [];
      foreach ($translationResults as $translation) {
        $translationsByLid[$translation->lid][$translation->language] = $translation->translation;
      }

      // Format the data
      foreach ($sources as $source) {
        $translations[] = [
          'source' => $source->source,
          'context' => $source->context,
          'translations' => $translationsByLid[$source->lid] ?? [],
        ];
      }
    }

    return new JsonResponse([
      'data' => $translations,
      'total' => $total,
      'page' => $page,
      'limit' => $limit,
      'pages' => ceil($total / $limit),
    ]);
  }

  /**
   * Adds a new translation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function addTranslation(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    // Add the translation using Drupal's locale system.
    $source = $data['source'];
    $translations = $data['translations'];

    // First, add the source string.
    $lid = $this->database->insert('locales_source')
      ->fields([
        'source' => $source,
        'context' => '',
        'version' => '',
      ])
      ->execute();

    // Then add translations for each language.
    foreach ($translations as $langcode => $translation) {
      $this->database->insert('locales_target')
        ->fields([
          'lid' => $lid,
          'language' => $langcode,
          'translation' => $translation,
          'customized' => 1,
        ])
        ->execute();
    }

    return new JsonResponse(['message' => 'Translation added successfully']);
  }

  /**
   * Deletes a translation.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function deleteTranslation(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    // Delete the translation using Drupal's locale system.
    $source = $data['source'];
    $context = $data['context'] ?? '';

    $lid = $this->database->select('locales_source', 'ls')
      ->fields('ls', ['lid'])
      ->condition('source', $source)
      ->condition('context', $context)
      ->execute()
      ->fetchField();

    if ($lid) {
      // Delete translations
      $this->database->delete('locales_target')
        ->condition('lid', $lid)
        ->execute();

      // Delete source
      $this->database->delete('locales_source')
        ->condition('lid', $lid)
        ->execute();
    }

    return new JsonResponse(['message' => 'Translation deleted successfully']);
  }

  /**
   * Deletes multiple translations in bulk.
   *
   * This method expects a JSON payload containing an array of terms to delete.
   * Each term should have a 'source' and optionally a 'context'.
   * It deletes entries from both 'locales_source' and 'locales_target' tables.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing the JSON payload.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function deleteTranslationsBulk(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    if (!is_array($data)) {
      return new JsonResponse(['message' => 'Invalid input'], 400);
    }

    if (!isset($data['selectedTerms']) || !is_array($data['selectedTerms'])) {
      return new JsonResponse(['message' => 'Invalid input'], 400);
    }

    foreach ($data['selectedTerms'] as $term) {
      $source = $term['source'] ?? NULL;
      $context = $term['context'] ?? '';

      if (!$source) {
        continue;
      }

      $lid = $this->database->select('locales_source', 'ls')
        ->fields('ls', ['lid'])
        ->condition('source', $source)
        ->condition('context', $context)
        ->execute()
        ->fetchField();

      if ($lid) {
        $this->database->delete('locales_target')
          ->condition('lid', $lid)
          ->execute();

        $this->database->delete('locales_source')
          ->condition('lid', $lid)
          ->execute();
      }
    }

    return new JsonResponse(['message' => 'Translations deleted successfully']);
  }

  /**
   * Edits translations for a given source and context.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing the source, context, and translations.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response indicating success or failure.
   */
  public function editTranslation(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    $source = $data['source'];
    $context = $data['context'];
    $translations = $data['translations'];

    $lid = $this->database->select('locales_source', 'ls')
      ->fields('ls', ['lid'])
      ->condition('source', $source)
      ->condition('context', $context)
      ->execute()
      ->fetchField();

    if (!$lid) {
      return new JsonResponse(['message' => 'Source string not found'], 404);
    }

    foreach ($translations as $langcode => $translation) {
      $exists = $this->database->select('locales_target', 'lt')
        ->fields('lt', ['language'])
        ->condition('lid', $lid)
        ->condition('language', $langcode)
        ->execute()
        ->fetchField();

      if ($exists) {
        $this->database->update('locales_target')
          ->fields([
            'translation' => $translation,
            'customized' => 1,
          ])
          ->condition('lid', $lid)
          ->condition('language', $langcode)
          ->execute();
      }
      else {
        $this->database->insert('locales_target')
          ->fields([
            'lid' => $lid,
            'language' => $langcode,
            'translation' => $translation,
            'customized' => 1,
          ])
          ->execute();
      }
    }

    return new JsonResponse(['message' => 'Translations updated successfully']);
  }

  /**
   * Gets the list of back office languages.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing language codes and names.
   */
  public function getSiteLanguages() {
    $languages = $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE);

    // Get enabled languages from dashboard configuration
    $config = \Drupal::config('vactory_dashboard.global.settings');
    $enabled_languages = $config->get('dashboard_languages') ?? [];
    $enabled_languages = array_filter($enabled_languages);

    $lang_codes = [];
    $lang_names = [];

    foreach ($languages as $langcode => $language) {
      // Only include languages that are enabled in dashboard config
      if (empty($enabled_languages) || isset($enabled_languages[$langcode])) {
        $lang_codes[] = $langcode;
        $lang_names[] = $language->getName();
      }
    }

    return new JsonResponse([
      'lang_codes' => $lang_codes,
      'lang_names' => $lang_names,
    ]);
  }

  /**
   * Imports a list of keywords from a string input.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object containing a newline-separated keywords string.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response indicating the number of imported keywords.
   */
  public function importKeywords(Request $request) {
    $data = json_decode($request->getContent(), TRUE);

    if (!isset($data['keywords']) || !is_string($data['keywords'])) {
      return new JsonResponse(['error' => 'Invalid input. Expecting "keywords" as string.'], 400);
    }

    $input_keywords = $data['keywords'];
    $keywords = explode("\n", $input_keywords);

    $imported = 0;
    foreach ($keywords as $keyword) {
      $keyword = trim($keyword);
      if (!empty($keyword)) {
        $this->translationService->createString($keyword);
        $imported++;
      }
    }

    return new JsonResponse(['status' => 'ok', 'imported' => $imported]);
  }

}
