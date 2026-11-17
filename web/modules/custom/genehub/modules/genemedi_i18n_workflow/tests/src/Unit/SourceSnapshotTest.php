<?php

declare(strict_types=1);

namespace Drupal\Tests\genemedi_i18n_workflow\Unit;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\genemedi_i18n_workflow\SourceSnapshot;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests field contracts and immutable product attributes.
 */
#[Group('genemedi_i18n_workflow')]
final class SourceSnapshotTest extends UnitTestCase {

  /**
   * Tests natural wording with identical markup, facts, and field properties.
   */
  public function testAcceptsLocalizedWording(): void {
    $snapshot = $this->snapshot();
    $fields = $snapshot['fields'];
    $fields['sections'][0]['heading'] = '概要';
    $fields['sections'][0]['body'] = '<p class="intro">純度 &gt;95%、濃度 5 µg/mL。</p><ul><li>30 min、4 °C。</li></ul>';
    $fields['sales_units'][0]['label'] = '標準パック';
    (new SourceSnapshot())->validate($snapshot, $fields);
    $this->addToAssertionCount(1);
  }

  /**
   * Tests rejection of field contract and protected attribute changes.
   */
  #[DataProvider('invalidResultProvider')]
  public function testRejectsInvalidResults(string $case): void {
    $snapshot = $this->snapshot();
    $fields = $snapshot['fields'];
    switch ($case) {
      case 'field_missing':
        unset($fields['sales_units']);
        break;

      case 'field_added':
        $fields['new_field'] = [['value' => 'Invented content']];
        break;

      case 'section_removed':
        $fields['sections'] = [];
        break;

      case 'format_changed':
        $fields['sections'][0]['format'] = 'restricted_html';
        break;

      case 'price_changed':
        $fields['sales_units'][0]['price'] = '1';
        break;

      case 'sku_changed':
        $fields['sales_units'][0]['sku'] = 'NEW-SKU';
        break;

      case 'item_property_added':
        $fields['sections'][0]['unexpected'] = 'New';
        break;

      case 'invalid_text_type':
        $fields['sections'][0]['body'] = ['unexpected' => 'object'];
        break;

    }
    $this->expectException(\InvalidArgumentException::class);
    (new SourceSnapshot())->validate($snapshot, $fields);
  }

  /**
   * Provides independent invalid result cases.
   */
  public static function invalidResultProvider(): array {
    $cases = [
      'field_missing', 'field_added', 'section_removed', 'format_changed',
      'price_changed', 'sku_changed', 'item_property_added', 'invalid_text_type',
    ];
    return array_combine($cases, array_map(static fn(string $case): array => [$case], $cases));
  }

  /**
   * Tests prose changes are reviewed by the agent, not rejected by heuristics.
   */
  public function testDoesNotGateProseOrDomChanges(): void {
    $snapshot = $this->snapshot();
    $fields = $snapshot['fields'];
    $fields['sections'][0]['body'] = '<div>纯度99%，浓度5毫克/升。</div>';
    $fields['sections'][0]['heading'] = '';
    (new SourceSnapshot())->validate($snapshot, $fields);
    $snapshot['fields']['sections'][0]['heading'] = NULL;
    $fields['sections'][0]['heading'] = '本地化标题';
    (new SourceSnapshot())->validate($snapshot, $fields);
    $this->addToAssertionCount(2);
  }

  /**
   * Tests the real task 16 CJK word-order regression without numeric parsing.
   */
  public function testAcceptsChineseWordOrderForAavIdentifiers(): void {
    $snapshot = $this->snapshot();
    $snapshot['fields']['sections'][0]['body'] = '<p>The PAGE of purified AAV9 by PurProX® AAVPure Affinity Resin-2 (GMV-PurProX-AAVPure-U-2).</p>';
    $fields = $snapshot['fields'];
    $fields['sections'][0]['body'] = '<p>使用 PurProX® AAVPure 亲和填料-2（GMV-PurProX-AAVPure-U-2）纯化 AAV9 后的 PAGE 结果。</p>';
    (new SourceSnapshot())->validate($snapshot, $fields);
    $this->addToAssertionCount(1);
  }

  /**
   * Tests source hashes ignore metadata while detecting source changes.
   */
  public function testCaptureHashAndNodeTitleBodyScope(): void {
    $capture = new SourceSnapshot();
    $first = $capture->capture($this->sourceEntity('Source', '1', 5), 'en');
    $next_revision = $capture->capture($this->sourceEntity('Source', '2', 6), 'en');
    $changed = $capture->capture($this->sourceEntity('Changed', '2', 6), 'en');
    $this->assertSame($first['source_hash'], $next_revision['source_hash']);
    $this->assertNotSame($first['source_hash'], $changed['source_hash']);
    $this->assertSame(['body', 'title'], array_keys($first['fields']));
    $this->assertSame([['value' => 'A page title']], $first['fields']['title']);
    $this->assertSame(['value'], $first['mapping']['title']['translatable_properties']);
    $this->assertSame([], $first['mapping']['title']['html_properties']);
    $this->assertArrayNotHasKey('title', $first['context']);
    $this->assertArrayNotHasKey('changed', $first['context']);
    $this->assertSame(5, $first['source_revision']);
  }

  /**
   * Builds a mock node without a Drupal service container.
   */
  private function sourceEntity(string $body, string $changed, int $revision): ContentEntityInterface {
    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('isRevisionable')->willReturn(TRUE);
    $keys = ['id' => 'nid', 'label' => 'title', 'uuid' => 'uuid', 'revision' => 'vid'];
    $entity_type->method('getKeys')->willReturn($keys);
    $entity_type->method('getKey')->willReturnCallback(static fn(string $key) => $keys[$key] ?? FALSE);

    $body_definition = $this->createMock(FieldDefinitionInterface::class);
    $body_definition->method('getType')->willReturn('text_with_summary');
    $body_definition->method('isTranslatable')->willReturn(TRUE);
    $title_definition = $this->createMock(FieldDefinitionInterface::class);
    $title_definition->method('getType')->willReturn('string');
    $title_definition->method('isTranslatable')->willReturn(TRUE);
    $changed_definition = clone $body_definition;
    $definitions = ['body' => $body_definition, 'title' => $title_definition, 'changed' => $changed_definition];
    $values = [
      'body' => [['value' => $body, 'format' => 'full_html', 'summary' => '']],
      'title' => [['value' => 'A page title']],
      'changed' => [['value' => $changed]],
    ];
    $entity = $this->createMock(ContentEntityInterface::class);
    $entity->method('hasTranslation')->with('en')->willReturn(TRUE);
    $entity->method('getTranslation')->with('en')->willReturnSelf();
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('getEntityType')->willReturn($entity_type);
    $entity->method('bundle')->willReturn('page');
    $entity->method('id')->willReturn('1');
    $entity->method('uuid')->willReturn('test-uuid');
    $entity->method('getRevisionId')->willReturn($revision);
    $entity->method('getFieldDefinitions')->willReturn($definitions);
    $entity->method('get')->willReturnCallback(function (string $field) use ($values) {
      $items = $this->createMock(FieldItemListInterface::class);
      $items->method('getValue')->willReturn($values[$field]);
      return $items;
    });
    return $entity;
  }

  /**
   * Builds a representative product snapshot.
   */
  private function snapshot(): array {
    return [
      'fields' => [
        'sections' => [[
          'heading' => 'Overview',
          'body' => '<p class="intro">Purity &gt;95%; 5 µg/mL.</p><ul><li>Run for 30 min at 4 °C.</li></ul>',
          'format' => 'full_html',
        ],
        ],
        'sales_units' => [[
          'sku' => 'SKU-123',
          'label' => 'Standard pack',
          'size' => '5',
          'unit' => 'mL',
          'price' => '100',
        ],
        ],
      ],
      'mapping' => [
        'sections' => [
          'type' => 'genehub_section',
          'translatable_properties' => ['heading', 'body'],
          'html_properties' => ['body'],
        ],
        'sales_units' => [
          'type' => 'genehub_sales_unit',
          'translatable_properties' => ['label'],
          'html_properties' => [],
        ],
      ],
    ];
  }

}
