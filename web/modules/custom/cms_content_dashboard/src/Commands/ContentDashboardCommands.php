<?php

declare(strict_types=1);

namespace Drupal\cms_content_dashboard\Commands;

use Drupal\cms_content_dashboard\Service\ChangeLogRepository;
use Drupal\cms_content_dashboard\Service\MajorChangeDetector;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Helper\ProgressBar;

/**
 * Drush commands for the CMS Content Dashboard module.
 */
class ContentDashboardCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs ContentDashboardCommands.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected Connection $database,
    protected MajorChangeDetector $changeDetector,
    protected ChangeLogRepository $repository,
  ) {
    parent::__construct();
  }

  /**
   * Backfills content change log from historical node revisions.
   *
   * @param array<string, mixed> $options
   *   Command options.
   */
  #[CLI\Command(name: 'cms-content-dashboard:backfill', aliases: ['cd-backfill'])]
  #[CLI\Bootstrap(DrupalBootLevels::FULL)]
  #[CLI\Option(name: 'bundle', description: 'Comma-separated node types to backfill (e.g. article,page).')]
  #[CLI\Option(name: 'force', description: 'Truncate existing change logs and reprocess all revisions.')]
  #[CLI\Option(name: 'batch-size', description: 'Number of nodes to process per batch.')]
  #[CLI\Usage(name: 'drush cms-content-dashboard:backfill', description: 'Backfill change logs incrementally for all existing nodes.')]
  #[CLI\Usage(name: 'drush cd-backfill --force --bundle=article', description: 'Force rebuild change logs for article nodes.')]
  public function backfill(
    array $options = [
      'bundle' => NULL,
      'force' => FALSE,
      'batch-size' => 50,
    ],
  ): int {
    $startTime = microtime(TRUE);
    $force = (bool) $options['force'];
    $rawBundle = isset($options['bundle']) ? trim((string) $options['bundle'], ". \t\n\r\0\x0B") : '';
    $bundleFilter = [];
    if (!empty($rawBundle) && $rawBundle !== 'all') {
      $bundleFilter = array_filter(array_map('trim', explode(',', $rawBundle)));
    }

    if ($force) {
      if (!$this->io()->confirm('Are you sure you want to TRUNCATE all existing content change logs and rebuild?', FALSE)) {
        $this->logger()->warning('Operation cancelled by user.');
        return 0;
      }
      $this->database->truncate('content_change_log')->execute();
      $this->logger()->notice('Truncated table content_change_log.');
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Query matching node IDs.
    $nodeQuery = $this->database->select('node', 'n')
      ->fields('n', ['nid', 'type'])
      ->orderBy('n.nid', 'ASC');

    if (!empty($bundleFilter)) {
      $nodeQuery->condition('n.type', $bundleFilter, 'IN');
    }

    $nodes = $nodeQuery->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $totalNodes = count($nodes);

    if ($totalNodes === 0) {
      $this->logger()->warning('No nodes found matching the criteria.');
      return 0;
    }

    $this->io()->section("Starting backfill for {$totalNodes} nodes...");

    $totalRevisions = 0;
    $processedRevisions = 0;
    $majorCount = 0;
    $minorCount = 0;
    $skippedCount = 0;

    $progressBar = new ProgressBar($this->output(), $totalNodes);
    $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% (Elapsed: %elapsed:6s%)');
    $progressBar->start();

    foreach ($nodes as $nodeRow) {
      $nid = (int) ($nodeRow->nid ?? $nodeRow['nid'] ?? 0);

      // Fetch all revision IDs for this node ordered chronologically.
      $vidQuery = $this->database->select('node_field_revision', 'r')
        ->fields('r', ['vid'])
        ->condition('r.nid', $nid)
        ->orderBy('r.vid', 'ASC');

      $vids = array_map('intval', $vidQuery->execute()->fetchCol());
      $countVids = count($vids);
      $totalRevisions += $countVids;

      $prevRevision = NULL;
      $prevVid = NULL;

      foreach ($vids as $vid) {
        // If not force mode, check if revision already logged.
        if (!$force && $this->repository->isRevisionLogged($vid)) {
          $skippedCount++;
          // Load revision as prevRevision for subsequent comparison.
          /** @var \Drupal\node\NodeInterface|null $prevRevision */
          $prevRevision = $nodeStorage->loadRevision($vid);
          $prevVid = $vid;
          continue;
        }

        /** @var \Drupal\node\NodeInterface|null $currRevision */
        $currRevision = $nodeStorage->loadRevision($vid);
        if (!$currRevision) {
          continue;
        }

        // Run analysis.
        $analysis = $this->changeDetector->analyze($currRevision, $prevRevision);

        // Record entry.
        $this->repository->recordChange($currRevision, $prevVid, $analysis);

        if ($analysis->isMajor()) {
          $majorCount++;
        }
        else {
          $minorCount++;
        }

        $processedRevisions++;
        $prevRevision = $currRevision;
        $prevVid = $vid;
      }

      $progressBar->advance();
    }

    $progressBar->finish();
    $this->io()->newLine(2);

    $duration = round(microtime(TRUE) - $startTime, 2);

    $this->io()->success('Historical revisions backfill completed successfully!');
    $this->io()->table(
      ['Metric', 'Value'],
      [
        ['Total Nodes Scanned', (string) $totalNodes],
        ['Total Revisions Found', (string) $totalRevisions],
        ['Revisions Processed', (string) $processedRevisions],
        ['Major Changes Detected', (string) $majorCount],
        ['Minor Changes Filtered', (string) $minorCount],
        ['Already Indexed (Skipped)', (string) $skippedCount],
        ['Total Execution Time', "{$duration} s"],
      ]
    );

    return 0;
  }

}
