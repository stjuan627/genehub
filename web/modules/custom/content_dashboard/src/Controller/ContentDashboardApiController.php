<?php

declare(strict_types=1);

namespace Drupal\content_dashboard\Controller;

use Drupal\content_dashboard\Service\ChangeLogRepository;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * RESTful API controller for Content Dashboard.
 */
class ContentDashboardApiController extends ControllerBase {

  /**
   * The change log repository.
   *
   * @var \Drupal\content_dashboard\Service\ChangeLogRepository
   */
  protected $repository;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs ContentDashboardApiController.
   */
  public function __construct(
    ChangeLogRepository $repository,
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->repository = $repository;
    $this->configFactory = $configFactory;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('content_dashboard.change_log_repository'),
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Validates API authentication token.
   */
  protected function authenticate(Request $request): ?JsonResponse {
    $config = $this->configFactory->get('content_dashboard.settings');
    if (!$config->get('enabled')) {
      return new JsonResponse([
        'status' => 'error',
        'code' => 403,
        'message' => 'Content Dashboard API is currently disabled.',
      ], 403);
    }

    $configuredKey = (string) $config->get('api_key');
    if (empty($configuredKey)) {
      return new JsonResponse([
        'status' => 'error',
        'code' => 500,
        'message' => 'API Key is not configured on the server.',
      ], 500);
    }

    $providedKey = $request->headers->get('X-API-KEY') ?: $request->query->get('api_key');
    if (!$providedKey || !hash_equals($configuredKey, (string) $providedKey)) {
      return new JsonResponse([
        'status' => 'error',
        'code' => 401,
        'message' => 'Invalid or missing API key in X-API-KEY header.',
      ], 401);
    }

    return NULL;
  }

  /**
   * Endpoint: List recent content changes.
   *
   * GET /api/v1/content-dashboard/updates.
   */
  public function getUpdates(Request $request): JsonResponse {
    if ($authError = $this->authenticate($request)) {
      return $authError;
    }

    $options = [
      'limit' => (int) $request->query->get('limit', 20),
      'page' => (int) $request->query->get('page', 0),
      'bundle' => $request->query->get('bundle'),
      'langcode' => $request->query->get('langcode'),
      'only_major' => filter_var($request->query->get('only_major', TRUE), FILTER_VALIDATE_BOOLEAN),
      'change_type' => $request->query->get('change_type'),
      'since' => $request->query->get('since'),
    ];

    $result = $this->repository->getRecentUpdates($options);

    $limit = min(max($options['limit'], 1), 100);
    $page = max($options['page'], 0);
    $hasMore = (($page + 1) * $limit) < $result['total'];

    return new JsonResponse([
      'status' => 'success',
      'data' => $result['items'],
      'pagination' => [
        'total' => $result['total'],
        'page' => $page,
        'limit' => $limit,
        'has_more' => $hasMore,
      ],
    ]);
  }

  /**
   * Endpoint: Node change history.
   *
   * GET /api/v1/content-dashboard/nodes/{nid}/history.
   */
  public function getNodeHistory(int $nid, Request $request): JsonResponse {
    if ($authError = $this->authenticate($request)) {
      return $authError;
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $nodeStorage->load($nid);
    if (!$node) {
      return new JsonResponse([
        'status' => 'error',
        'code' => 404,
        'message' => "Node with ID {$nid} not found.",
      ], 404);
    }

    $onlyMajor = filter_var($request->query->get('only_major', TRUE), FILTER_VALIDATE_BOOLEAN);
    $limit = (int) $request->query->get('limit', 50);

    $history = $this->repository->getNodeHistory($nid, $onlyMajor, $limit);

    return new JsonResponse([
      'status' => 'success',
      'node' => [
        'nid' => (int) $node->id(),
        'bundle' => $node->bundle(),
        'current_title' => $node->getTitle(),
        'slug' => $this->repository->extractSlug($node),
        'url' => $this->repository->extractUrl($node),
        'path' => $node->toUrl()->toString(),
        'is_published' => $node->isPublished(),
        'created_at' => date('c', (int) $node->getCreatedTime()),
        'updated_at' => date('c', (int) $node->getChangedTime()),
      ],
      'history' => $history,
    ]);
  }

  /**
   * Endpoint: Detailed diff for a specific revision.
   *
   * GET /api/v1/content-dashboard/nodes/{nid}/diff/{vid}
   */
  public function getDiff(int $nid, int $vid, Request $request): JsonResponse {
    if ($authError = $this->authenticate($request)) {
      return $authError;
    }

    $diff = $this->repository->getDiffDetail($nid, $vid);
    if (!$diff) {
      return new JsonResponse([
        'status' => 'error',
        'code' => 404,
        'message' => "Revision {$vid} for node {$nid} not found.",
      ], 404);
    }

    return new JsonResponse([
      'status' => 'success',
      'data' => $diff,
    ]);
  }

}
