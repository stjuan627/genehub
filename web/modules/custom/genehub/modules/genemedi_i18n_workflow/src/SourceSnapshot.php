<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Captures source fields and checks mechanically verifiable invariants.
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
        $eligible = $eligible && $name === 'body';
      }
      if (!$eligible) {
        // Node titles provide context but are outside the body-only output.
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
   * Rejects changed schema, layout, protected values, and numeric facts.
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
          if ($value === NULL || $value === '') {
            if ($target !== $value) {
              throw new \InvalidArgumentException("An empty source value changed at $position.");
            }
            continue;
          }
          if (!is_string($target) || trim($target) === '') {
            throw new \InvalidArgumentException("A nonempty source value is missing at $position.");
          }
          $html = in_array($property, $mapping[$field]['html_properties'], TRUE);
          if ($html) {
            if ($this->htmlStructure((string) $value) !== $this->htmlStructure($target)) {
              throw new \InvalidArgumentException("HTML structure or attributes changed at $position.");
            }
          }
          // Check each DOM text position separately, not only the whole field.
          $original_parts = $html ? $this->htmlTextParts((string) $value) : [(string) $value];
          $target_parts = $html ? $this->htmlTextParts($target) : [$target];
          // A value and its unit may be separated by inline HTML elements.
          if ($this->numericFacts(implode(' ', $original_parts)) !== $this->numericFacts(implode(' ', $target_parts))) {
            throw new \InvalidArgumentException("Numeric facts or units changed at $position.");
          }
          foreach ($original_parts as $index => $part) {
            if ($this->numericFacts($part) !== $this->numericFacts($target_parts[$index] ?? '')) {
              throw new \InvalidArgumentException("Numeric facts or units changed at $position.");
            }
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

  /**
   * Gets a canonical element tree, preserving text positions and attributes.
   */
  private function htmlStructure(string $html): array {
    $walk = function (\DOMNode $node) use (&$walk): ?array {
      if ($node instanceof \DOMText) {
        return trim($node->textContent) === '' ? NULL : ['text'];
      }
      if ($node instanceof \DOMComment) {
        return ['comment', $node->nodeValue];
      }
      $attributes = [];
      if ($node instanceof \DOMElement) {
        foreach ($node->attributes as $attribute) {
          $attributes[$attribute->nodeName] = $attribute->nodeValue;
        }
        ksort($attributes);
        if (in_array(strtolower($node->tagName), ['script', 'style'], TRUE)) {
          return [$node->nodeName, $attributes, $node->textContent];
        }
      }
      $children = [];
      foreach ($node->childNodes as $child) {
        $value = $walk($child);
        if ($value !== NULL) {
          $children[] = $value;
        }
      }
      return [$node->nodeName, $attributes, $children];
    };
    return $walk(Html::load($html));
  }

  /**
   * Extracts visible text nodes in document order for per-position checks.
   */
  private function htmlTextParts(string $html): array {
    $parts = [];
    $walk = function (\DOMNode $node) use (&$walk, &$parts): void {
      if ($node instanceof \DOMText && trim($node->textContent) !== '') {
        $parts[] = $node->textContent;
      }
      foreach ($node->childNodes as $child) {
        $walk($child);
      }
    };
    $walk(Html::load($html));
    return $parts;
  }

  /**
   * Preserves numeric tokens, comparison signs, and common scientific units.
   *
   * Worded quantities and qualifiers still need semantic review by the agent.
   */
  private function numericFacts(string $text): array {
    $number = '(?:[<>≤≥≈~]=?\s*)?[+\-−]?\d+(?:[.,]\d+)*(?:[eE][+\-]?\d+)?';
    $unit = '(?:%|‰|°\s*[CFK]|[kmunpµμ]?[gGLlMm]|mM|µM|μM|nM|pM|mol|mmol|µmol|μmol|nmol|rpm|×\s*g|IU|U|ms|min|sec|s|h)(?![\p{L}])';
    $units = $unit . '(?:\s*\/\s*' . $unit . ')*';
    preg_match_all('/' . $number . '(?:\s*' . $units . ')?/u', $text, $matches);
    return array_map(static fn(string $value): string => preg_replace('/\s+/u', '', $value), $matches[0]);
  }

}
