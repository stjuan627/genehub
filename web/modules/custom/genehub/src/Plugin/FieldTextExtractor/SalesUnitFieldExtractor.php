<?php

declare(strict_types=1);

namespace Drupal\genehub\Plugin\FieldTextExtractor;

use Drupal\ai_translate\Attribute\FieldTextExtractor;
use Drupal\ai_translate\Plugin\FieldTextExtractor\FieldExtractorBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Extracts user-facing text from GeneHub sales unit fields.
 */
#[FieldTextExtractor(
  id: 'genehub_sales_unit',
  label: new TranslatableMarkup('GeneHub sales unit'),
  field_types: ['genehub_sales_unit'],
)]
final class SalesUnitFieldExtractor extends FieldExtractorBase {

  /**
   * {@inheritdoc}
   */
  public function getColumns(): array {
    return ['label', 'unit'];
  }

  /**
   * {@inheritdoc}
   */
  public function setValue(ContentEntityInterface $entity, string $fieldName, array $textMeta): void {
    $newValue = $entity->get($fieldName)->getValue();

    foreach ($textMeta as $delta => $singleValue) {
      unset(
        $singleValue['field_name'],
        $singleValue['field_type'],
        $singleValue['_columns'],
      );

      $newValue[$delta] = isset($newValue[$delta])
        ? array_merge($newValue[$delta], $singleValue)
        : $singleValue;
    }

    $entity->set($fieldName, $newValue);
  }

}
