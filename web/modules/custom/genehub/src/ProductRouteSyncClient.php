<?php

declare(strict_types=1);

namespace Drupal\genehub;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Synchronizes GeneHub product routes to the legacy site.
 */
final class ProductRouteSyncClient {

  private const SUPPORTED_ENTITY_TYPES = [
    'product_generic',
    'product_solidex',
  ];

  /**
   * The GeneHub logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs a product route synchronization client.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('genehub');
  }

  /**
   * Synchronizes one product route without interrupting entity persistence.
   */
  public function sync(EntityInterface $entity, string $routePath): void {
    try {
      $entityType = $entity->getEntityTypeId();
      $entityUuid = $entity->uuid();
      $routePath = ltrim(trim($routePath), '/');

      if (!in_array($entityType, self::SUPPORTED_ENTITY_TYPES, TRUE)) {
        throw new \InvalidArgumentException(sprintf('Unsupported entity type "%s".', $entityType));
      }
      if ($entityUuid === NULL || $entityUuid === '') {
        throw new \InvalidArgumentException('The product entity does not have a UUID.');
      }
      if (!preg_match('#^i/[A-Za-z0-9][A-Za-z0-9._-]*$#', $routePath)) {
        throw new \InvalidArgumentException(sprintf('Invalid legacy route path "%s".', $routePath));
      }

      $settings = $this->configFactory
        ->get('genehub.settings')
        ->get('product_route_sync');
      $settings = is_array($settings) ? $settings : [];
      $apiUrl = trim((string) ($settings['api_url'] ?? ''));
      $username = (string) ($settings['username'] ?? '');
      $password = (string) ($settings['password'] ?? '');

      if ($apiUrl === '' || $username === '' || $password === '') {
        throw new \RuntimeException('The product route synchronization API is not fully configured.');
      }

      $response = $this->httpClient->request('POST', $apiUrl, [
        'auth' => [$username, $password],
        'headers' => [
          'Accept' => 'application/json',
        ],
        'json' => [
          'entity_type' => $entityType,
          'entity_uuid' => $entityUuid,
          'route_path' => $routePath,
        ],
        'http_errors' => FALSE,
        'timeout' => 10,
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode < 200 || $statusCode >= 300) {
        throw new \RuntimeException(sprintf('The legacy API returned HTTP %d.', $statusCode));
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error(
        'Failed to synchronize the legacy route for @entity_type @entity_id: @message',
        [
          '@entity_type' => $entity->getEntityTypeId(),
          '@entity_id' => $entity->id() ?? 'new',
          '@message' => $exception->getMessage(),
        ],
      );
    }
  }

}
