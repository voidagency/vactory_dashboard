<?php

namespace Drupal\vactory_dashboard\Service;

use Drupal\Core\Database\Connection;
use Drupal\webform\Entity\WebformSubmission;

/**
 * Provides helper methods to search and retrieve Webform submissions.
 */
class FormSearchService {

  /**
   * The database connection.
   *
   * Used for executing low-level queries on submission data.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new FormSearchService instance.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Retrieves matching webform submission IDs based on search criteria.
   *
   * @param string $webform_id
   *   The ID of the webform.
   * @param string $q
   *   The search query string.
   * @param array $keys
   *   The list of submission element keys to search in.
   * @param string $operator
   *   The search operator. Supported: 'CONTAINS', 'START_WITH'. Defaults to
   *   'CONTAINS'.
   * @param int|null $limit
   *   Optional limit on the number of results.
   *
   * @return array
   *   An array of matching submission IDs.
   */
  public function getMatchingSubmissionIds(string $webform_id, string $q, array $keys, string $operator = 'CONTAINS', $limit = NULL): array {
    $query = $this->database->select('webform_submission_data', 'wsd')
      ->fields('wsd', ['sid'])
      ->condition('wsd.webform_id', $webform_id)
      ->condition('wsd.name', $keys, 'IN')
      ->condition('wsd.value', ($operator === 'START_WITH') ? "$q%" : "%$q%", 'LIKE')
      ->distinct();

    if ($limit !== NULL) {
      $query->range(0, (int) $limit);
    }

    return $query->execute()->fetchCol();
  }

  /**
   * Retrieves webform submissions by their IDs.
   *
   * @param array $ids
   *   An array of submission IDs.
   * @param string $formID
   *   The ID of the webform.
   *
   * @return array
   *   An array of submission data.
   */
  public function getSubmissionsByIds(array $ids, string $formID): array {
    $data = [];

    $ids = array_filter(array_map('intval', $ids));
    if (empty($ids)) {
      return $data;
    }

    $result = \Drupal::entityQuery('webform_submission')
      ->accessCheck(FALSE)
      ->condition('webform_id', $formID)
      ->condition('sid', $ids, 'IN')
      ->execute();

    foreach ($result as $item_id) {
      $submission = WebformSubmission::load($item_id);
      if ($submission) {
        $submission_data = $submission->getData();
        $data[] = [
          'id' => $submission->id(),
          'webform_id' => $submission->getWebform()->id(),
          'created' => $submission->getCreatedTime(),
          'completed' => $submission->getCompletedTime(),
          'remote_addr' => $submission->getRemoteAddr(),
          'uid' => $submission->getOwnerId(),
          'data' => $submission_data,
        ];
      }
    }

    return $data;
  }

}
