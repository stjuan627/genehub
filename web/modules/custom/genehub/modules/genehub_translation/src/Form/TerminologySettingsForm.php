<?php

declare(strict_types=1);

namespace Drupal\genehub_translation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\genehub_translation\TerminologyManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures GeneHub English-to-Chinese terminology.
 */
final class TerminologySettingsForm extends ConfigFormBase {

  /**
   * The terminology parser and provider.
   */
  private TerminologyManager $terminologyManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->terminologyManager = $container->get('genehub_translation.terminology');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'genehub_translation_terminology_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [TerminologyManager::CONFIG_NAME];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $value = $this->terminologyManager->getTerminologyText();
    $parsed = $this->terminologyManager->parse($value);

    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Current rules'),
      '#markup' => $this->t('@terms protected English terms and @normalizations Chinese output normalization aliases.', [
        '@terms' => count($parsed['terms']),
        '@normalizations' => count($parsed['normalizations']),
      ]),
    ];
    $form['terminology'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Terminology CSV'),
      '#default_value' => $value,
      '#rows' => 32,
      '#required' => TRUE,
      '#description' => $this->t('Keep the header source_term,target_term. A non-Chinese source is protected during English-to-Chinese translation. A Chinese source is treated as an output alias and normalized to its target. Standard CSV quoting is supported for values containing commas.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    try {
      $this->terminologyManager->parse((string) $form_state->getValue('terminology'));
    }
    catch (\InvalidArgumentException $exception) {
      $form_state->setErrorByName('terminology', $exception->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $value = str_replace(["\r\n", "\r"], "\n", (string) $form_state->getValue('terminology'));
    $this->configFactory->getEditable(TerminologyManager::CONFIG_NAME)
      ->set('terminology', rtrim($value) . "\n")
      ->save();
    parent::submitForm($form, $form_state);
  }

}
