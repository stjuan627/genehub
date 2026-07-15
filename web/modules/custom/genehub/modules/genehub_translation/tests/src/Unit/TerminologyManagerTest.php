<?php

declare(strict_types=1);

namespace Drupal\Tests\genehub_translation\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\genehub_translation\TerminologyManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests terminology CSV parsing.
 */
#[Group('genehub_translation')]
final class TerminologyManagerTest extends UnitTestCase {

  /**
   * Tests classification, de-duplication, and longest-first ordering.
   */
  public function testParseTerminology(): void {
    $manager = new TerminologyManager($this->getConfigFactoryStub());
    $parsed = $manager->parse(<<<'CSV'
source_term,target_term
AAV,AAV
AAVPure Affinity Resin,AAVPure 亲和树脂
AAV,AAV
AAV 亲和层析填料,AAV 亲和树脂
CSV);

    $this->assertSame(
      ['AAVPure Affinity Resin', 'AAV'],
      array_keys($parsed['terms']),
    );
    $this->assertSame(
      ['AAV 亲和层析填料'],
      array_keys($parsed['normalizations']),
    );
  }

  /**
   * Tests that conflicting duplicate sources are rejected.
   */
  public function testParseRejectsConflicts(): void {
    $manager = new TerminologyManager($this->getConfigFactoryStub());
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('conflicts with an earlier mapping');
    $manager->parse(<<<'CSV'
source_term,target_term
Column,分选柱
Column,柱式
CSV);
  }

  /**
   * Tests that the exact two-column header is required.
   */
  public function testParseRejectsInvalidHeader(): void {
    $manager = new TerminologyManager($this->getConfigFactoryStub());
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('source_term,target_term');
    $manager->parse("source,target\nAAV,AAV\n");
  }

}
