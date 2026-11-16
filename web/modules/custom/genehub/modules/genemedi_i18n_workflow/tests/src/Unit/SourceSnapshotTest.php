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
 * Tests preservation of source structure and product facts.
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
   * Tests rejection of data changes, omissions, and layout modifications.
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

      case 'attribute_changed':
        $fields['sections'][0]['body'] = str_replace('intro', 'different', $fields['sections'][0]['body']);
        break;

      case 'structure_changed':
        $fields['sections'][0]['body'] = str_replace(['<ul>', '</ul>'], ['<ol>', '</ol>'], $fields['sections'][0]['body']);
        break;

      case 'value_changed':
        $fields['sections'][0]['body'] = str_replace('95%', '99%', $fields['sections'][0]['body']);
        break;

      case 'comparison_changed':
        $fields['sections'][0]['body'] = str_replace('&gt;', '≥', $fields['sections'][0]['body']);
        break;

      case 'unit_changed':
        $fields['sections'][0]['body'] = str_replace('µg/mL', 'mg/mL', $fields['sections'][0]['body']);
        break;

      case 'denominator_changed':
        $fields['sections'][0]['body'] = str_replace('µg/mL', 'µg/L', $fields['sections'][0]['body']);
        break;

      case 'time_unit_changed':
        $fields['sections'][0]['body'] = str_replace('30 min', '30 h', $fields['sections'][0]['body']);
        break;

      case 'details_removed':
        $fields['sections'][0]['body'] = '<p class="intro">A simplified description.</p><ul><li>One detail.</li></ul>';
        break;

      case 'item_property_added':
        $fields['sections'][0]['unexpected'] = 'New';
        break;

      case 'text_removed':
        $fields['sections'][0]['heading'] = '';
        break;

      case 'data_moved_between_text_positions':
        $fields['sections'][0]['body'] = '<p class="intro">Run for 30 min at 4 °C.</p><ul><li>Purity &gt;95%; 5 µg/mL.</li></ul>';
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
      'price_changed', 'sku_changed', 'attribute_changed', 'structure_changed',
      'value_changed', 'comparison_changed', 'unit_changed', 'denominator_changed',
      'time_unit_changed', 'details_removed', 'item_property_added',
      'text_removed', 'data_moved_between_text_positions',
    ];
    return array_combine($cases, array_map(static fn(string $case): array => [$case], $cases));
  }

  /**
   * Tests that an empty source cannot be filled with invented copy.
   */
  public function testRejectsInventedEmptyValue(): void {
    $snapshot = $this->snapshot();
    $snapshot['fields']['sections'][0]['heading'] = NULL;
    $fields = $snapshot['fields'];
    $fields['sections'][0]['heading'] = 'A new heading';
    $this->expectException(\InvalidArgumentException::class);
    (new SourceSnapshot())->validate($snapshot, $fields);
  }

  /**
   * Tests units are protected when inline markup separates them from numbers.
   */
  public function testRejectsUnitChangedAcrossInlineElements(): void {
    $snapshot = $this->snapshot();
    $snapshot['fields']['sections'][0]['body'] = '<p>Capacity <strong>5</strong> mL.</p>';
    $fields = $snapshot['fields'];
    $fields['sections'][0]['body'] = '<p>容量 <strong>5</strong> L.</p>';
    $this->expectException(\InvalidArgumentException::class);
    (new SourceSnapshot())->validate($snapshot, $fields);
  }

  /**
   * Tests source hashes ignore metadata while detecting source changes.
   */
  public function testCaptureHashAndNodeBodyScope(): void {
    $capture = new SourceSnapshot();
    $first = $capture->capture($this->sourceEntity('Source', '1', 5), 'en');
    $next_revision = $capture->capture($this->sourceEntity('Source', '2', 6), 'en');
    $changed = $capture->capture($this->sourceEntity('Changed', '2', 6), 'en');
    $this->assertSame($first['source_hash'], $next_revision['source_hash']);
    $this->assertNotSame($first['source_hash'], $changed['source_hash']);
    $this->assertSame(['body'], array_keys($first['fields']));
    $this->assertArrayHasKey('title', $first['context']);
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
    $title_definition = clone $body_definition;
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
