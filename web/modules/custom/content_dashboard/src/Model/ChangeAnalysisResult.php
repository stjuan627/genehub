<?php

declare(strict_types=1);

namespace Drupal\content_dashboard\Model;

/**
 * Value object representing the outcome of a node change comparison.
 */
class ChangeAnalysisResult {

  /**
   * Constructs a ChangeAnalysisResult object.
   *
   * @param bool $isMajor
   *   Whether the change is classified as major.
   * @param array<int, string> $changeFlags
   *   Array of event flags (e.g. ['created'], ['title', 'body_major']).
   * @param string $summary
   *   Human-readable summary of the detected modifications.
   * @param array<string, mixed> $metrics
   *   Structured metrics comparing the two versions.
   */
  public function __construct(
    protected bool $isMajor,
    protected array $changeFlags,
    protected string $summary,
    protected array $metrics = [],
  ) {}

  /**
   * Gets whether this is a major change.
   */
  public function isMajor(): bool {
    return $this->isMajor;
  }

  /**
   * Gets the change event flags.
   *
   * @return array<int, string>
   *   List of change flags.
   */
  public function getChangeFlags(): array {
    return $this->changeFlags;
  }

  /**
   * Gets the comma-separated change flags string.
   */
  public function getChangeFlagsString(): string {
    return implode(',', $this->changeFlags);
  }

  /**
   * Gets the human-readable summary.
   */
  public function getSummary(): string {
    return $this->summary;
  }

  /**
   * Gets the quantitative diff metrics.
   *
   * @return array<string, mixed>
   *   Metrics array.
   */
  public function getMetrics(): array {
    return $this->metrics;
  }

  /**
   * Gets metrics encoded as JSON.
   */
  public function getMetricsJson(): string {
    $encoded = json_encode($this->metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded !== FALSE ? $encoded : '{}';
  }

}
