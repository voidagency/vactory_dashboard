<?php

/**
 * @file
 * Hooks provided by the Vactory Dashboard module.
 */

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Alter the list of fields that should be skipped in the dashboard.
 *
 * This hook allows modules to add or remove fields from the list of fields
 * that are excluded from dashboard display.
 *
 * @param array $skipped_fields
 *   An array of field names to skip. By default, this includes technical
 *   and system fields defined in DashboardConstants::SKIPPED_FIELDS.
 * @param array $context
 *   An associative array containing contextual information:
 *   - entity_type: The entity type being processed.
 *   - bundle: The bundle being processed.
 *   - components: The form display components being processed.
 *
 * @see DashboardConstants::SKIPPED_FIELDS
 */
function hook_dashboard_form_skipped_fields_alter(array &$skipped_fields, array $context) {
  // Example: Always skip the 'internal_notes' field.
  $skipped_fields[] = 'field_internal_notes';

  // Example: Skip promotion field only for article content type.
  if ($context['entity_type'] === 'node' && $context['bundle'] === 'article') {
    $skipped_fields[] = 'promote';
  }

  // Example: Remove a field from the skip list to make it visible.
  $key = array_search('field_custom', $skipped_fields);
  if ($key !== FALSE) {
    unset($skipped_fields[$key]);
  }

  // Example: Skip all fields starting with 'temp_'.
  foreach ($context['components'] as $field_name => $component) {
    if (strpos($field_name, 'temp_') === 0) {
      $skipped_fields[] = $field_name;
    }
  }
}
