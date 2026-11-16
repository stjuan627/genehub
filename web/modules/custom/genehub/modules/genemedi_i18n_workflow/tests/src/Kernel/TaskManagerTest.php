<?php

declare(strict_types=1);

namespace Drupal\Tests\genemedi_i18n_workflow\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\genemedi_i18n_workflow\TaskManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests durable multilingual task transitions against real entity storage.
 */
#[Group('genemedi_i18n_workflow')]
#[RunTestsInSeparateProcesses]
final class TaskManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system', 'user', 'node', 'field', 'text', 'filter', 'file', 'language',
    'content_translation', 'serialization', 'jsonapi', 'basic_auth',
    'genemedi_i18n_workflow',
  ];

  /**
   * Controlled wall clock, so lease-expiration tests need no sleeps.
   */
  private int $now = 1800000000;

  /**
   * The task service under test.
   */
  private TaskManager $tasks;

  /**
   * The published English source.
   */
  private Node $source;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentTime')->willReturnCallback(fn(): int => $this->now);
    $clock->method('getRequestTime')->willReturnCallback(fn(): int => $this->now);
    $clock->method('getCurrentMicroTime')->willReturnCallback(fn(): float => (float) $this->now);
    $clock->method('getRequestMicroTime')->willReturnCallback(fn(): float => (float) $this->now);
    $this->container->set('datetime.time', $clock);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('genemedi_i18n_workflow', ['genemedi_i18n_task', 'genemedi_i18n_attempt']);
    $this->installConfig(['system', 'user', 'node', 'filter', 'language', 'jsonapi']);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('ja')->save();
    $type = NodeType::create(['type' => 'page', 'name' => 'Basic page', 'new_revision' => TRUE]);
    $type->save();
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'body',
      'type' => 'text_with_summary',
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'page',
      'field_name' => 'body',
      'label' => 'Body',
      'translatable' => TRUE,
    ])->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'page')
      ->setDefaultLangcode('en')->setLanguageAlterable(TRUE)
      ->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML'])->save();
    Role::create([
      'id' => 'workflow_admin',
      'label' => 'Workflow administrator',
      'permissions' => ['bypass node access', 'administer genemedi i18n workflow', 'execute genemedi i18n workflow'],
    ])->save();
    $account = User::create(['name' => 'workflow-test', 'status' => 1, 'roles' => ['workflow_admin']]);
    $account->save();
    $this->container->get('current_user')->setAccount($account);
    $this->source = Node::create([
      'type' => 'page',
      'langcode' => 'en',
      'title' => 'Test source',
      'status' => TRUE,
      'uid' => $account->id(),
      'body' => ['value' => '<section><p>Purity &gt;95%.</p></section>', 'format' => 'full_html', 'summary' => ''],
    ]);
    $this->source->save();
    $this->container->get('router.builder')->rebuild();
    $this->tasks = $this->container->get('genemedi_i18n_workflow.task_manager');
  }

  /**
   * Tests target preparation, deduplication, and source-only hashing.
   */
  public function testEnqueuePreparesUnpublishedTargetsIdempotently(): void {
    $batch = $this->enqueueBoth();
    $this->assertCount(2, $batch['tasks']);
    $entity = $this->reload();
    $this->assertTrue($entity->isPublished());
    $this->assertFalse($entity->getTranslation('fr')->isPublished());
    $this->assertFalse($entity->getTranslation('ja')->isPublished());
    $this->assertSame('en', $entity->getUntranslated()->language()->getId());
    $hash = $batch['tasks'][0]['source_hash'];
    $this->assertSame($hash, $batch['tasks'][1]['source_hash']);
    $repeated = $this->enqueueBoth();
    $this->assertSame(array_column($batch['tasks'], 'id'), array_column($repeated['tasks'], 'id'));
    $this->assertCount(2, $this->tasks->listTasks());
    $entity->getTranslation('ja')->set('title', '日本語')->setNewRevision(TRUE);
    $entity->getTranslation('ja')->save();
    $snapshot = $this->container->get('genemedi_i18n_workflow.snapshot')->capture($this->reload(), 'en');
    $this->assertSame($hash, $snapshot['source_hash']);
  }

  /**
   * Tests that existing published translations are not reset during enqueue.
   */
  public function testExistingTranslationIsNotUnpublishedOrOverwritten(): void {
    $this->source->addTranslation('fr', [
      'title' => 'Existing French',
      'status' => TRUE,
      'body' => ['value' => '<p>Existing French content.</p>', 'format' => 'full_html', 'summary' => ''],
    ])->save();
    $this->enqueueBoth();
    $translation = $this->reload()->getTranslation('fr');
    $this->assertTrue($translation->isPublished());
    $this->assertSame('<p>Existing French content.</p>', $translation->get('body')->value);
  }

  /**
   * Tests live token checks, lease expiry, and stale-owner fencing.
   */
  public function testExpiredWorkerCannotOverwriteReclaimedTask(): void {
    $this->enqueueOne();
    $first = $this->tasks->claim('worker-a', 30);
    $this->assertNotNull($first);
    $this->assertNull($this->tasks->claim('worker-b', 30));
    $this->assertThrows(fn() => $this->tasks->complete($first['id'], 'wrong-token', $this->validResult($first)), 'lease');
    $this->now += 31;
    $this->assertThrows(fn() => $this->tasks->heartbeat($first['id'], $first['token'], 30), 'lease');
    $second = $this->tasks->claim('worker-b', 30);
    $this->assertSame($first['id'], $second['id']);
    $this->assertNotSame($first['token'], $second['token']);
    $this->assertSame(2, $second['attempt_count']);
    $this->assertThrows(fn() => $this->tasks->complete($first['id'], $first['token'], $this->validResult($first)), 'lease');
    $ready = $this->tasks->complete($second['id'], $second['token'], $this->validResult($second));
    $this->assertSame('ready_for_import', $ready['status']);
    $this->assertArrayNotHasKey('token_hash', $ready);
  }

  /**
   * Tests quality attestations and structural validation before persistence.
   */
  public function testCompleteRequiresChecksAndPreservesStructure(): void {
    $this->enqueueOne();
    $task = $this->tasks->claim('worker-a');
    $result = $this->validResult($task);
    $invalid = $result;
    $invalid['checks']['facts_passed'] = FALSE;
    $this->assertThrows(fn() => $this->tasks->complete($task['id'], $task['token'], $invalid), 'check');
    $invalid = $result;
    $invalid['fields']['body'][0]['value'] = '<p>Purity &gt;95%.</p>';
    $this->assertThrows(fn() => $this->tasks->complete($task['id'], $task['token'], $invalid), 'structure');
    $this->assertNull($this->tasks->getTask($task['id'])['result']);
    $ready = $this->tasks->complete($task['id'], $task['token'], $result);
    $this->assertSame($result, $ready['result']);
    $this->assertSame($ready, $this->tasks->complete($task['id'], $task['token'], $result));
  }

  /**
   * Tests two language import leases cannot write the same entity at once.
   */
  public function testImportLeaseSerializesLanguagesAndBuildsCorePatch(): void {
    $this->enqueueBoth();
    $first = $this->tasks->claim('worker-a');
    $second = $this->tasks->claim('worker-b');
    $this->tasks->complete($first['id'], $first['token'], $this->validResult($first));
    $this->tasks->complete($second['id'], $second['token'], $this->validResult($second));
    $import = $this->tasks->beginImport($first['id'], 'import-a', 30);
    $this->assertSame('PATCH', $import['patch']['method']);
    $this->assertSame($this->source->uuid(), $import['patch']['payload']['data']['id']);
    $this->assertTrue($import['patch']['payload']['data']['attributes']['status']);
    $this->assertSame((string) $first['id'], $import['patch']['headers']['X-Genemedi-I18n-Task']);
    $this->assertThrows(fn() => $this->tasks->beginImport($second['id'], 'import-b', 30), 'entity lease');
    $this->now += 31;
    $other_import = $this->tasks->beginImport($second['id'], 'import-b', 30);
    $this->assertSame('importing', $other_import['status']);
  }

  /**
   * Tests source modifications reject a previously generated completion.
   */
  public function testChangedSourceRejectsCompletion(): void {
    $this->enqueueOne();
    $task = $this->tasks->claim('worker-a');
    $this->changeSource();
    $this->assertThrows(fn() => $this->tasks->complete($task['id'], $task['token'], $this->validResult($task)), 'Source content');
    $this->assertNull($this->tasks->getTask($task['id'])['result']);
  }

  /**
   * Tests source modifications after generation also reject import.
   */
  public function testChangedSourceRejectsImport(): void {
    $this->enqueueOne();
    $task = $this->tasks->claim('worker-a');
    $this->tasks->complete($task['id'], $task['token'], $this->validResult($task));
    $this->changeSource();
    $this->assertThrows(fn() => $this->tasks->beginImport($task['id'], 'import-a'), 'Source content');
    $this->assertSame('ready_for_import', $this->tasks->getTask($task['id'])['status']);
  }

  /**
   * Tests deliberate regeneration supersedes old execution credentials.
   */
  public function testRegenerationFencesSupersededWorker(): void {
    $this->enqueueOne();
    $old = $this->tasks->claim('worker-a');
    $new = $this->tasks->enqueue('node', (string) $this->source->id(), 'en', [
      ['langcode' => 'fr', 'market' => 'FR', 'regenerate' => TRUE],
    ])['tasks'][0];
    $this->assertNotSame($old['id'], $new['id']);
    $this->assertSame('superseded', $this->tasks->getTask($old['id'])['status']);
    $this->assertThrows(fn() => $this->tasks->complete($old['id'], $old['token'], $this->validResult($old)), 'lease');
    $this->assertSame($new['id'], $this->tasks->claim('worker-b')['id']);
  }

  /**
   * Tests import retries preserve generated content and stop after the limit.
   */
  public function testImportFailuresReuseResultAndExhaustIndependently(): void {
    $this->enqueueOne();
    $task = $this->tasks->claim('writer');
    $result = $this->validResult($task);
    $this->tasks->complete($task['id'], $task['token'], $result);
    for ($attempt = 1; $attempt <= 5; $attempt++) {
      $import = $this->tasks->claimImport('importer', 30);
      $this->assertNotNull($import);
      $this->assertSame($attempt, $import['import_attempt_count']);
      $failed = $this->tasks->fail($task['id'], $import['token'], 'Temporary import error', TRUE);
      $this->assertSame($result, $failed['result']);
      $this->assertSame(1, $failed['attempt_count']);
      $this->assertSame($attempt < 5 ? 'ready_for_import' : 'import_failed', $failed['status']);
      $this->assertNull($this->tasks->claim('another-writer'));
      $this->assertNull($this->tasks->claimImport('another-importer'));
      $this->now = $failed['available_at'];
    }
    $this->assertNull($this->tasks->claimImport('another-importer'));
  }

  /**
   * Tests generation failures enforce delayed retries and a finite limit.
   */
  public function testGenerationFailureBackoffAndLimit(): void {
    $this->enqueueOne();
    for ($attempt = 1; $attempt <= 5; $attempt++) {
      $task = $this->tasks->claim('writer', 30);
      $this->assertSame($attempt, $task['attempt_count']);
      $failed = $this->tasks->fail($task['id'], $task['token'], 'Temporary generation error', TRUE);
      $this->assertSame($attempt < 5 ? 'queued' : 'failed', $failed['status']);
      $this->assertNull($failed['result']);
      $this->assertNull($this->tasks->claim('another-writer'));
      $this->now = $failed['available_at'];
    }
    $this->assertNull($this->tasks->claim('another-writer'));
  }

  /**
   * Queues two independent target languages.
   */
  private function enqueueBoth(): array {
    return $this->tasks->enqueue('node', (string) $this->source->id(), 'en', [
      ['langcode' => 'fr', 'market' => 'FR'],
      ['langcode' => 'ja', 'market' => 'JP'],
    ]);
  }

  /**
   * Queues a single target language.
   */
  private function enqueueOne(): array {
    return $this->tasks->enqueue('node', (string) $this->source->id(), 'en', [
      ['langcode' => 'fr', 'market' => 'FR'],
    ]);
  }

  /**
   * Creates a minimal complete result using the task's fixed source fields.
   */
  private function validResult(array $task): array {
    return [
      'fields' => $task['snapshot']['fields'],
      'checks' => ['structure_passed' => TRUE, 'facts_passed' => TRUE, 'details_preserved' => TRUE],
      'research' => [['query' => 'pureté produit', 'url' => 'https://example.test/evidence']],
    ];
  }

  /**
   * Changes actual source text rather than only a revision identifier.
   */
  private function changeSource(): void {
    $entity = $this->reload();
    $entity->set('body', [
      'value' => '<section><p>Updated purity &gt;99%.</p></section>',
      'format' => 'full_html',
      'summary' => '',
    ]);
    $entity->setNewRevision(TRUE);
    $entity->save();
  }

  /**
   * Loads a fresh node from real entity storage.
   */
  private function reload(): Node {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$this->source->id()]);
    return $storage->load($this->source->id());
  }

  /**
   * Asserts an operation fails without aborting the rest of its scenario.
   */
  private function assertThrows(callable $operation, string $message): void {
    try {
      $operation();
      $this->fail('The invalid operation was accepted.');
    }
    catch (\RuntimeException | \InvalidArgumentException $exception) {
      $this->assertStringContainsString($message, $exception->getMessage());
    }
  }

}
