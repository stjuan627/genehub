<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\genemedi_i18n_workflow\TaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Enqueues explicit language/market pairs for an existing source entity.
 */
final class EnqueueForm extends FormBase {

  use TargetLanguageFieldsTrait;

  /**
   * Constructs the queue form.
   */
  public function __construct(
    protected readonly TaskManager $taskManager,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly RequestStack $workflowRequests,
    protected readonly EntityTypeManagerInterface $entities,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('genemedi_i18n_workflow.task_manager'),
      $container->get('language_manager'),
      $container->get('request_stack'),
      $container->get('entity_type.manager'),
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
    $types = [
      'node' => $this->t('Basic page'),
      'product_generic' => $this->t('Generic product'),
      'product_solidex' => $this->t('SOLIDEX product'),
    ];
    $initial_type = $query?->get('entity_type', 'node');
    $initial_type = isset($types[$initial_type]) ? $initial_type : 'node';
    $type = $form_state->getValue('entity_type', $initial_type);
    $type = isset($types[$type]) ? $type : 'node';
    $previous_type = $form_state->get('selected_entity_type');
    $changed = $previous_type !== NULL && $previous_type !== $type;
    if ($changed) {
      $input = $form_state->getUserInput();
      $input['entity_id'] = '';
      $form_state->setUserInput($input);
      $form_state->setValue('entity_id', '');
    }
    $form_state->set('selected_entity_type', $type);
    $default_entity = NULL;
    if ($previous_type === NULL && $type === $initial_type && $query?->get('entity_id')) {
      $candidate = $this->entities->getStorage($type)->load($query->get('entity_id'));
      if ($candidate && $candidate->access('update') && ($type !== 'node' || $candidate->bundle() === 'page')) {
        $default_entity = $candidate;
      }
    }
    $form['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $types,
      '#default_value' => $type,
      '#required' => TRUE,
      '#ajax' => ['callback' => '::refreshEntity', 'wrapper' => 'localization-entity-selector'],
      '#limit_validation_errors' => [['entity_type']],
    ];
    $form['entity_id'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Content'),
      '#description' => $this->t('Search by title and select the content to localize.'),
      '#target_type' => $type,
      '#maxlength' => 1024,
      '#selection_settings' => $type === 'node' ? ['target_bundles' => ['page']] : [],
      '#default_value' => $default_entity,
      '#required' => TRUE,
      '#prefix' => '<div id="localization-entity-selector">',
      '#suffix' => '</div>',
    ];
    if ($changed) {
      $form['entity_id']['#value'] = '';
    }
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
    $this->addTargetLanguageFields($form, $languages);
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
   * Replaces the autocomplete when the selected entity type changes.
   */
  public function refreshEntity(array &$form, FormStateInterface $form_state): array {
    return $form['entity_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (($form_state->getTriggeringElement()['#name'] ?? '') === 'entity_type') {
      return;
    }
    $targets = $this->validateTargetLanguages($form_state);
    $id = $form_state->getValue('entity_id');
    $type = $form_state->getValue('entity_type');
    $entity = $id ? $this->entities->getStorage($type)->load($id) : NULL;
    if (!$entity || !$entity->access('update') || ($type === 'node' && $entity->bundle() !== 'page')) {
      $form_state->setErrorByName('entity_id', $this->t('Select existing content that you can update.'));
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
