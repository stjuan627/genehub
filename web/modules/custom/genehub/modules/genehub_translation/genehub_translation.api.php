<?php

/**
 * @file
 * Hooks and documentation for the GeneHub Translation module.
 */

/**
 * Alter whether JSON:API auto-translation creation is allowed on PATCH.
 *
 * @param bool $allow
 *   Whether to allow auto-creating the translation. Defaults to TRUE.
 * @param array $context
 *   An associative array containing:
 *   - entity: The \Drupal\Core\Entity\ContentEntityInterface entity.
 *   - langcode: The target translation language code (e.g., 'zh-hans').
 *   - defaults: The route defaults array.
 */
function hook_genehub_translation_jsonapi_auto_translate_alter(bool &$allow, array $context): void {
  $entity = $context['entity'];
  $langcode = $context['langcode'];

  // Example: disallow auto-creating translations for a specific entity type.
  if ($entity->getEntityTypeId() === 'node' && $langcode === 'fr') {
    $allow = FALSE;
  }
}
