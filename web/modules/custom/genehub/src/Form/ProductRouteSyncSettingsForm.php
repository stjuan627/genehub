<?php

declare(strict_types=1);

namespace Drupal\genehub\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures synchronization of product routes to the legacy site.
 */
final class ProductRouteSyncSettingsForm extends ConfigFormBase {

  private const CONFIG_NAME = 'genehub.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'genehub_product_route_sync_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = $this->config(self::CONFIG_NAME)->get('product_route_sync');
    $settings = is_array($settings) ? $settings : [];
    $has_password = (string) ($settings['password'] ?? '') !== '';

    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#default_value' => (string) ($settings['api_url'] ?? ''),
      '#required' => TRUE,
      '#maxlength' => 2048,
    ];
    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Basic Auth username'),
      '#default_value' => (string) ($settings['username'] ?? ''),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#autocomplete' => 'off',
    ];
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Basic Auth password'),
      '#required' => !$has_password,
      '#description' => $has_password
        ? $this->t('A password is configured. Leave this field blank to keep it.')
        : $this->t('Enter the password used by the legacy route API.'),
      '#attributes' => [
        'autocomplete' => 'new-password',
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $api_url = trim((string) $form_state->getValue('api_url'));
    $scheme = strtolower((string) parse_url($api_url, PHP_URL_SCHEME));
    if (!UrlHelper::isValid($api_url, TRUE) || !in_array($scheme, ['http', 'https'], TRUE)) {
      $form_state->setErrorByName('api_url', $this->t('Enter a valid absolute HTTP or HTTPS URL.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory->getEditable(self::CONFIG_NAME)
      ->set('product_route_sync.api_url', trim((string) $form_state->getValue('api_url')))
      ->set('product_route_sync.username', trim((string) $form_state->getValue('username')));

    $password = (string) $form_state->getValue('password');
    if ($password !== '') {
      $config->set('product_route_sync.password', $password);
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
