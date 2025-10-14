<?php

/**
 * @file
 * Hooks provided by the search_api_opensearch module.
 */

use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api_opensearch_semantic\Plugin\search_api\data_type\Semantic;

/**
 * Process the values of generated in IndexParamBuilder service.
 *
 * @param \Drupal\search_api\Item\FieldInterface $field
 *   Search api index field.
 * @param array $original_values
 *   Array of original field values before the update.
 * @param array $context
 *   Additional context information.
 */
function hook_index_param_value_alter(FieldInterface $field, array &$original_values, array $context): void {
  if ($context['field_type'] !== Semantic::PLUGIN_ID) {
    return;
  }
  \reset($original_values);
}
