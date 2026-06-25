<?php

declare(strict_types=1);

namespace Drupal\genehub_solidex\Plugin\Field\FieldWidget;

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

}
