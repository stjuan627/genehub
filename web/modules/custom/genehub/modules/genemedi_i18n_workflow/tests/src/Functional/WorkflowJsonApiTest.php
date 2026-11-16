<?php

declare(strict_types=1);

namespace Drupal\Tests\genemedi_i18n_workflow\Functional;

use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\node\Entity\Node;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Exercises the real JSON:API converter, PATCH controller, save and response.
 */
#[Group('genemedi_i18n_workflow')]
#[RunTestsInSeparateProcesses]
final class WorkflowJsonApiTest extends BrowserTestBase {

  use ApiRequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['genemedi_i18n_workflow', 'language', 'ai'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The authenticated worker account used for both workflow and core requests.
   */
  private UserInterface $worker;

  /**
   * The temporary test account password.
   */
  private string $password;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    // Deliberately disable automatic bundle revisions: the workflow must force
    // a new revision even when the ordinary JSON:API default would not.
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page', 'new_revision' => FALSE]);
    FieldConfig::loadByName('node', 'page', 'body')->setTranslatable(TRUE)->save();
    ContentLanguageSettings::loadByEntityTypeBundle('node', 'page')
      ->setDefaultLangcode('en')->setLanguageAlterable(TRUE)
      ->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
    FilterFormat::create(['format' => 'full_html', 'name' => 'Full HTML'])->save();
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    $account = $this->drupalCreateUser([
      'access content',
      'administer nodes',
      'bypass node access',
      'administer genemedi i18n workflow',
      'execute genemedi i18n workflow',
      'use text format full_html',
    ]);
    $this->assertInstanceOf(UserInterface::class, $account);
    $this->worker = $account;
    $this->password = $account->passRaw;
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * Tests a French import creates one revision and cannot be applied twice.
   */
  public function testCorePatchPublishesExactTargetOnceWithNewRevision(): void {
    $source_html = '<section><p>Purity &gt;95%.</p></section>';
    $target_html = '<section><p>Pureté &gt;95%.</p></section>';
    $source = Node::create([
      'type' => 'page',
      'langcode' => 'en',
      'title' => 'English source',
      'status' => TRUE,
      'uid' => $this->worker->id(),
      'body' => ['value' => $source_html, 'format' => 'full_html', 'summary' => ''],
    ]);
    $source->save();

    $batch = $this->api('/api/genemedi-i18n/tasks', [
      'entity_type' => 'node',
      'entity_id' => (string) $source->id(),
      'source_lang' => 'en',
      'targets' => [['langcode' => 'fr', 'market' => 'FR']],
    ], 201);
    $this->assertCount(1, $batch['tasks']);
    $task_id = $batch['tasks'][0]['id'];
    $prepared = $this->reloadNode($source->id());
    $this->assertTrue($prepared->getTranslation('en')->isPublished());
    $this->assertFalse($prepared->getTranslation('fr')->isPublished());

    $claimed = $this->api('/api/genemedi-i18n/tasks/claim', ['worker_id' => 'test-writer']);
    $task = $claimed['task'];
    $this->assertSame($task_id, $task['id']);
    $fields = $task['snapshot']['fields'];
    $fields['body'][0]['value'] = $target_html;
    $ready = $this->api('/api/genemedi-i18n/tasks/' . $task_id . '/complete', [
      'token' => $task['token'],
      'result' => [
        'fields' => $fields,
        'research' => [['query' => 'pureté produit', 'url' => 'https://example.test/research']],
        'checks' => ['structure_passed' => TRUE, 'facts_passed' => TRUE, 'details_preserved' => TRUE],
      ],
    ]);
    $this->assertSame('ready_for_import', $ready['status']);

    $claimed_import = $this->api('/api/genemedi-i18n/imports/claim', ['worker_id' => 'test-importer']);
    $import = $claimed_import['task'];
    $this->assertSame($task_id, $import['id']);
    $before = $this->reloadNode($source->id());
    $revision_before = (string) $before->getRevisionId();
    $document = $this->api($import['patch']['url'], $import['patch']['payload'], 200, 'PATCH', $import['patch']['headers']);
    $this->assertSame($source->uuid(), $document['data']['id']);
    $this->assertSame('fr', $document['data']['attributes']['langcode']);
    $this->assertTrue($document['data']['attributes']['status']);
    $this->assertSame($target_html, $document['data']['attributes']['body']['value']);
    // Building the actual JSON:API response links re-enters parameter
    // conversion; this catches failures after a successful entity save.
    $this->assertArrayHasKey('self', $document['links']);

    $saved = $this->reloadNode($source->id());
    $revision_after = (string) $saved->getRevisionId();
    $this->assertNotSame($revision_before, $revision_after);
    $this->assertSame($source_html, $saved->getTranslation('en')->get('body')->value);
    $this->assertSame('English source', $saved->getTranslation('en')->label());
    $this->assertTrue($saved->getTranslation('en')->isPublished());
    $this->assertSame($target_html, $saved->getTranslation('fr')->get('body')->value);
    $this->assertTrue($saved->getTranslation('fr')->isPublished());

    $this->api($import['patch']['url'], $import['patch']['payload'], 409, 'PATCH', $import['patch']['headers']);
    $this->assertSame($revision_after, (string) $this->reloadNode($source->id())->getRevisionId());
    $published = $this->api('/api/genemedi-i18n/tasks/' . $task_id . '/acknowledge', ['token' => $import['token']]);
    $this->assertSame('published', $published['status']);
    $this->assertSame($revision_after, $published['applied_revision']);
    $this->assertSame($revision_before, $published['revision_before']);
    $this->assertArrayNotHasKey('token_hash', $published);
    $this->assertNull($this->api('/api/genemedi-i18n/imports/claim', ['worker_id' => 'another-importer'])['task']);

    $this->drupalLogin($this->worker);
    $this->drupalGet('/admin/content/genemedi-i18n-workflow');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Queue localization task');
    $this->drupalGet('/admin/content/genemedi-i18n-workflow/queue');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Queue localization tasks');
  }

  /**
   * Calls an authenticated endpoint and checks the actual HTTP response.
   */
  private function api(string $path, array $body, int $expected_status = 200, string $method = 'POST', array $headers = []): array {
    $response = $this->makeApiRequest($method, Url::fromUserInput($path), [
      RequestOptions::AUTH => [$this->worker->getAccountName(), $this->password],
      RequestOptions::HEADERS => $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
      RequestOptions::BODY => json_encode($body, JSON_THROW_ON_ERROR),
    ]);
    $content = (string) $response->getBody();
    $this->assertSame($expected_status, $response->getStatusCode(), $content);
    return json_decode($content, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Reloads the latest stored node after a request served by another process.
   */
  private function reloadNode(int|string $id): Node {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$id]);
    return $storage->load($id);
  }

}
