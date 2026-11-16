<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow;

use Drupal\Core\Url;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Owns durable task state, fenced leases and scoped JSON:API import receipts.
 */
final class TaskManager {

  private const TABLE = 'genemedi_i18n_task';
  private const ATTEMPTS = 'genemedi_i18n_attempt';
  private const MAX_ATTEMPTS = 5;

  /**
   * Entity locks held for the duration of a workflow JSON:API request.
   *
   * @var array<string, bool>
   */
  private array $heldLocks = [];

  /**
   * Constructs the task manager.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly LanguageManagerInterface $languages,
    private readonly AccountProxyInterface $account,
    private readonly TimeInterface $time,
    private readonly LockBackendInterface $lock,
    private readonly SourceSnapshot $snapshots,
    private readonly ResourceTypeRepositoryInterface $resources,
    private readonly RequestStack $requests,
  ) {}

  /**
   * Creates a language batch and pre-creates only missing unpublished targets.
   */
  public function enqueue(string $entityType, string $entityId, string $sourceLang, array $targets): array {
    $this->assertSupportedType($entityType);
    if (!$targets || count($targets) > 50) {
      throw new \InvalidArgumentException('Provide between one and fifty targets.');
    }
    $seen = [];
    foreach ($targets as $target) {
      $language = $target['langcode'] ?? '';
      $market = $target['market'] ?? '';
      if (!is_string($language) || !is_string($market) || $market === '' || strlen($market) > 64
        || !$this->languages->getLanguage($language) || $language === $sourceLang || isset($seen[$language])) {
        throw new \InvalidArgumentException('Targets need distinct configured languages, explicit markets, and must differ from the source.');
      }
      $seen[$language] = TRUE;
    }
    $key = $this->entityLockKey($entityType, $entityId);
    $this->acquire($key);
    $transaction = $this->database->startTransaction();
    try {
      $entity = $this->loadEntity($entityType, $entityId);
      if (!$this->account->hasPermission('administer genemedi i18n workflow') || !$entity->access('update', $this->account)) {
        throw new AccessDeniedHttpException('Entity update access and workflow administration are required.');
      }
      $this->assertTranslationSupport($entity);
      $snapshot = $this->snapshots->capture($entity, $sourceLang);
      $batch = bin2hex(random_bytes(16));
      $tasks = [];
      foreach ($targets as $target) {
        $language = $target['langcode'];
        $dedup = hash('sha256', $this->json([
          $entityType, $entity->uuid(), $snapshot['source_hash'],
          $sourceLang, $language, $target['market'], 1,
        ]));
        $existing = $this->database->select(self::TABLE, 't')->fields('t', ['id'])
          ->condition('dedup_key', $dedup)->execute()->fetchField();
        if ($existing && empty($target['regenerate'])) {
          $previous = $this->getTask((int) $existing);
          if (!in_array($previous['status'], ['superseded', 'stale'], TRUE)) {
            $tasks[] = $previous;
            continue;
          }
        }
        // A new task generation must not race an in-flight CMS write.
        $active = $this->database->select(self::TABLE, 't')->fields('t', ['id'])
          ->condition('entity_type', $entityType)->condition('entity_id', $entityId)
          ->condition('status', 'importing')->condition('lease_expires', $this->now(), '>')->execute()->fetchField();
        if ($active) {
          throw new \RuntimeException('An import is active for this entity; retry enqueue after it finishes.');
        }
        if ($existing) {
          // Preserve the previous record while giving this generation its key.
          $this->database->update(self::TABLE)->fields(['dedup_key' => hash('sha256', $dedup . ':' . $existing)])
            ->condition('id', $existing)->execute();
        }
        $this->database->update(self::TABLE)->fields([
          'status' => 'superseded',
          'token_hash' => '',
          'changed' => $this->now(),
        ])
          ->condition('entity_type', $entityType)->condition('entity_id', $entityId)->condition('target_lang', $language)
          ->condition('status', ['queued', 'running', 'ready_for_import', 'importing', 'failed', 'stale'], 'IN')->execute();
        if (!$entity->hasTranslation($language)) {
          $values = [];
          foreach ($entity->getTranslation($sourceLang)->getFieldDefinitions() as $name => $definition) {
            if ($definition->isTranslatable() && !$definition->isComputed()
              && !in_array($name, [
                $entity->getEntityType()->getKey('langcode'),
                'default_langcode',
                'content_translation_source',
              ], TRUE)) {
              $values[$name] = $entity->getTranslation($sourceLang)->get($name)->getValue();
            }
          }
          $translation = $entity->addTranslation($language, $values);
          $translation->setUnpublished();
          if ($translation->hasField('content_translation_source')) {
            $translation->set('content_translation_source', $sourceLang);
          }
          $translation->setNewRevision(TRUE);
          $this->revisionMetadata($translation, 'Localization target draft prepared.');
          $translation->save();
        }
        $id = (int) $this->database->insert(self::TABLE)->fields([
          'batch_id' => $batch,
          'dedup_key' => $dedup,
          'entity_type' => $entityType,
          'entity_id' => $entityId,
          'entity_uuid' => $entity->uuid(),
          'source_lang' => $sourceLang,
          'target_lang' => $language,
          'market' => $target['market'],
          'source_hash' => $snapshot['source_hash'],
          'snapshot' => $this->json($snapshot),
          'created' => $this->now(),
          'changed' => $this->now(),
        ])->execute();
        $tasks[] = $this->getTask($id);
      }
      return ['batch_id' => $batch, 'tasks' => $tasks];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $this->entities->getStorage($entityType)->resetCache([$entityId]);
      throw $e;
    }
    finally {
      unset($transaction);
      $this->release($key);
    }
  }

  /**
   * Lists recent tasks without leaking credential hashes.
   */
  public function listTasks(): array {
    $ids = $this->database->select(self::TABLE, 't')->fields('t', ['id'])->orderBy('id', 'DESC')->range(0, 100)->execute()->fetchCol();
    return array_map(fn ($id) => $this->getTask((int) $id), $ids);
  }

  /**
   * Returns a task, its immutable source and persisted result.
   */
  public function getTask(int $id): array {
    $task = $this->load($id);
    unset($task['token_hash']);
    $task['attempts'] = $task['attempt_count'];
    $task['last_error'] = $task['error'];
    return $task;
  }

  /**
   * Atomically claims a queued or abandoned generation using compare-and-set.
   */
  public function claim(string $workerId, int $leaseSeconds = 300): ?array {
    $this->assertWorker($workerId, $leaseSeconds);
    $now = $this->now();
    $query = $this->database->select(self::TABLE, 't')->fields('t');
    $eligible = $query->orConditionGroup()->condition('status', 'queued')
      ->condition($query->andConditionGroup()->condition('status', 'running')->condition('lease_expires', $now, '<='));
    $rows = $query->condition($eligible)->condition('available_at', $now, '<=')
      ->condition('attempt_count', self::MAX_ATTEMPTS, '<')->orderBy('id')->range(0, 30)->execute()->fetchAll(FetchAs::Associative);
    foreach ($rows as $row) {
      $token = bin2hex(random_bytes(32));
      $transaction = $this->database->startTransaction();
      try {
        $updated = $this->database->update(self::TABLE)->fields([
          'status' => 'running',
          'worker_id' => $workerId,
          'token_hash' => hash('sha256', $token),
          'lease_expires' => $now + $leaseSeconds,
          'attempt_count' => (int) $row['attempt_count'] + 1,
          'changed' => $now,
        ])->condition('id', $row['id'])->condition('status', $row['status'])
          ->condition('token_hash', $row['token_hash'])->condition('lease_expires', $row['lease_expires'])->execute();
        if (!$updated) {
          unset($transaction);
          continue;
        }
        $this->finishAttempt($row['token_hash'], 'expired');
        $this->startAttempt((int) $row['id'], 'generation', $workerId, $token);
        return $this->getTask((int) $row['id']) + ['token' => $token];
      }
      catch (\Throwable $e) {
        $transaction->rollBack();
        throw $e;
      }
      finally {
        unset($transaction);
      }
    }
    // Exhausted abandoned executions must not remain permanently "running".
    $this->database->update(self::TABLE)->fields([
      'status' => 'failed',
      'error' => 'Generation lease expired and retry limit reached.',
      'changed' => $now,
    ])
      ->condition('status', 'running')->condition('lease_expires', $now, '<=')->condition('attempt_count', self::MAX_ATTEMPTS, '>=')->execute();
    return NULL;
  }

  /**
   * Extends an owned live lease without allowing expired owners to revive it.
   */
  public function heartbeat(int $id, string $token, int $leaseSeconds = 300): array {
    $this->assertWorker('heartbeat', $leaseSeconds);
    $task = $this->owned($id, $token, ['running', 'importing']);
    $this->updateOwned($task, $token, ['lease_expires' => $this->now() + $leaseSeconds]);
    return $this->getTask($id);
  }

  /**
   * Atomically persists validated fields and the agent's quality attestations.
   */
  public function complete(int $id, string $token, array $result): array {
    $task = $this->load($id);
    if ($task['status'] === 'ready_for_import' && $this->tokenMatches($task, $token) && $task['result'] === $result) {
      return $this->getTask($id);
    }
    $task = $this->owned($id, $token, ['running']);
    foreach (['structure_passed', 'facts_passed', 'details_preserved'] as $check) {
      if (($result['checks'][$check] ?? NULL) !== TRUE) {
        throw new \InvalidArgumentException('Every structural/factual/detail-preservation check must explicitly pass.');
      }
    }
    if (!is_array($result['fields'] ?? NULL) || !is_array($result['research'] ?? NULL) || !$result['research']) {
      throw new \InvalidArgumentException('Provide translated fields and non-empty research evidence.');
    }
    $this->snapshots->validate($task['snapshot'], $result['fields']);
    $this->assertFresh($task);
    $transaction = $this->database->startTransaction();
    try {
      $this->updateOwned($task, $token, [
        'status' => 'ready_for_import',
        'result' => $this->json($result),
        'error' => NULL,
      ]);
      $this->finishAttempt(hash('sha256', $token), 'completed');
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    return $this->getTask($id);
  }

  /**
   * Records a phase-specific failure without discarding generated content.
   */
  public function fail(int $id, string $token, string $message, bool $retryable): array {
    $task = $this->owned($id, $token, ['running', 'importing']);
    $importing = $task['status'] === 'importing';
    if ($importing && $task['applied_revision'] !== NULL) {
      return $this->acknowledgeImport($id, $token);
    }
    $attempts = $importing ? $task['import_attempt_count'] : $task['attempt_count'];
    $retry = $retryable && $attempts < self::MAX_ATTEMPTS;
    $transaction = $this->database->startTransaction();
    try {
      $this->updateOwned($task, $token, [
        'status' => $retry ? ($importing ? 'ready_for_import' : 'queued') : ($importing ? 'import_failed' : 'failed'),
        'error' => mb_substr($message, 0, 10000),
        'available_at' => $this->now() + min(3600, 30 * (2 ** $attempts)),
        'lease_expires' => 0,
        'token_hash' => '',
      ]);
      $this->finishAttempt(hash('sha256', $token), 'failed', $message);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    return $this->getTask($id);
  }

  /**
   * Discovers pending imports independently of the recent-tasks UI listing.
   */
  public function claimImport(string $workerId, int $leaseSeconds = 300): ?array {
    $this->assertWorker($workerId, $leaseSeconds);
    $query = $this->database->select(self::TABLE, 't')->fields('t', ['id']);
    $eligible = $query->orConditionGroup()->condition('status', 'ready_for_import')
      ->condition($query->andConditionGroup()->condition('status', 'importing')->condition('lease_expires', $this->now(), '<='));
    $ids = $query->condition($eligible)->condition('available_at', $this->now(), '<=')->orderBy('changed')->orderBy('id')->execute()->fetchCol();
    foreach ($ids as $id) {
      try {
        return $this->beginImport((int) $id, $workerId, $leaseSeconds);
      }
      catch (\RuntimeException $exception) {
        $message = $exception->getMessage();
        if (str_starts_with($message, 'Source content or field mapping changed;')) {
          $this->database->update(self::TABLE)->fields([
            'status' => 'stale',
            'error' => $message,
            'lease_expires' => 0,
            'token_hash' => '',
            'changed' => $this->now(),
          ])->condition('id', $id)->condition('status', ['ready_for_import', 'importing'], 'IN')->execute();
          continue;
        }
        // Busy entities can be skipped without starving other language jobs.
        if (str_starts_with($message, 'Another language import ')
          || str_starts_with($message, 'The entity is busy;')
          || $message === 'Task is not available for import.') {
          continue;
        }
        // Configuration and storage failures need an actionable API error.
        throw $exception;
      }
    }
    return NULL;
  }

  /**
   * Claims the import stage and returns a fenced core JSON:API PATCH document.
   */
  public function beginImport(int $id, string $workerId, int $leaseSeconds = 300): array {
    $this->assertWorker($workerId, $leaseSeconds);
    $task = $this->load($id);
    if ($task['status'] === 'published') {
      return $this->getTask($id);
    }
    $key = $this->entityLockKey($task['entity_type'], $task['entity_id']);
    $this->acquire($key);
    $transaction = $this->database->startTransaction();
    try {
      $task = $this->load($id);
      if (!in_array($task['status'], ['ready_for_import', 'importing'], TRUE)
        || ($task['status'] === 'importing' && $task['lease_expires'] > $this->now())
        || $task['available_at'] > $this->now()) {
        throw new \RuntimeException('Task is not available for import.');
      }
      $other = $this->database->select(self::TABLE, 't')->fields('t', ['id'])
        ->condition('entity_type', $task['entity_type'])->condition('entity_id', $task['entity_id'])
        ->condition('id', $id, '<>')->condition('status', 'importing')->condition('lease_expires', $this->now(), '>')->execute()->fetchField();
      if ($other) {
        throw new \RuntimeException('Another language import holds the entity lease.');
      }
      // An entity-save receipt is committed with the revision. Recover it
      // before source checks, avoiding a duplicate PATCH after a lost reply.
      if ($task['applied_revision'] !== NULL) {
        $this->verifyApplied($task);
        $this->database->update(self::TABLE)->fields([
          'status' => 'published',
          'changed' => $this->now(),
          'lease_expires' => 0,
          'token_hash' => '',
        ])
          ->condition('id', $id)->execute();
        $this->finishAttempt($task['token_hash'], 'completed');
        return $this->getTask($id);
      }
      if ($task['import_attempt_count'] >= self::MAX_ATTEMPTS) {
        $this->database->update(self::TABLE)->fields([
          'status' => 'import_failed',
          'error' => 'Import lease expired and retry limit reached.',
          'lease_expires' => 0,
          'token_hash' => '',
          'changed' => $this->now(),
        ])->condition('id', $id)->execute();
        $this->finishAttempt($task['token_hash'], 'expired');
        return $this->getTask($id);
      }
      $entity = $this->assertFresh($task);
      $this->assertTranslationSupport($entity);
      if (!$entity->hasTranslation($task['target_lang'])) {
        throw new \RuntimeException('Prepared target translation is missing; repair the enqueue prerequisite.');
      }
      $this->snapshots->validate($task['snapshot'], $task['result']['fields']);
      $token = bin2hex(random_bytes(32));
      $updated = $this->database->update(self::TABLE)->fields([
        'status' => 'importing',
        'worker_id' => $workerId,
        'token_hash' => hash('sha256', $token),
        'lease_expires' => $this->now() + $leaseSeconds,
        'changed' => $this->now(),
        'revision_before' => (string) $entity->getRevisionId(),
        'import_attempt_count' => $task['import_attempt_count'] + 1,
        'error' => NULL,
      ])->condition('id', $id)->condition('status', $task['status'])->condition('token_hash', $task['token_hash'])->execute();
      if (!$updated) {
        throw new \RuntimeException('Import was claimed concurrently.');
      }
      $this->finishAttempt($task['token_hash'], 'expired');
      $this->startAttempt($id, 'import', $workerId, $token);
      $task = $this->load($id);
      $resource = $this->resources->get($task['entity_type'], $entity->bundle());
      if ($resource->isInternal()) {
        throw new \RuntimeException('The resource is not exposed by JSON:API.');
      }
      $route = 'jsonapi.' . $resource->getTypeName() . '.individual';
      $url = Url::fromRoute($route, ['entity' => $entity->uuid()])->toString();
      return $this->getTask($id) + [
        'token' => $token,
        'patch' => [
          'method' => 'PATCH',
          'url' => $url,
          'headers' => [
            'Content-Type' => 'application/vnd.api+json',
            'Accept' => 'application/vnd.api+json',
            'X-Genemedi-I18n-Task' => (string) $id,
            'X-Genemedi-I18n-Token' => $token,
          ],
          'payload' => $this->patchDocument($task, $entity),
        ],
      ];
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      unset($transaction);
      $this->release($key);
    }
  }

  /**
   * Confirms the revision receipt and re-reads target fields before completion.
   */
  public function acknowledgeImport(int $id, string $token): array {
    $task = $this->load($id);
    if ($task['status'] === 'published') {
      return $this->getTask($id);
    }
    $task = $this->owned($id, $token, ['importing']);
    $key = $this->entityLockKey($task['entity_type'], $task['entity_id']);
    $this->acquire($key);
    try {
      $this->verifyApplied($task);
      $transaction = $this->database->startTransaction();
      $this->updateOwned($task, $token, ['status' => 'published', 'token_hash' => '', 'lease_expires' => 0]);
      $this->finishAttempt(hash('sha256', $token), 'completed');
      return $this->getTask($id);
    }
    catch (\Throwable $e) {
      if (isset($transaction)) {
        $transaction->rollBack();
      }
      throw $e;
    }
    finally {
      unset($transaction);
      $this->release($key);
    }
  }

  /**
   * Resolves exactly the prepared target, while retaining native access checks.
   */
  public function resolvePatchEntity(string $uuid, array $definition, array $defaults): ContentEntityInterface {
    if (!$this->account->isAuthenticated() || !$this->account->hasPermission('execute genemedi i18n workflow')) {
      throw new AccessDeniedHttpException('Authenticated workflow execution permission is required.');
    }
    $request = $this->requests->getCurrentRequest();
    // JSON:API also upcasts the current route when computing response links.
    // Reuse the exact object within this request, not across HTTP requests.
    $resolved = $request->attributes->get('_genemedi_i18n_entity');
    if ($resolved instanceof ContentEntityInterface && $resolved->uuid() === $uuid
      && ($definition['type'] ?? '') === 'entity:' . $resolved->getEntityTypeId()) {
      return $resolved;
    }
    $id = (int) $request->headers->get('X-Genemedi-I18n-Task');
    $token = (string) $request->headers->get('X-Genemedi-I18n-Token');
    $task = $this->owned($id, $token, ['importing']);
    $key = $this->entityLockKey($task['entity_type'], $task['entity_id']);
    $this->acquire($key);
    $this->heldLocks[$key] = TRUE;
    $task = $this->owned($id, $token, ['importing']);
    if ($task['applied_revision'] !== NULL) {
      throw new \RuntimeException('This result is already applied; acknowledge or recover the receipt instead of PATCHing again.');
    }
    if ($uuid !== $task['entity_uuid'] || ($definition['type'] ?? '') !== 'entity:' . $task['entity_type']) {
      throw new AccessDeniedHttpException('Task and JSON:API resource do not match.');
    }
    $entity = $this->assertFresh($task);
    if (!$entity->hasTranslation($task['target_lang'])) {
      throw new \RuntimeException('Prepared target translation no longer exists.');
    }
    $actual = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    if ($this->canonical($actual) !== $this->canonical($this->patchDocument($task, $entity))) {
      throw new AccessDeniedHttpException('PATCH must exactly match the validated result document.');
    }
    $request->attributes->set('_genemedi_i18n_task', $id);
    $translation = $entity->getTranslation($task['target_lang']);
    $request->attributes->set('_genemedi_i18n_entity', $translation);
    return $translation;
  }

  /**
   * Forces a new revision only for a validated, fenced localization PATCH.
   */
  public function beforePatch(EntityInterface $entity): void {
    $request = $this->requests->getCurrentRequest();
    $id = $request?->attributes->get('_genemedi_i18n_task');
    if (!$id || !$entity instanceof ContentEntityInterface) {
      return;
    }
    $task = $this->owned((int) $id, (string) $request->headers->get('X-Genemedi-I18n-Token'), ['importing']);
    // Entity saves may trigger path aliases or other related entity saves.
    if ($entity->getEntityTypeId() !== $task['entity_type'] || $entity->uuid() !== $task['entity_uuid']) {
      return;
    }
    if ($entity->uuid() !== $task['entity_uuid'] || $entity->language()->getId() !== $task['target_lang']) {
      throw new AccessDeniedHttpException('The save does not match the task target.');
    }
    if (!$entity->isPublished()) {
      throw new \RuntimeException('The import must publish the target translation.');
    }
    $this->assertExpectedFields($task, $entity);
    $entity->setNewRevision(TRUE);
    $this->revisionMetadata($entity, sprintf('Localization task %d (%s, %s).', $id, $task['target_lang'], $task['market']));
  }

  /**
   * Commits the application receipt in the entity save transaction.
   */
  public function afterPatch(EntityInterface $entity): void {
    $request = $this->requests->getCurrentRequest();
    $id = $request?->attributes->get('_genemedi_i18n_task');
    if (!$id || !$entity instanceof ContentEntityInterface) {
      return;
    }
    $token = (string) $request->headers->get('X-Genemedi-I18n-Token');
    $task = $this->owned((int) $id, $token, ['importing']);
    if ($entity->getEntityTypeId() !== $task['entity_type'] || $entity->uuid() !== $task['entity_uuid']) {
      return;
    }
    if ($entity->uuid() !== $task['entity_uuid'] || (string) $entity->getRevisionId() === (string) $task['revision_before']) {
      throw new \RuntimeException('Localization PATCH did not create the required new revision.');
    }
    $this->updateOwned($task, $token, ['applied_revision' => (string) $entity->getRevisionId()]);
  }

  /**
   * Releases request-lifetime entity locks even after an HTTP exception.
   */
  public function releaseLocks(): void {
    foreach (array_keys($this->heldLocks) as $key) {
      $this->release($key);
    }
    $this->heldLocks = [];
  }

  /**
   * Builds a native JSON:API document using actual public field names.
   */
  private function patchDocument(array $task, ContentEntityInterface $entity): array {
    $resource = $this->resources->get($task['entity_type'], $entity->bundle());
    $attributes = [];
    foreach ($task['result']['fields'] as $name => $items) {
      $field = $resource->getFieldByInternalName($name);
      if (!$field || !$field->isFieldEnabled()) {
        throw new \RuntimeException('A required localization field is disabled in JSON:API: ' . $name);
      }
      $definition = $entity->getFieldDefinition($name);
      $type = $definition->getType();
      $values = array_map(static function (array $item) use ($type) {
        return in_array($type, ['string', 'string_long'], TRUE) ? ($item['value'] ?? NULL) : $item;
      }, $items);
      $attributes[$resource->getPublicName($name)] = $definition->getFieldStorageDefinition()->getCardinality() === 1 ? ($values[0] ?? NULL) : $values;
    }
    $attributes[$resource->getPublicName($entity->getEntityType()->getKey('published'))] = TRUE;
    return ['data' => ['type' => $resource->getTypeName(), 'id' => $entity->uuid(), 'attributes' => $attributes]];
  }

  /**
   * Confirms the saved target and revision independently of the HTTP reply.
   */
  private function verifyApplied(array $task): void {
    if ($task['applied_revision'] === NULL || $task['applied_revision'] === $task['revision_before']) {
      throw new \RuntimeException('No confirmed new revision receipt exists for this result.');
    }
    $entity = $this->loadEntity($task['entity_type'], $task['entity_id']);
    if (!$entity->hasTranslation($task['target_lang'])) {
      throw new \RuntimeException('The saved target translation is missing.');
    }
    $translation = $entity->getTranslation($task['target_lang']);
    if (!$translation->isPublished()) {
      throw new \RuntimeException('The target content is not published.');
    }
    $this->assertExpectedFields($task, $translation);
  }

  /**
   * Compares all persisted field values with the validated result.
   */
  private function assertExpectedFields(array $task, ContentEntityInterface $entity): void {
    foreach ($task['result']['fields'] as $name => $expected) {
      // Drupal normalizes empty field items on deserialization and storage.
      // Compare their native emptiness, never dropping nonempty content.
      if ($entity->get($name)->isEmpty()) {
        $expected_field = clone $entity->get($name);
        $expected_field->setValue($expected, FALSE);
        if ($expected_field->isEmpty()) {
          continue;
        }
      }
      if ($this->canonical($entity->get($name)->getValue()) !== $this->canonical($expected)) {
        throw new \RuntimeException('Stored content differs from validated result: ' . $name);
      }
    }
  }

  /**
   * Reloads the source and compares content rather than entity-wide revisions.
   */
  private function assertFresh(array $task): ContentEntityInterface {
    $entity = $this->loadEntity($task['entity_type'], $task['entity_id']);
    $current = $this->snapshots->capture($entity, $task['source_lang']);
    if ($current['source_hash'] !== $task['source_hash']) {
      throw new \RuntimeException('Source content or field mapping changed; enqueue a fresh task.');
    }
    return $entity;
  }

  /**
   * Loads task storage and decodes the immutable input/result.
   */
  private function load(int $id): array {
    $row = $this->database->select(self::TABLE, 't')->fields('t')->condition('id', $id)->execute()->fetchAssoc();
    if (!$row) {
      throw new \InvalidArgumentException('Localization task not found.');
    }
    foreach (['id', 'attempt_count', 'import_attempt_count', 'lease_expires', 'created', 'changed', 'available_at'] as $name) {
      $row[$name] = (int) $row[$name];
    }
    $row['snapshot'] = json_decode($row['snapshot'], TRUE, 512, JSON_THROW_ON_ERROR);
    $row['result'] = $row['result'] !== NULL ? json_decode($row['result'], TRUE, 512, JSON_THROW_ON_ERROR) : NULL;
    return $row;
  }

  /**
   * Requires an active lease with the exact current fencing credential.
   */
  private function owned(int $id, string $token, array $statuses): array {
    $task = $this->load($id);
    if (!in_array($task['status'], $statuses, TRUE) || !$this->tokenMatches($task, $token) || $task['lease_expires'] <= $this->now()) {
      throw new \RuntimeException('Task lease is expired, replaced, or invalid for this operation.');
    }
    return $task;
  }

  /**
   * Applies a fenced state transition, rejecting a concurrently replaced lease.
   */
  private function updateOwned(array $task, string $token, array $values): void {
    $values['changed'] = $this->now();
    $updated = $this->database->update(self::TABLE)->fields($values)->condition('id', $task['id'])
      ->condition('status', $task['status'])->condition('token_hash', hash('sha256', $token))
      ->condition('lease_expires', $this->now(), '>')->execute();
    if (!$updated) {
      throw new \RuntimeException('Task ownership changed before the operation completed.');
    }
  }

  /**
   * Verifies a supplied secret against its stored digest.
   */
  private function tokenMatches(array $task, string $token): bool {
    return $token !== '' && $task['token_hash'] !== '' && hash_equals($task['token_hash'], hash('sha256', $token));
  }

  /**
   * Loads a fresh content entity without reusing a previous translation cache.
   */
  private function loadEntity(string $type, string $id): ContentEntityInterface {
    $this->assertSupportedType($type);
    $storage = $this->entities->getStorage($type);
    $storage->resetCache([$id]);
    $entity = $storage->load($id);
    if (!$entity instanceof ContentEntityInterface || ($type === 'node' && $entity->bundle() !== 'page')) {
      throw new \InvalidArgumentException('A supported existing page or product is required.');
    }
    return $entity;
  }

  /**
   * Rejects arbitrary entity types before invoking storage.
   */
  private function assertSupportedType(string $type): void {
    if (!in_array($type, ['node', 'product_generic', 'product_solidex'], TRUE) || !$this->entities->hasDefinition($type)) {
      throw new \InvalidArgumentException('Unsupported localization entity type.');
    }
  }

  /**
   * Refuses to unpublish shared status or bypass moderation working copies.
   */
  private function assertTranslationSupport(ContentEntityInterface $entity): void {
    $published = $entity->getEntityType()->getKey('published');
    if (!$entity instanceof EntityPublishedInterface || !$entity->isTranslatable() || !$entity->getEntityType()->isRevisionable()
      || !$published || !$entity->getFieldDefinition($published)->isTranslatable()) {
      throw new \RuntimeException('Entity requires revisions and a translatable publication status; run the product status update first.');
    }
    if ($entity->hasField('moderation_state')) {
      throw new \RuntimeException('Moderated content needs an explicit workflow adapter before automated publication.');
    }
    if (!$entity->isLatestRevision() || !$entity->isDefaultRevision()) {
      throw new \RuntimeException('Pending revisions are not supported by core JSON:API PATCH.');
    }
  }

  /**
   * Validates bounded lease inputs.
   */
  private function assertWorker(string $worker, int $lease): void {
    if ($worker === '' || strlen($worker) > 128 || $lease < 30 || $lease > 3600) {
      throw new \InvalidArgumentException('Worker ID is required (max 128 characters); lease must be 30–3600 seconds.');
    }
  }

  /**
   * Adds an auditable task link to every created revision.
   */
  private function revisionMetadata(ContentEntityInterface $entity, string $message): void {
    if ($entity instanceof RevisionLogInterface) {
      $entity->setRevisionUserId((int) $this->account->id());
      $entity->setRevisionCreationTime($this->now());
      $entity->setRevisionLogMessage($message);
    }
  }

  /**
   * Records a new worker attempt.
   */
  private function startAttempt(int $id, string $phase, string $worker, string $token): void {
    $this->database->insert(self::ATTEMPTS)->fields([
      'task_id' => $id,
      'phase' => $phase,
      'worker_id' => $worker,
      'token_hash' => hash('sha256', $token),
      'status' => 'running',
      'started' => $this->now(),
    ])->execute();
  }

  /**
   * Finishes an attempt while retaining its diagnostic history.
   */
  private function finishAttempt(string $hash, string $status, ?string $error = NULL): void {
    if ($hash !== '') {
      $this->database->update(self::ATTEMPTS)->fields([
        'status' => $status,
        'finished' => $this->now(),
        'error' => $error,
      ])
        ->condition('token_hash', $hash)->condition('status', 'running')->execute();
    }
  }

  /**
   * Names the short critical section shared by all translations of an entity.
   */
  private function entityLockKey(string $type, string $id): string {
    return 'genemedi_i18n:' . $type . ':' . $id;
  }

  /**
   * Takes an entity lock for a short database operation or HTTP PATCH.
   */
  private function acquire(string $key): void {
    if (!$this->lock->acquire($key, 120.0)) {
      throw new \RuntimeException('The entity is busy; retry after its current operation finishes.');
    }
  }

  /**
   * Releases a short critical-section lock.
   */
  private function release(string $key): void {
    $this->lock->release($key);
    unset($this->heldLocks[$key]);
  }

  /**
   * Gets wall time, including during long-running worker requests.
   */
  private function now(): int {
    return $this->time->getCurrentTime();
  }

  /**
   * Encodes data without silently losing malformed strings.
   */
  private function json(mixed $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * Normalizes object key order, preserving list and field item order.
   */
  private function canonical(mixed $value): string {
    $normalize = function (mixed $item) use (&$normalize): mixed {
      if (is_array($item)) {
        if (!array_is_list($item)) {
          ksort($item);
        }
        return array_map($normalize, $item);
      }
      return $item;
    };
    return $this->json($normalize($value));
  }

}
