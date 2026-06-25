<?php

declare(strict_types=1);

namespace Drupal\genehub_solidex\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the sales unit formatter.
 */
#[FieldFormatter(
  id: 'genehub_sales_unit_default',
  label: new TranslatableMarkup('Sales unit'),
  field_types: ['genehub_sales_unit'],
)]
final class SalesUnitFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($items as $delta => $item) {
      $rows = [];

      foreach ([
        'sku' => $this->t('SKU'),
        'label' => $this->t('Label'),
        'size' => $this->t('Size'),
        'unit' => $this->t('Unit'),
        'price' => $this->t('Price'),
      ] as $property => $label) {
        $value = (string) ($item->{$property} ?? '');
        if ($value === '') {
          continue;
        }

        $rows[] = [
          'label' => [
            '#markup' => '<strong>' . $label . ':</strong>',
          ],
          'value' => [
            '#plain_text' => $value,
          ],
        ];
      }

      $elements[$delta] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['genehub-sales-unit-item'],
        ],
      ];

      if ($rows === []) {
        continue;
      }

      foreach ($rows as $index => $row) {
        $elements[$delta][$index] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['genehub-sales-unit-row'],
          ],
          'label' => $row['label'],
          'value' => $row['value'],
        ];
      }
    }

    return $elements;
  }

}
