<?php

declare(strict_types=1);

namespace Drupal\content_dashboard\Service;

use Drupal\content_dashboard\Model\ChangeAnalysisResult;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Repository service for content change events and audit logs.
 */
class ChangeLogRepository {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The change detector service.
   *
   * @var \Drupal\content_dashboard\Service\MajorChangeDetector
   */
  protected $changeDetector;

  /**
   * Constructs a ChangeLogRepository.
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    MajorChangeDetector $changeDetector,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->changeDetector = $changeDetector;
  }

  /**
   * Records a change analysis event into the log table.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The current node.
   * @param int|null $prevVid
   *   Previous revision ID.
   * @param \Drupal\content_dashboard\Model\ChangeAnalysisResult $result
   *   The analysis result.
   *
   * @return int
   *   The inserted log ID.
   */
  public function recordChange(NodeInterface $node, ?int $prevVid, ChangeAnalysisResult $result): int {
    $vid = (int) $node->getRevisionId();
    $slug = $this->extractSlug($node);
    $url = $this->extractUrl($node);

    // Prevent duplicate insertion for the same revision.
    if ($this->isRevisionLogged($vid)) {
      $this->database->update('content_change_log')
        ->fields([
          'is_major' => $result->isMajor() ? 1 : 0,
          'change_flags' => $result->getChangeFlagsString(),
          'title' => (string) $node->getTitle(),
          'slug' => $slug,
          'url' => $url,
          'summary' => $result->getSummary(),
          'metrics' => $result->getMetricsJson(),
          'created' => (int) $node->getChangedTime(),
        ])
        ->condition('vid', $vid)
        ->execute();

      return 0;
    }

    $id = (int) $this->database->insert('content_change_log')
      ->fields([
        'nid' => (int) $node->id(),
        'vid' => $vid,
        'prev_vid' => $prevVid ?? 0,
        'langcode' => $node->language()->getId(),
        'bundle' => $node->bundle(),
        'is_major' => $result->isMajor() ? 1 : 0,
        'change_flags' => $result->getChangeFlagsString(),
        'title' => (string) $node->getTitle(),
        'slug' => $slug,
        'url' => $url,
        'author_uid' => (int) $node->getOwnerId(),
        'summary' => $result->getSummary(),
        'metrics' => $result->getMetricsJson(),
        'created' => (int) $node->getChangedTime(),
      ])
      ->execute();

    return $id;
  }

  /**
   * Checks whether a revision ID has already been logged.
   */
  public function isRevisionLogged(int $vid): bool {
    $count = (int) $this->database->select('content_change_log', 'c')
      ->fields('c', ['id'])
      ->condition('vid', $vid)
      ->countQuery()
      ->execute()
      ->fetchField();

    return $count > 0;
  }

  /**
   * Retrieves recent updates with filtering and pagination.
   *
   * @param array<string, mixed> $options
   *   Filter options: limit, page, bundle, langcode, only_major, change_type.
   *
   * @return array{items: array<int, array<string, mixed>>, total: int}
   *   List of records and total count.
   */
  public function getRecentUpdates(array $options = []): array {
    $limit = isset($options['limit']) ? min(max((int) $options['limit'], 1), 100) : 20;
    $page = isset($options['page']) ? max((int) $options['page'], 0) : 0;
    $offset = $page * $limit;

    $query = $this->database->select('content_change_log', 'l')
      ->fields('l');

    if (!empty($options['only_major'])) {
      $query->condition('l.is_major', 1);
    }

    if (!empty($options['bundle'])) {
      $bundles = is_array($options['bundle']) ? $options['bundle'] : explode(',', (string) $options['bundle']);
      $bundles = array_filter(array_map('trim', $bundles));
      if (!empty($bundles)) {
        $query->condition('l.bundle', $bundles, 'IN');
      }
    }

    if (!empty($options['langcode'])) {
      $query->condition('l.langcode', (string) $options['langcode']);
    }

    if (!empty($options['change_type'])) {
      $type = (string) $options['change_type'];
      $query->condition('l.change_flags', '%' . $this->database->escapeLike($type) . '%', 'LIKE');
    }

    if (!empty($options['since'])) {
      $query->condition('l.created', (int) $options['since'], '>');
    }

    // Clone query for total count.
    $countQuery = clone $query;
    $total = (int) $countQuery->countQuery()->execute()->fetchField();

    $query->orderBy('l.created', 'DESC');
    $query->orderBy('l.id', 'DESC');
    $query->range($offset, $limit);

    $results = $query->execute()->fetchAll();

    $items = [];
    foreach ($results as $row) {
      $items[] = $this->formatLogRow((array) $row);
    }

    return [
      'items' => $items,
      'total' => $total,
    ];
  }

  /**
   * Retrieves change history for a specific node.
   *
   * @param int $nid
   *   The node ID.
   * @param bool $onlyMajor
   *   Whether to return major changes only.
   * @param int $limit
   *   Maximum number of history records.
   *
   * @return array<int, array<string, mixed>>
   *   History records.
   */
  public function getNodeHistory(int $nid, bool $onlyMajor = TRUE, int $limit = 50): array {
    $query = $this->database->select('content_change_log', 'l')
      ->fields('l')
      ->condition('l.nid', $nid);

    if ($onlyMajor) {
      $query->condition('l.is_major', 1);
    }

    $query->orderBy('l.created', 'DESC');
    $query->orderBy('l.id', 'DESC');
    $query->range(0, min($limit, 200));

    $results = $query->execute()->fetchAll();

    $history = [];
    foreach ($results as $row) {
      $history[] = $this->formatLogRow((array) $row);
    }

    return $history;
  }

  /**
   * Loads specific revision diff details.
   *
   * @param int $nid
   *   Node ID.
   * @param int $vid
   *   Revision ID.
   *
   * @return array<string, mixed>|null
   *   Detailed diff structure, or NULL if not found.
   */
  public function getDiffDetail(int $nid, int $vid): ?array {
    /** @var \Drupal\node\NodeStorageInterface $nodeStorage */
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    /** @var \Drupal\node\NodeInterface|null $currRevision */
    $currRevision = $nodeStorage->loadRevision($vid);
    if (!$currRevision || (int) $currRevision->id() !== $nid) {
      return NULL;
    }

    // Look up previous revision logged.
    $logEntry = $this->database->select('content_change_log', 'l')
      ->fields('l')
      ->condition('vid', $vid)
      ->execute()
      ->fetchAssoc();

    $prevVid = $logEntry ? (int) $logEntry['prev_vid'] : 0;
    $prevRevision = $prevVid > 0 ? $nodeStorage->loadRevision($prevVid) : NULL;

    $analysis = $this->changeDetector->analyze($currRevision, $prevRevision);

    $oldTitle = $prevRevision ? $prevRevision->getTitle() : '';
    $newTitle = $currRevision->getTitle();

    $oldH1 = $prevRevision ? $this->changeDetector->extractH1($prevRevision) : '';
    $newH1 = $this->changeDetector->extractH1($currRevision);

    $oldBody = $prevRevision ? $this->changeDetector->getNodeBodyText($prevRevision) : '';
    $newBody = $this->changeDetector->getNodeBodyText($currRevision);

    return [
      'nid' => $nid,
      'vid' => $vid,
      'prev_vid' => $prevVid,
      'is_major' => $analysis->isMajor(),
      'change_flags' => $analysis->getChangeFlags(),
      'summary' => $analysis->getSummary(),
      'metrics' => $analysis->getMetrics(),
      'title_diff' => [
        'old' => $oldTitle,
        'new' => $newTitle,
        'changed' => $oldTitle !== $newTitle,
      ],
      'h1_diff' => [
        'old' => $oldH1,
        'new' => $newH1,
        'changed' => $oldH1 !== $newH1,
      ],
      'body_diff' => [
        'old_length' => mb_strlen($oldBody),
        'new_length' => mb_strlen($newBody),
        'old_excerpt' => mb_substr($oldBody, 0, 300) . (mb_strlen($oldBody) > 300 ? '...' : ''),
        'new_excerpt' => mb_substr($newBody, 0, 300) . (mb_strlen($newBody) > 300 ? '...' : ''),
      ],
    ];
  }

  /**
   * Extracts clean slug from node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   Extracted slug string.
   */
  public function extractSlug(NodeInterface $node): string {
    if ($node->hasField('field_slug') && !$node->get('field_slug')->isEmpty()) {
      $val = trim((string) $node->get('field_slug')->value);
      $slug = trim($val, '/');
      if ($slug !== '') {
        return $slug;
      }
    }

    // Fallback: extract from URL path alias.
    $urlPath = trim($node->toUrl()->toString(), '/');
    $parts = explode('/', $urlPath);
    $last = end($parts);
    return !empty($last) ? $last : 'node-' . $node->id();
  }

  /**
   * Extracts canonical absolute URL from node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   Absolute URL.
   */
  public function extractUrl(NodeInterface $node): string {
    return $node->toUrl('canonical', ['absolute' => TRUE])->toString();
  }

  /**
   * Formats a raw database row into an API-ready array.
   *
   * @param array<string, mixed> $row
   *   Database row.
   *
   * @return array<string, mixed>
   *   Formatted array.
   */
  protected function formatLogRow(array $row): array {
    $metrics = [];
    if (!empty($row['metrics'])) {
      $decoded = json_decode((string) $row['metrics'], TRUE);
      if (is_array($decoded)) {
        $metrics = $decoded;
      }
    }

    $flags = array_filter(explode(',', (string) ($row['change_flags'] ?? '')));
    $url = (string) ($row['url'] ?? '');
    $slug = (string) ($row['slug'] ?? '');
    $path = parse_url($url, PHP_URL_PATH) ?: '';

    return [
      'log_id' => (int) $row['id'],
      'nid' => (int) $row['nid'],
      'vid' => (int) $row['vid'],
      'prev_vid' => (int) $row['prev_vid'],
      'bundle' => (string) $row['bundle'],
      'langcode' => (string) $row['langcode'],
      'title' => (string) $row['title'],
      'slug' => $slug,
      'url' => $url,
      'path' => $path,
      'is_major' => (bool) $row['is_major'],
      'change_flags' => array_values($flags),
      'summary' => (string) ($row['summary'] ?? ''),
      'metrics' => $metrics,
      'updated_at' => date('c', (int) $row['created']),
      'timestamp' => (int) $row['created'],
      'author_uid' => (int) $row['author_uid'],
    ];
  }

}
