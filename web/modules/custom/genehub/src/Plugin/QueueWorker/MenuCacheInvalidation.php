<?php

declare(strict_types=1);

namespace Drupal\genehub\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genehub\MenuCacheInvalidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retries webhook delivery in Drupal cron.
 */
#[QueueWorker(
  id: MenuCacheInvalidator::QUEUE,
  title: new TranslatableMarkup('GeneMedi menu cache invalidation'),
  cron: ['time' => 15],
)]
final class MenuCacheInvalidation extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, private readonly MenuCacheInvalidator $invalidator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('genehub.menu_cache_invalidator'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    try {
      $this->invalidator->deliver($data);
    }
    catch (\Exception $error) {
      throw new SuspendQueueException($error->getMessage(), 0, $error);
    }
  }

}
