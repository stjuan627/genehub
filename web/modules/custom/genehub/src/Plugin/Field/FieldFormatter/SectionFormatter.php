<?php

declare(strict_types=1);

namespace Drupal\genehub\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the GeneHub section formatter.
 */
#[FieldFormatter(
  id: 'genehub_section_default',
  label: new TranslatableMarkup('GeneHub section'),
  field_types: ['genehub_section'],
)]
final class SectionFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($items as $delta => $item) {
      $heading = trim((string) ($item->heading ?? ''));
      $body = (string) ($item->body ?? '');

      $elements[$delta] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['genehub-section'],
        ],
      ];

      if ($heading !== '') {
        $elements[$delta]['heading'] = [
          '#type' => 'html_tag',
          '#tag' => 'h2',
          '#value' => Html::escape($heading),
          '#attributes' => [
            'class' => ['genehub-section__heading'],
          ],
        ];
      }

      if ($body !== '') {
        $elements[$delta]['body'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['genehub-section__body'],
          ],
          'content' => [
            '#type' => 'processed_text',
            '#text' => $body,
            '#format' => $item->format,
            '#langcode' => $item->getLangcode(),
          ],
        ];
      }
    }

    return $elements;
  }

}
