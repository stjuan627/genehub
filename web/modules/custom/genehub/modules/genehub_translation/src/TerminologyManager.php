<?php

declare(strict_types=1);

namespace Drupal\genehub_translation;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Parses and provides the configured English-to-Chinese terminology.
 */
final class TerminologyManager {

  /**
   * The configuration object name.
   */
  public const CONFIG_NAME = 'genehub_translation.settings';

  /**
   * The expected CSV header.
   */
  private const HEADER = ['source_term', 'target_term'];

  /**
   * Cached parsed terminology for this request.
   *
   * @var array{terms: array<string, string>, normalizations: array<string, string>}|null
   */
  private ?array $terminology = NULL;

  /**
   * Constructs the terminology manager.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Gets the configured terminology grouped by its role in the pipeline.
   *
   * Sources containing Chinese characters are output normalization aliases.
   * All other sources are protected source-language terminology.
   *
   * @return array{terms: array<string, string>, normalizations: array<string, string>}
   *   The protected terms and Chinese output normalizations.
   */
  public function getTerminology(): array {
    if ($this->terminology === NULL) {
      $this->terminology = $this->parse($this->getTerminologyText());
    }

    return $this->terminology;
  }

  /**
   * Gets configured CSV text, falling back to the bundled initial glossary.
   */
  public function getTerminologyText(): string {
    $value = (string) $this->configFactory
      ->get(self::CONFIG_NAME)
      ->get('terminology');
    if (trim($value) !== '') {
      return $value;
    }

    $defaultFile = dirname(__DIR__) . '/data/gene-product-terminology.csv';
    $default = file_get_contents($defaultFile);
    if ($default === FALSE) {
      throw new \RuntimeException(sprintf('Unable to read default terminology from %s.', $defaultFile));
    }
    return $default;
  }

  /**
   * Parses terminology CSV text.
   *
   * @param string $value
   *   CSV with source_term and target_term columns.
   *
   * @return array{terms: array<string, string>, normalizations: array<string, string>}
   *   Parsed and longest-first terminology maps.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the CSV is malformed or contains conflicting sources.
   */
  public function parse(string $value): array {
    if (trim($value) === '') {
      return ['terms' => [], 'normalizations' => []];
    }

    $stream = fopen('php://temp', 'w+');
    if ($stream === FALSE) {
      throw new \RuntimeException('Unable to open a temporary terminology stream.');
    }
    fwrite($stream, $value);
    rewind($stream);

    $header = fgetcsv($stream, escape: '');
    if (!is_array($header)) {
      fclose($stream);
      throw new \InvalidArgumentException('The terminology CSV header is missing.');
    }
    $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) ($header[0] ?? ''));
    if ($header !== self::HEADER) {
      fclose($stream);
      throw new \InvalidArgumentException('The first row must be: source_term,target_term');
    }

    $terms = [];
    $normalizations = [];
    $line = 1;
    while (($row = fgetcsv($stream, escape: '')) !== FALSE) {
      $line++;
      if ($row === [NULL] || $row === []) {
        continue;
      }
      if (count($row) !== 2) {
        fclose($stream);
        throw new \InvalidArgumentException(sprintf('Line %d must contain exactly two CSV columns.', $line));
      }

      $source = trim((string) $row[0]);
      $target = trim((string) $row[1]);
      if ($source === '' && $target === '') {
        continue;
      }
      if ($source === '' || $target === '') {
        fclose($stream);
        throw new \InvalidArgumentException(sprintf('Line %d contains an empty source or target term.', $line));
      }

      if (preg_match('/\p{Han}/u', $source) === 1) {
        $map = &$normalizations;
      }
      else {
        $map = &$terms;
      }
      if (isset($map[$source]) && $map[$source] !== $target) {
        fclose($stream);
        throw new \InvalidArgumentException(sprintf('Line %d conflicts with an earlier mapping for "%s".', $line, $source));
      }
      $map[$source] = $target;
      unset($map);
    }
    fclose($stream);

    $this->sortLongestFirst($terms);
    $this->sortLongestFirst($normalizations);

    return ['terms' => $terms, 'normalizations' => $normalizations];
  }

  /**
   * Sorts mappings by source length so nested terms match predictably.
   *
   * @param array<string, string> $map
   *   The map to sort.
   */
  private function sortLongestFirst(array &$map): void {
    uksort($map, static function (string $left, string $right): int {
      $length = mb_strlen($right) <=> mb_strlen($left);
      return $length !== 0 ? $length : strcmp($left, $right);
    });
  }

}
