<?php

declare(strict_types=1);

namespace Drupal\genehub_solidex\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the sales unit field type.
 */
#[FieldType(
  id: 'genehub_sales_unit',
  label: new TranslatableMarkup('GeneHub sales unit'),
  description: new TranslatableMarkup('Stores a product sales unit with SKU, label, size, unit, and price.'),
  default_widget: 'genehub_sales_unit_default',
  default_formatter: 'genehub_sales_unit_default',
)]
final class SalesUnitItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['sku'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('SKU'));

    $properties['label'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'));

    $properties['size'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Size'));

    $properties['unit'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Unit'));

    $properties['price'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Price'));

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'sku' => [
          'description' => 'The sales unit SKU.',
          'type' => 'varchar',
          'length' => 256,
        ],
        'label' => [
          'description' => 'The sales unit label.',
          'type' => 'varchar',
          'length' => 255,
        ],
        'size' => [
          'description' => 'The sales unit size.',
          'type' => 'varchar',
          'length' => 255,
        ],
        'unit' => [
          'description' => 'The sales unit unit.',
          'type' => 'varchar',
          'length' => 255,
        ],
        'price' => [
          'description' => 'The display price for the sales unit.',
          'type' => 'varchar',
          'length' => 255,
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): ?string {
    return 'sku';
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    foreach (['sku', 'label', 'size', 'unit', 'price'] as $property) {
      $value = $this->get($property)->getValue();
      if ($value !== NULL && $value !== '') {
        return FALSE;
      }
    }

    return TRUE;
  }

}
