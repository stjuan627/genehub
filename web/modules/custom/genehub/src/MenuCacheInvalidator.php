<?php

declare(strict_types=1);

namespace Drupal\genehub;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Persists and delivers GeneMedi header cache invalidations.
 */
final class MenuCacheInvalidator implements EventSubscriberInterface {

  public const QUEUE = 'genehub_menu_cache_invalidate';

  /**
   * The GeneHub logger. */
  private readonly LoggerInterface $logger;

  /**
   * The GeneMedi webhook endpoint. */
  private readonly string $url;

  /**
   * The shared HMAC secret. */
  private readonly string $secret;

  /**
   * Whether this request enqueued a menu invalidation. */
  private bool $queued = FALSE;

  public function __construct(
    private readonly QueueFactory $queues,
    private readonly ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggers,
  ) {
    $this->logger = $loggers->get('genehub');
    $this->url = (string) (getenv('GENEMEDI_CACHE_WEBHOOK_URL') ?: Settings::get('genemedi_cache_webhook_url', ''));
    $this->secret = (string) (getenv('CMS_CACHE_WEBHOOK_SECRET') ?: Settings::get('genemedi_cache_webhook_secret', ''));
  }

  /**
   * Queues invalidations in the same database transaction as the menu edit.
   */
  public function enqueue(string $menu): void {
    if (!str_starts_with($this->url, 'https://') || $this->secret === ''
      || !preg_match('/\Agenemedi-header-([a-z]{2,3}(?:-[a-z0-9]{2,8})*)\z/', $menu, $matches)) {
      return;
    }

    $item = $this->queues->get(self::QUEUE, TRUE)->createItem([
      'resource' => 'genemedi-header',
      'lang' => $matches[1],
      'event_id' => bin2hex(random_bytes(16)),
    ]);
    if ($item === FALSE) {
      $this->logger->error('Unable to queue invalidation for menu @menu.', ['@menu' => $menu]);
      return;
    }
    $this->queued = TRUE;
  }

  /**
   * Delivers a queued event. Each attempt signs with the current timestamp.
   */
  public function deliver(array $event): void {
    if (!str_starts_with($this->url, 'https://') || $this->secret === '') {
      throw new \RuntimeException('The GeneMedi cache webhook is not configured.');
    }
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $response = $this->httpClient->request('POST', $this->url, [
      'headers' => [
        'Content-Type' => 'application/json',
        'X-CMS-Timestamp' => $timestamp,
        'X-CMS-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, $this->secret),
      ],
      'body' => $body,
      'allow_redirects' => FALSE,
      'connect_timeout' => 1,
      'timeout' => 3,
      'http_errors' => FALSE,
    ]);
    if ($response->getStatusCode() !== 204) {
      throw new \RuntimeException('The GeneMedi cache webhook returned HTTP ' . $response->getStatusCode() . '.');
    }
  }

  /**
   * Attempts delivery after the editor's request has committed and responded.
   */
  public function onTerminate(): void {
    if (!$this->queued) {
      return;
    }
    $queue = $this->queues->get(self::QUEUE, TRUE);
    for ($attempt = 0; $attempt < 4 && ($item = $queue->claimItem(30)); $attempt++) {
      try {
        $this->deliver($item->data);
        $queue->deleteItem($item);
      }
      catch (\Exception $error) {
        // Cron can retry the event after the claim expires.
        $this->logger->warning('GeneMedi cache invalidation will be retried. @message', [
          '@message' => $error->getMessage(),
        ]);
        break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

}
