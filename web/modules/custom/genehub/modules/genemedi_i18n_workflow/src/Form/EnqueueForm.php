<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\genemedi_i18n_workflow\TaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enqueues explicit language/market pairs for an existing source entity.
 */
final class EnqueueForm extends FormBase {

  /**
   * Constructs the queue form.
   */
  public function __construct(
    protected readonly TaskManager $taskManager,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly RequestStack $workflowRequests,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('genemedi_i18n_workflow.task_manager'),
      $container->get('language_manager'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'genemedi_i18n_workflow_enqueue';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $query = $this->workflowRequests->getCurrentRequest()?->query;
    $form['description'] = [
      '#markup' => $this->t('Queue one job per target language and market. Missing target translations are initialized as unpublished drafts. Validated results automatically overwrite the selected translated fields and publish a new revision.'),
    ];
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => [
        'node' => $this->t('Basic page'),
        'product_generic' => $this->t('Generic product'),
        'product_solidex' => $this->t('SOLIDEX product'),
      ],
      '#default_value' => $query?->get('entity_type', 'node'),
      '#required' => TRUE,
    ];
    $form['entity_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity ID'),
      '#default_value' => $query?->get('entity_id', ''),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];
    $languages = [];
    foreach ($this->languageManager->getLanguages() as $code => $language) {
      $languages[$code] = $language->getName() . ' (' . $code . ')';
    }
    $form['source_lang'] = [
      '#type' => 'select',
      '#title' => $this->t('Source language'),
      '#options' => $languages,
      '#default_value' => $query?->get('source_lang', isset($languages['en']) ? 'en' : $this->languageManager->getDefaultLanguage()->getId()),
      '#required' => TRUE,
    ];
    $form['targets'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Target languages and markets'),
      '#description' => $this->t('Enter one configured language code and explicit market per line, separated by a colon; for example de:DE. Only one market may write to each CMS language per batch.'),
      '#required' => TRUE,
      '#rows' => 5,
    ];
    $form['actions']['#type'] = 'actions';
    $form['regenerate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Regenerate an existing completed task'),
      '#description' => $this->t('Create a new generation when a matching completed task already exists. Otherwise existing matching tasks are reused.'),
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue localization tasks'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $targets = [];
    $seen = [];
    $languages = $this->languageManager->getLanguages();
    foreach (preg_split('/\R/u', (string) $form_state->getValue('targets')) ?: [] as $line) {
      if (trim($line) === '') {
        continue;
      }
      $parts = array_map('trim', explode(':', $line, 2));
      if (count($parts) !== 2 || !isset($languages[$parts[0]]) || $parts[1] === '') {
        $form_state->setErrorByName('targets', $this->t('Each line must contain a configured language code and a nonempty market, for example de:DE.'));
        return;
      }
      [$language, $market] = $parts;
      if (isset($seen[$language]) || $language === $form_state->getValue('source_lang')) {
        $form_state->setErrorByName('targets', $this->t('Each target language must be unique and different from the source language.'));
        return;
      }
      $seen[$language] = TRUE;
      $targets[] = [
        'langcode' => $language,
        'market' => $market,
        'regenerate' => (bool) $form_state->getValue('regenerate'),
      ];
    }
    if (!$targets) {
      $form_state->setErrorByName('targets', $this->t('Enter at least one target language and market.'));
    }
    $form_state->set('parsed_targets', $targets);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $tasks = $this->taskManager->enqueue(
        (string) $form_state->getValue('entity_type'),
        trim((string) $form_state->getValue('entity_id')),
        (string) $form_state->getValue('source_lang'),
        $form_state->get('parsed_targets'),
      );
      $this->messenger()->addStatus($this->t('Localization tasks are ready: @count.', ['@count' => count($tasks['tasks'])]));
      $form_state->setRedirect('genemedi_i18n_workflow.overview');
    }
    catch (\InvalidArgumentException | \RuntimeException $exception) {
      $this->messenger()->addError($exception->getMessage());
    }
  }

}
