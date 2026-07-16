<?php

declare(strict_types=1);

namespace Drupal\genehub\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the sales unit widget.
 */
#[FieldWidget(
  id: 'genehub_sales_unit_default',
  label: new TranslatableMarkup('Sales unit'),
  field_types: ['genehub_sales_unit'],
)]
final class SalesUnitWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $item = $items[$delta];
    $sku = $this->resolveSku($form, $form_state, $delta, $item->sku ?? '');
    $element['#type'] = 'details';
    $element['#title'] = $sku !== ''
      ? $this->t('SKU: @sku', ['@sku' => $sku])
      : $this->t('Sales option @number', ['@number' => $delta + 1]);
    $element['#open'] = $sku === '';

    $element['sku'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SKU'),
      '#default_value' => $item->sku ?? '',
      '#maxlength' => 256,
      '#size' => 40,
    ];

    $element['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $item->label ?? '',
      '#maxlength' => 255,
      '#size' => 40,
    ];

    $element['size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Size'),
      '#default_value' => $item->size ?? '',
      '#maxlength' => 255,
      '#size' => 24,
    ];

    $element['unit'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Unit'),
      '#default_value' => $item->unit ?? '',
      '#maxlength' => 255,
      '#size' => 24,
    ];

    $element['price'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Price'),
      '#default_value' => $item->price ?? '',
      '#maxlength' => 255,
      '#size' => 24,
    ];

    return $element;
  }

  /**
   * Resolves the current SKU for the details title.
   */
  private function resolveSku(array $form, FormStateInterface $form_state, int $delta, mixed $default): string {
    $path = array_merge($form['#parents'], [
      $this->fieldDefinition->getName(),
      $delta,
      'sku',
    ]);

    $input = NestedArray::getValue($form_state->getUserInput(), $path, $input_exists);
    if ($input_exists && is_scalar($input)) {
      return trim((string) $input);
    }

    $values = NestedArray::getValue($form_state->getValues(), $path, $value_exists);
    if ($value_exists && is_scalar($values)) {
      return trim((string) $values);
    }

    return trim((string) $default);
  }

}
