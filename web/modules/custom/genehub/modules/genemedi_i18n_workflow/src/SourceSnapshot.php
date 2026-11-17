<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Captures source fields and validates field contracts and protected values.
 *
 * Semantic completeness still requires the agent's source-to-result review.
 */
final class SourceSnapshot {

  /**
   * Captures localizable fields and immutable product context.
   */
  public function capture(ContentEntityInterface $entity, string $sourceLang): array {
    if (!$entity->hasTranslation($sourceLang)) {
      throw new \InvalidArgumentException('The source translation does not exist.');
    }
    $source = $entity->getTranslation($sourceLang);
    $type = $source->getEntityTypeId();
    if (!(($type === 'node' && $source->bundle() === 'page') || str_starts_with($type, 'product_'))) {
      throw new \InvalidArgumentException('Only Basic pages and GeneHub product entities are supported.');
    }

    $fields = $mapping = $context = [];
    $metadata = [
      'id', 'uuid', 'langcode', 'default_langcode', 'revision_id', 'vid',
      'revision_created', 'revision_timestamp', 'revision_uid', 'revision_log',
      'revision_translation_affected', 'revision_default', 'status', 'uid',
      'created', 'changed', 'moderation_state', 'content_translation_source',
      'content_translation_outdated', 'content_translation_uid',
      'content_translation_created', 'content_translation_status',
    ];
    foreach ($source->getEntityType()->getKeys() as $key) {
      if ($key && $key !== $source->getEntityType()->getKey('label')) {
        $metadata[] = $key;
      }
    }
    foreach ($source->getFieldDefinitions() as $name => $definition) {
      if ($definition->isComputed() || in_array($name, $metadata, TRUE)) {
        continue;
      }
      $field_type = $definition->getType();
      $properties = match ($field_type) {
        'string', 'string_long', 'text', 'text_long' => ['value'],
        'text_with_summary' => ['value', 'summary'],
        'genehub_section' => ['heading', 'body'],
        'genehub_sales_unit' => ['label'],
        default => [],
      };
      $value = $source->get($name)->getValue();
      $eligible = $definition->isTranslatable() && !$definition->isReadOnly() && $properties;
      if ($type === 'node') {
        $eligible = $eligible && in_array($name, ['title', 'body', 'field_meta_description'], TRUE);
      }
      if (!$eligible) {
        // Other node fields remain context outside the localization scope.
        $context[$name] = $value;
        continue;
      }
      $fields[$name] = $value;
      $mapping[$name] = [
        'type' => $field_type,
        'translatable_properties' => $properties,
        'html_properties' => match ($field_type) {
          'text', 'text_long' => ['value'],
          'text_with_summary' => ['value', 'summary'],
          'genehub_section' => ['body'],
          default => [],
        },
      ];
    }
    if (!$fields) {
      throw new \InvalidArgumentException('No supported translatable text fields were found.');
    }
    return [
      'entity_type' => $type,
      'entity_id' => (string) $source->id(),
      'uuid' => $source->uuid(),
      'bundle' => $source->bundle(),
      'source_lang' => $sourceLang,
      'source_revision' => $source->getEntityType()->isRevisionable() ? $source->getRevisionId() : NULL,
      'source_hash' => hash('sha256', $this->encodeCanonical([$fields, $context, $mapping])),
      'fields' => $fields,
      'mapping' => $mapping,
      'context' => $context,
    ];
  }

  /**
   * Validates field shapes, nullable text values and immutable properties.
   */
  public function validate(array $snapshot, array $translatedFields): void {
    $fields = $snapshot['fields'] ?? NULL;
    $mapping = $snapshot['mapping'] ?? NULL;
    if (!is_array($fields) || !is_array($mapping) || !$this->sameKeys($fields, $translatedFields)) {
      throw new \InvalidArgumentException('The result must contain exactly the source fields.');
    }
    foreach ($fields as $field => $original_items) {
      $items = $translatedFields[$field];
      if (!is_array($items) || !array_is_list($items) || count($items) !== count($original_items)) {
        throw new \InvalidArgumentException("The item count or order container changed for $field.");
      }
      if (!isset($mapping[$field]['translatable_properties'], $mapping[$field]['html_properties'])) {
        throw new \InvalidArgumentException("The field mapping is missing for $field.");
      }
      foreach ($original_items as $delta => $original) {
        $item = $items[$delta];
        if (!is_array($item) || !$this->sameKeys($original, $item)) {
          throw new \InvalidArgumentException("The field properties changed for $field:$delta.");
        }
        foreach ($original as $property => $value) {
          $target = $item[$property];
          $position = "$field:$delta:$property";
          if (!in_array($property, $mapping[$field]['translatable_properties'], TRUE)) {
            if ($target !== $value) {
              throw new \InvalidArgumentException("A protected value changed at $position.");
            }
            continue;
          }
          if (!is_string($target) && $target !== NULL) {
            throw new \InvalidArgumentException("A localized value must be text or null at $position.");
          }
        }
      }
    }
  }

  /**
   * Compares associative key sets independently of JSON key ordering.
   */
  private function sameKeys(array $left, array $right): bool {
    $left = array_keys($left);
    $right = array_keys($right);
    sort($left);
    sort($right);
    return $left === $right;
  }

  /**
   * Encodes associative maps with deterministic ordering.
   */
  private function encodeCanonical(array $values): string {
    $normalize = function (array $array) use (&$normalize): array {
      if (!array_is_list($array)) {
        ksort($array);
      }
      foreach ($array as &$value) {
        if (is_array($value)) {
          $value = $normalize($value);
        }
      }
      return $array;
    };
    return json_encode($normalize($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

}
