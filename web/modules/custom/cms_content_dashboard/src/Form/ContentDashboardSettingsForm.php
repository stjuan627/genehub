<?php

declare(strict_types=1);

namespace Drupal\cms_content_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for the Content Dashboard module.
 */
class ContentDashboardSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'cms_content_dashboard_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['cms_content_dashboard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('cms_content_dashboard.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Content Dashboard API and tracking'),
      '#default_value' => $config->get('enabled') ?? TRUE,
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key Token'),
      '#description' => $this->t('The secret token expected in the X-API-KEY request header.'),
      '#default_value' => $config->get('api_key') ?? '',
      '#required' => TRUE,
    ];

    $form['thresholds'] = [
      '#type' => 'details',
      '#title' => $this->t('Major Change Thresholds'),
      '#open' => TRUE,
    ];

    $form['thresholds']['body_diff_ratio_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Body modification ratio threshold'),
      '#description' => $this->t('Modifications equal to or greater than this ratio (e.g. 0.15 = 15%) are classified as major.'),
      '#default_value' => $config->get('body_diff_ratio_threshold') ?? 0.15,
      '#step' => 0.01,
      '#min' => 0.01,
      '#max' => 1.0,
      '#required' => TRUE,
    ];

    $form['thresholds']['body_diff_words_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Body words changed threshold'),
      '#description' => $this->t('Modifications with changed words (added + deleted) equal to or greater than this count are classified as major.'),
      '#default_value' => $config->get('body_diff_words_threshold') ?? 50,
      '#min' => 1,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('cms_content_dashboard.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('api_key', trim((string) $form_state->getValue('api_key')))
      ->set('body_diff_ratio_threshold', (float) $form_state->getValue('body_diff_ratio_threshold'))
      ->set('body_diff_words_threshold', (int) $form_state->getValue('body_diff_words_threshold'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
