<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\genemedi_i18n_workflow\TaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the recent localization queue to site administrators.
 */
final class WorkflowController extends ControllerBase {

  /**
   * Constructs the workflow overview controller.
   */
  public function __construct(protected readonly TaskManager $taskManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('genemedi_i18n_workflow.task_manager'));
  }

  /**
   * Builds the recent-task table without exposing worker lease credentials.
   */
  public function overview(): array {
    $rows = [];
    foreach ($this->taskManager->listTasks() as $task) {
      $cells = [
        $task['id'],
        $task['entity_type'] . ':' . $task['entity_id'],
        $task['source_lang'],
        $task['target_lang'],
        $task['market'],
        $task['status'],
        $task['attempts'] ?? 0,
        $task['last_error'] ?? '',
      ];
      $rows[] = array_map(static fn($value) => ['data' => ['#plain_text' => (string) $value]], $cells);
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
          $this->t('Source'),
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
      '#cache' => ['max-age' => 0],
    ];
  }

}
