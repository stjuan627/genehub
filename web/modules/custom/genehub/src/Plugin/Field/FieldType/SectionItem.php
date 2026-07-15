<?php

declare(strict_types=1);

namespace Drupal\genehub\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\text\TextProcessed;

/**
 * Defines a reusable GeneHub section field item.
 */
#[FieldType(
  id: 'genehub_section',
  label: new TranslatableMarkup('GeneHub section'),
  description: new TranslatableMarkup('Stores a section heading and formatted HTML body.'),
  default_widget: 'genehub_section_default',
  default_formatter: 'genehub_section_default',
)]
final class SectionItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultFieldSettings(): array {
    return ['allowed_formats' => []] + parent::defaultFieldSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function fieldSettingsForm(array $form, FormStateInterface $form_state): array {
    $element = parent::fieldSettingsForm($form, $form_state);
    $allowed_formats = $this->getSetting('allowed_formats');

    $element['allowed_formats'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Allowed text formats'),
      '#options' => $this->get('format')->getPossibleOptions(),
      '#default_value' => $allowed_formats ?: [],
      '#description' => $this->t('If none are selected, all text formats available to the editor are allowed.'),
      '#element_validate' => [[static::class, 'validateAllowedFormats']],
    ];

    return $element;
  }

  /**
   * Normalizes the allowed text format setting.
   */
  public static function validateAllowedFormats(array &$element, FormStateInterface $form_state): void {
    $value = array_values(array_filter($form_state->getValue($element['#parents'])));
    $form_state->setValueForElement($element, $value);
  }

  /**
   * {@inheritdoc}
   */
  public static function calculateDependencies(FieldDefinitionInterface $field_definition): array {
    $dependencies = parent::calculateDependencies($field_definition);
    $allowed_formats = $field_definition->getSetting('allowed_formats') ?? [];

    foreach ($allowed_formats as $format_id) {
      $dependencies['config'][] = 'filter.format.' . $format_id;
    }

    if (isset($dependencies['config'])) {
      $dependencies['config'] = array_values(array_unique($dependencies['config']));
    }

    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties['heading'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Section heading'));

    $properties['body'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Section body'));

    $properties['format'] = DataDefinition::create('filter_format')
      ->setLabel(new TranslatableMarkup('Text format'))
      ->setSetting('allowed_formats', $field_definition->getSetting('allowed_formats'));

    $properties['processed'] = DataDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Processed section body'))
      ->setDescription(new TranslatableMarkup('The section body with its text format applied.'))
      ->setComputed(TRUE)
      ->setClass(TextProcessed::class)
      ->setSetting('text source', 'body')
      ->setInternal(FALSE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return [
      'columns' => [
        'heading' => [
          'description' => 'The section heading.',
          'type' => 'varchar',
          'length' => 255,
        ],
        'body' => [
          'description' => 'The unprocessed section body.',
          'type' => 'text',
          'size' => 'big',
        ],
        'format' => [
          'description' => 'The text format used by the section body.',
          'type' => 'varchar_ascii',
          'length' => 255,
        ],
      ],
      'indexes' => [
        'format' => ['format'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName(): string {
    return 'body';
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE): static {
    $this->setValue(['format' => NULL], $notify);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    $heading = $this->get('heading')->getValue();
    $body = $this->get('body')->getValue();

    return trim((string) $heading) === '' && trim((string) $body) === '';
  }

  /**
   * {@inheritdoc}
   */
  public function onChange($property_name, $notify = TRUE): void {
    if ($property_name === 'body' || $property_name === 'format') {
      $this->writePropertyValue('processed', NULL);
    }

    parent::onChange($property_name, $notify);
  }

}
