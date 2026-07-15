<?php

declare(strict_types=1);

namespace Drupal\genehub\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Plugin implementation of the GeneHub section widget.
 */
#[FieldWidget(
  id: 'genehub_section_default',
  label: new TranslatableMarkup('GeneHub section'),
  field_types: ['genehub_section'],
)]
final class SectionWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $item = $items[$delta];
    $heading = $this->resolveHeading($form, $form_state, $delta, $item->heading ?? '');
    $body = (string) ($item->body ?? '');
    $format = (string) ($item->format ?? '');

    $element['#type'] = 'details';
    $element['#title'] = $heading !== ''
      ? $heading
      : $this->t('Section @number', ['@number' => $delta + 1]);
    $element['#open'] = $heading === '' && $body === '';

    $element['heading'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Section heading'),
      '#default_value' => $item->heading ?? '',
      '#maxlength' => 255,
      '#size' => 60,
    ];

    $element['body'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Section body'),
      '#default_value' => $body,
      '#format' => $format !== '' ? $format : filter_fallback_format(),
      '#rows' => 10,
    ];

    $allowed_formats = $this->getFieldSetting('allowed_formats');
    if ($allowed_formats) {
      $element['body']['#allowed_formats'] = $allowed_formats;
    }

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as &$value) {
      if (!isset($value['body']) || !is_array($value['body'])) {
        continue;
      }

      $body = $value['body'];
      $value['body'] = $body['value'] ?? '';
      $value['format'] = $body['format'] ?? NULL;
    }

    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function errorElement(array $element, ConstraintViolationInterface $violation, array $form, FormStateInterface $form_state): array|bool {
    $property = strrchr($violation->getPropertyPath(), '.');

    return match ($property) {
      '.heading' => $element['heading'] ?? $element,
      '.body' => $element['body']['value'] ?? $element['body'] ?? $element,
      '.format' => $element['body']['format'] ?? $element['body'] ?? $element,
      default => $element,
    };
  }

  /**
   * Resolves the current heading for the details title.
   */
  private function resolveHeading(array $form, FormStateInterface $form_state, int $delta, mixed $default): string {
    $path = array_merge($form['#parents'], [
      $this->fieldDefinition->getName(),
      $delta,
      'heading',
    ]);

    $input = NestedArray::getValue($form_state->getUserInput(), $path, $input_exists);
    if ($input_exists && is_scalar($input)) {
      return trim((string) $input);
    }

    $value = NestedArray::getValue($form_state->getValues(), $path, $value_exists);
    if ($value_exists && is_scalar($value)) {
      return trim((string) $value);
    }

    return trim((string) $default);
  }

}
