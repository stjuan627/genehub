<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\EventSubscriber;

use Drupal\genemedi_i18n_workflow\TaskManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Releases entity locks after successful or failed HTTP PATCH requests.
 */
final class RequestCleanupSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the request cleanup subscriber.
   */
  public function __construct(private readonly TaskManager $tasks) {}

  /**
   * Releases only locks held by this request.
   */
  public function release(): void {
    $this->tasks->releaseLocks();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => ['release', -1000], KernelEvents::EXCEPTION => ['release', -1000]];
  }

}
