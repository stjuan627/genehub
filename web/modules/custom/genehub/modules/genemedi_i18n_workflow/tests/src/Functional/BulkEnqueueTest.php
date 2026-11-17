<?php

declare(strict_types=1);

namespace Drupal\Tests\genemedi_i18n_workflow\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\genehub_generic\Entity\GenericProduct;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\Tests\BrowserTestBase;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests selection, confirmation, language fan-out and duplicate protection.
 */
#[Group('genemedi_i18n_workflow')]
#[RunTestsInSeparateProcesses]
final class BulkEnqueueTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'genemedi_i18n_workflow', 'genehub_generic', 'genehub_solidex',
    'language', 'ai', 'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('ja')->save();
    $this->drupalCreateContentType(['type' => 'page', 'name' => 'Basic page']);
    FieldConfig::loadByName('node', 'page', 'body')->setTranslatable(TRUE)->save();
    foreach (['node' => 'page', 'product_generic' => 'product_generic', 'product_solidex' => 'product_solidex'] as $type => $bundle) {
      ContentLanguageSettings::loadByEntityTypeBundle($type, $bundle)
        ->setDefaultLangcode('en')->setLanguageAlterable(TRUE)
        ->setThirdPartySetting('content_translation', 'enabled', TRUE)->save();
    }
    $this->drupalLogin($this->drupalCreateUser([], NULL, TRUE));
  }

  /**
   * Tests current product lists and Views actions share confirmation and queue.
   */
  public function testBulkQueueFromProductListAndViews(): void {
    $first = GenericProduct::create(['langcode' => 'en', 'product_name' => 'Bulk product one']);
    $first->save();
    $second = GenericProduct::create(['langcode' => 'en', 'product_name' => 'Bulk product two']);
    $second->save();
    $this->drupalGet('/admin/content/products/generic');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Apply to selected items');
    $this->assertSession()->pageTextContains('Select at least one product');
    $this->submitForm([
      'entities[' . $first->id() . ']' => TRUE,
      'entities[' . $second->id() . ']' => TRUE,
    ], 'Apply to selected items');
    $this->assertSession()->addressEquals('/admin/content/genemedi-i18n-workflow/queue-selected');
    $this->assertSession()->pageTextContains('Bulk product one');
    $this->assertSession()->pageTextContains('Bulk product two');
    $this->assertSame(0, $this->taskCount());
    $this->submitForm([], 'Queue localization tasks');
    $this->assertSession()->pageTextContains('Select at least one target language');
    $this->assertSame(0, $this->taskCount());
    $this->submitForm(['target_languages[en]' => TRUE], 'Queue localization tasks');
    $this->assertSession()->pageTextContains('Target languages must differ from the source language.');
    $this->assertSame(0, $this->taskCount());
    $this->submitForm(['target_languages[en]' => FALSE, 'target_languages[fr]' => TRUE, 'markets[fr]' => ''], 'Queue localization tasks');
    $this->assertSession()->pageTextContains('Enter the market for');
    $this->assertSame(0, $this->taskCount());
    $this->submitForm([
      'target_languages[fr]' => TRUE,
      'target_languages[ja]' => TRUE,
      'markets[fr]' => 'FR',
    ], 'Queue localization tasks');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(4, $this->taskCount());
    $storage = $this->container->get('entity_type.manager')->getStorage('product_generic');
    $storage->resetCache();
    foreach ($storage->loadMultiple([$first->id(), $second->id()]) as $product) {
      $this->assertTrue($product->getTranslation('en')->isPublished());
      $this->assertFalse($product->getTranslation('fr')->isPublished());
      $this->assertFalse($product->getTranslation('ja')->isPublished());
    }
    // Repeating the selection reuses the four matching tasks.
    $this->drupalGet('/admin/content/products/generic');
    $this->submitForm([
      'entities[' . $first->id() . ']' => TRUE,
      'entities[' . $second->id() . ']' => TRUE,
    ], 'Apply to selected items');
    $this->submitForm(['target_languages[fr]' => TRUE, 'target_languages[ja]' => TRUE], 'Queue localization tasks');
    $this->assertSame(4, $this->taskCount());

    // Use the actual Views bulk form on the core content listing.
    $page = $this->drupalCreateNode(['type' => 'page', 'title' => 'Bulk basic page', 'langcode' => 'en']);
    $view = Views::getView('content');
    $this->assertNotNull($view);
    $this->drupalGet('/admin/content');
    $this->assertSession()->optionExists('action', 'genemedi_i18n_queue_node');
    $checkbox = $this->getSession()->getPage()->find('css', 'input[type="checkbox"][name^="node_bulk_form["]');
    $this->assertNotNull($checkbox);
    $checkbox->check();
    $this->getSession()->getPage()->selectFieldOption('action', 'genemedi_i18n_queue_node');
    $this->getSession()->getPage()->pressButton('Apply to selected items');
    $this->assertSession()->pageTextContains('Bulk basic page');
    $this->assertSame(4, $this->taskCount());
    $this->submitForm(['target_languages[fr]' => TRUE], 'Queue localization tasks');
    $this->assertSame(5, $this->taskCount());
    $task = $this->container->get('database')->select('genemedi_i18n_task', 't')
      ->fields('t')->condition('entity_type', 'node')->execute()->fetchAssoc();
    $this->assertSame((string) $page->id(), $task['entity_id']);
    $this->assertSame('fr', $task['target_lang']);
    $this->assertSame('FR', $task['market']);

    $this->drupalLogout();
    $this->drupalLogin($this->drupalCreateUser(['administer generic products']));
    $this->drupalGet('/admin/content/products/generic');
    $this->assertSession()->buttonNotExists('Apply to selected items');
    $this->drupalGet('/admin/content/genemedi-i18n-workflow/queue-selected');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests another tab cannot change the selection shown by an open form.
   */
  public function testConfirmationKeepsItsOwnSelection(): void {
    $first = GenericProduct::create(['langcode' => 'en', 'product_name' => 'First tab product']);
    $first->save();
    $second = GenericProduct::create(['langcode' => 'en', 'product_name' => 'Second tab product']);
    $second->save();
    $this->drupalGet('/admin/content/products/generic');
    $this->submitForm(['entities[' . $first->id() . ']' => TRUE], 'Apply to selected items');
    $this->assertSession()->pageTextContains('First tab product');
    // Simulate another tab submitting a different selection for the same user,
    // without rebuilding or validating the already open confirmation form.
    $this->container->get('current_user')->setAccount($this->loggedInUser);
    $this->container->get('tempstore.private')->get('genemedi_i18n_bulk')->set('selection:other-tab', [
      'product_generic' => [$second->id() => (string) $second->id()],
    ]);
    $this->container->get('tempstore.private')->get('genemedi_i18n_bulk')->set('latest_selection', 'other-tab');
    $this->submitForm(['target_languages[fr]' => TRUE], 'Queue localization tasks');
    $this->assertSame(1, $this->taskCount());
    $task = $this->container->get('database')->select('genemedi_i18n_task', 't')
      ->fields('t')->execute()->fetchAssoc();
    $this->assertSame((string) $first->id(), $task['entity_id']);
  }

  /**
   * Counts persisted tasks independently of the UI's success messages.
   */
  private function taskCount(): int {
    return (int) $this->container->get('database')->select('genemedi_i18n_task', 't')->countQuery()->execute()->fetchField();
  }

}
