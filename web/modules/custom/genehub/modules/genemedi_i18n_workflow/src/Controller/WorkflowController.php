<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\ContentEntityInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the paginated localization queue to site administrators.
 */
final class WorkflowController extends ControllerBase {

  /**
   * Constructs the workflow overview controller.
   */
  public function __construct(protected readonly Connection $database) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * Builds the task table without exposing worker lease credentials.
   */
  public function overview(): array {
    $rows = [];
    // The overview needs summaries, not large source snapshots and results.
    $tasks = $this->database->select('genemedi_i18n_task', 't')
      ->fields('t', [
        'id', 'entity_type', 'entity_id', 'source_lang', 'target_lang',
        'market', 'status', 'attempt_count', 'error',
      ])
      ->orderBy('id', 'DESC')
      ->extend(PagerSelectExtender::class)->limit(50)->execute()->fetchAll(FetchAs::Associative);
    $ids = $entities = [];
    foreach ($tasks as $task) {
      $ids[$task['entity_type']][$task['entity_id']] = $task['entity_id'];
    }
    foreach ($ids as $type => $entity_ids) {
      if ($this->entityTypeManager()->hasDefinition($type)) {
        $entities[$type] = $this->entityTypeManager()->getStorage($type)->loadMultiple($entity_ids);
      }
    }
    foreach ($tasks as $task) {
      $cells = [
        $task['id'],
        $task['entity_type'] . ':' . $task['entity_id'],
        $task['source_lang'],
        $task['target_lang'],
        $task['market'],
        $task['status'],
        $task['attempt_count'],
        $task['error'] ?? '',
      ];
      $row = array_map(static fn($value) => ['data' => ['#plain_text' => (string) $value]], $cells);
      $entity = $entities[$task['entity_type']][$task['entity_id']] ?? NULL;
      if ($entity instanceof ContentEntityInterface) {
        if ($entity->hasTranslation($task['source_lang'])) {
          $entity = $entity->getTranslation($task['source_lang']);
        }
        if ($entity->access('update') && $entity->hasLinkTemplate('edit-form')) {
          $row[1]['data'] = $entity->toLink(NULL, 'edit-form')->toRenderable();
        }
        elseif ($entity->access('view') && $entity->hasLinkTemplate('canonical')) {
          $row[1]['data'] = $entity->toLink()->toRenderable();
        }
        elseif ($entity->access('view') || $entity->access('update')) {
          $row[1]['data'] = ['#plain_text' => $entity->label() ?? ''];
        }
      }
      $rows[] = $row;
    }
    return [
      'enqueue' => [
        '#type' => 'container',
        'link' => Link::fromTextAndUrl($this->t('Queue localization task'), Url::fromRoute('genemedi_i18n_workflow.enqueue'))->toRenderable(),
      ],
      'tasks' => [
        '#type' => 'table',
        '#header' => [
          $this->t('ID'),
          $this->t('Content'),
          $this->t('Source language'),
          $this->t('Target language'),
          $this->t('Market'),
          $this->t('Status'),
          $this->t('Attempts'),
          $this->t('Last error'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No localization jobs have been queued.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
