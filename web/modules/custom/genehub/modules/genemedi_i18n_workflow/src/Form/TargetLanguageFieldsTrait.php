<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Shares target language and market controls between queue entry points.
 */
trait TargetLanguageFieldsTrait {

  /**
   * Adds target language checkboxes and their explicit market inputs.
   */
  protected function addTargetLanguageFields(array &$form, array $options): void {
    $form['target_languages'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Target languages'),
      '#options' => $options,
      '#description' => $this->t('Select at least one language different from the source language.'),
    ];
    $defaults = ['en' => 'US', 'zh-hans' => 'CN', 'ja' => 'JP', 'fr' => 'FR', 'ko' => 'KR', 'ru' => 'RU'];
    $form['markets'] = [
      '#type' => 'details',
      '#title' => $this->t('Target markets'),
      '#description' => $this->t('Confirm the intended market for each selected language. Language alone does not determine the market.'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    foreach ($options as $code => $label) {
      $form['markets'][$code] = [
        '#type' => 'textfield',
        '#title' => $this->t('@language market', ['@language' => $label]),
        '#default_value' => $defaults[$code] ?? '',
        '#maxlength' => 64,
        '#size' => 20,
        '#states' => ['visible' => [':input[name="target_languages[' . $code . ']"]' => ['checked' => TRUE]]],
      ];
    }
  }

  /**
   * Validates selected language and market pairs against the source language.
   */
  protected function validateTargetLanguages(FormStateInterface $form_state): array {
    $source = (string) $form_state->getValue('source_lang');
    $targets = [];
    foreach (array_filter($form_state->getValue('target_languages', [])) as $code) {
      if ($code === $source) {
        $form_state->setErrorByName('target_languages', $this->t('Target languages must differ from the source language.'));
      }
      $market = trim((string) $form_state->getValue(['markets', $code]));
      if ($market === '') {
        $form_state->setErrorByName('markets][' . $code, $this->t('Enter the market for @language.', [
          '@language' => $code,
        ]));
      }
      $targets[] = [
        'langcode' => $code,
        'market' => $market,
        'regenerate' => (bool) $form_state->getValue('regenerate'),
      ];
    }
    if (!$targets) {
      $form_state->setErrorByName('target_languages', $this->t('Select at least one target language.'));
    }
    return $targets;
  }

}
