<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configures language targets before enqueuing the selected content.
 */
final class BulkEnqueueForm extends FormBase {

  use TargetLanguageFieldsTrait;

  /**
   * Constructs the bulk language form.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly LanguageManagerInterface $languages,
    private readonly PrivateTempStoreFactory $tempStore,
    private readonly ActionManager $actionManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('tempstore.private'),
      $container->get('plugin.manager.action'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'genemedi_i18n_workflow_bulk_enqueue';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // A unique selection in the form action survives uncached initial GETs
    // and keeps different browser tabs bound to their own selected entities.
    $store = $this->tempStore->get('genemedi_i18n_bulk');
    $key = $this->getRequest()->query->get('selection', $store->get('latest_selection') ?? '');
    $selection = $store->get('selection:' . $key) ?? [];
    $form_state->set('selection', $selection);
    $form_state->set('selection_key', $key);
    $form['#action'] = Url::fromRoute('genemedi_i18n_workflow.bulk_enqueue', [], [
      'query' => ['selection' => $key],
    ])->toString();
    $form['description'] = [
      '#markup' => $this->t('Select target languages and confirm the market for each. Confirmation queues one task per selected content item and target language. Missing translations become unpublished drafts; completed localization will overwrite the target fields and publish a new revision.'),
    ];
    $rows = [];
    foreach ($selection as $type => $ids) {
      if (!in_array($type, ['node', 'product_generic', 'product_solidex'], TRUE)) {
        continue;
      }
      $plugin = $this->actionManager->createInstance('genemedi_i18n_queue:' . $type);
      foreach ($this->entities->getStorage($type)->loadMultiple($ids) as $entity) {
        if ($plugin->access($entity)) {
          $rows[] = [$entity->getEntityType()->getLabel(), $entity->id(), $entity->label()];
        }
      }
    }
    $form['selected'] = [
      '#type' => 'table',
      '#header' => [$this->t('Content type'), $this->t('ID'), $this->t('Title')],
      '#rows' => $rows,
      '#empty' => $this->t('No accessible content selected. Return to the content list and select items.'),
    ];
    $options = [];
    foreach ($this->languages->getLanguages() as $code => $language) {
      $options[$code] = $language->getName() . ' (' . $code . ')';
    }
    $form['source_lang'] = [
      '#type' => 'select',
      '#title' => $this->t('Source language'),
      '#options' => $options,
      '#default_value' => isset($options['en']) ? 'en' : $this->languages->getDefaultLanguage()->getId(),
      '#required' => TRUE,
    ];
    $this->addTargetLanguageFields($form, $options);
    $form['regenerate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Create a new task generation even if a matching task exists'),
      '#description' => $this->t('Leave unchecked to reuse matching tasks and avoid duplicate work.'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue localization tasks'),
      '#disabled' => !$rows,
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('genemedi_i18n_workflow.overview'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $source = (string) $form_state->getValue('source_lang');
    $targets = $this->validateTargetLanguages($form_state);
    $selection = $form_state->get('selection');
    if (!$selection) {
      $form_state->setErrorByName('selected', $this->t('No content selected.'));
    }
    foreach ($selection as $type => $ids) {
      if (!in_array($type, ['node', 'product_generic', 'product_solidex'], TRUE)) {
        $form_state->setErrorByName('selected', $this->t('Unsupported content type.'));
        continue;
      }
      $plugin = $this->actionManager->createInstance('genemedi_i18n_queue:' . $type);
      foreach ($ids as $id) {
        $entity = $this->entities->getStorage($type)->load($id);
        if (!$entity || !$plugin->access($entity) || !$entity->hasTranslation($source)) {
          $form_state->setErrorByName('selected', $this->t('Selected content @type/@id is missing, inaccessible, unsupported, or has no source translation.', [
            '@type' => $type,
            '@id' => $id,
          ]));
        }
      }
    }
    $form_state->set('parsed_targets', $targets);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Queueing localization tasks'))
      ->setFinishCallback([self::class, 'finished']);
    foreach ($form_state->get('selection') as $type => $ids) {
      foreach ($ids as $id) {
        $batch->addOperation([self::class, 'enqueueItem'], [
          $type, (string) $id, (string) $form_state->getValue('source_lang'),
          $form_state->get('parsed_targets'),
        ]);
      }
    }
    batch_set($batch->toArray());
    $store = $this->tempStore->get('genemedi_i18n_bulk');
    $key = $form_state->get('selection_key');
    $store->delete('selection:' . $key);
    if ($store->get('latest_selection') === $key) {
      $store->delete('latest_selection');
    }
    $form_state->setRedirect('genemedi_i18n_workflow.overview');
  }

  /**
   * Queues one entity atomically, preserving individual errors and task IDs.
   */
  public static function enqueueItem(string $type, string $id, string $source, array $targets, array &$context): void {
    $context['results'] += ['created' => 0, 'reused' => 0, 'errors' => [], 'task_ids' => []];
    try {
      // TaskManager rechecks entity access, translation support and source
      // content at operation time, including when a batch spans requests.
      $existing = \Drupal::database()->select('genemedi_i18n_task', 't')->fields('t', ['id'])
        ->condition('entity_type', $type)->condition('entity_id', $id)->execute()->fetchCol();
      $result = \Drupal::service('genemedi_i18n_workflow.task_manager')->enqueue($type, $id, $source, $targets);
      foreach ($result['tasks'] as $task) {
        $key = in_array((string) $task['id'], array_map('strval', $existing), TRUE) ? 'reused' : 'created';
        $context['results'][$key]++;
        $context['results']['task_ids'][] = $task['id'];
      }
    }
    catch (\Throwable $exception) {
      $context['results']['errors'][] = $type . '/' . $id . ': ' . $exception->getMessage();
    }
    $context['message'] = t('Processed @type/@id.', ['@type' => $type, '@id' => $id]);
  }

  /**
   * Reports batch results without hiding partially successful submissions.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $messenger->addStatus(t('Localization queue: @created new tasks, @reused existing tasks reused.', [
      '@created' => $results['created'] ?? 0,
      '@reused' => $results['reused'] ?? 0,
    ]));
    foreach ($results['errors'] ?? [] as $error) {
      $messenger->addError($error);
    }
    if (!$success) {
      $messenger->addError(t('Batch interrupted. Some items were not processed; existing queued tasks remain available.'));
    }
  }

}
