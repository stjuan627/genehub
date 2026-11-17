<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\genemedi_i18n_workflow\TaskManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Exposes authenticated, transition-specific worker endpoints.
 */
final class TaskApiController extends ControllerBase {

  /**
   * Constructs the task API controller.
   */
  public function __construct(protected readonly TaskManager $taskManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('genemedi_i18n_workflow.task_manager'));
  }

  /**
   * Lists recent tasks without granting arbitrary state updates.
   */
  public function listing(): JsonResponse {
    return $this->respond(fn() => ['tasks' => $this->taskManager->listTasks()]);
  }

  /**
   * Fetches the source snapshot and current state of a task.
   */
  public function get(int $task): JsonResponse {
    return $this->respond(fn() => $this->taskManager->getTask($task));
  }

  /**
   * Enqueues a source page for one or more language markets.
   */
  public function enqueue(Request $request): JsonResponse {
    return $this->respond(function () use ($request): array {
      $data = $this->body($request);
      if (!isset($data['targets']) || !is_array($data['targets']) || !array_is_list($data['targets'])) {
        throw new \InvalidArgumentException('targets must be a list of language/market objects.');
      }
      return $this->taskManager->enqueue(
        $this->text($data, 'entity_type'),
        $this->text($data, 'entity_id'),
        $this->text($data, 'source_lang'),
        $data['targets'],
      );
    }, 201);
  }

  /**
   * Claims one task atomically or returns a null task when none is ready.
   */
  public function claim(Request $request): JsonResponse {
    return $this->respond(function () use ($request): array {
      $data = $this->body($request);
      return ['task' => $this->taskManager->claim($this->text($data, 'worker_id'), $this->lease($data))];
    });
  }

  /**
   * Renews the lease held by the supplied credential.
   */
  public function heartbeat(int $task, Request $request): JsonResponse {
    return $this->respond(function () use ($task, $request): array {
      $data = $this->body($request);
      return $this->taskManager->heartbeat($task, $this->text($data, 'token'), $this->lease($data));
    });
  }

  /**
   * Persists the localized fields and required agent checks.
   */
  public function complete(int $task, Request $request): JsonResponse {
    return $this->respond(function () use ($task, $request): array {
      $data = $this->body($request);
      if (!isset($data['result']) || !is_array($data['result'])) {
        throw new \InvalidArgumentException('result must be an object.');
      }
      return $this->taskManager->complete($task, $this->text($data, 'token'), $data['result']);
    });
  }

  /**
   * Reports a generation or import failure without discarding saved results.
   */
  public function fail(int $task, Request $request): JsonResponse {
    return $this->respond(function () use ($task, $request): array {
      $data = $this->body($request);
      if (!isset($data['retryable']) || !is_bool($data['retryable'])) {
        throw new \InvalidArgumentException('retryable must be a boolean.');
      }
      return $this->taskManager->fail($task, $this->text($data, 'token'), $this->text($data, 'message'), $data['retryable']);
    });
  }

  /**
   * Continues the same task, reusing its saved result or the caller's draft.
   */
  public function resume(int $task, Request $request): JsonResponse {
    return $this->respond(function () use ($task, $request): array {
      $data = $this->body($request);
      $token = array_key_exists('token', $data) ? $this->text($data, 'token') : NULL;
      return $this->taskManager->resume($task, $this->text($data, 'worker_id'), $this->lease($data), $token);
    });
  }

  /**
   * Cancels an unfinished task without deleting its history or saved results.
   */
  public function cancel(int $task): JsonResponse {
    return $this->respond(fn() => $this->taskManager->cancel($task));
  }

  /**
   * Claims an import and obtains its guarded JSON:API PATCH instructions.
   */
  public function beginImport(int $task, Request $request): JsonResponse {
    return $this->respond(function () use ($task, $request): array {
      $data = $this->body($request);
      return $this->taskManager->beginImport($task, $this->text($data, 'worker_id'), $this->lease($data));
    });
  }

  /**
   * Claims the next eligible import without relying on the recent-task list.
   */
  public function claimImport(Request $request): JsonResponse {
    return $this->respond(function () use ($request): array {
      $data = $this->body($request);
      return ['task' => $this->taskManager->claimImport($this->text($data, 'worker_id'), $this->lease($data))];
    });
  }

  /**
   * Confirms an import by checking the actual saved translation.
   */
  public function acknowledge(int $task, Request $request): JsonResponse {
    return $this->respond(function () use ($task, $request): array {
      $data = $this->body($request);
      return $this->taskManager->acknowledgeImport($task, $this->text($data, 'token'));
    });
  }

  /**
   * Decodes a JSON object without accepting arrays or scalar request bodies.
   */
  private function body(Request $request): array {
    try {
      $decoded = json_decode($request->getContent(), FALSE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw new \InvalidArgumentException('The request body must contain valid JSON.');
    }
    if (!$decoded instanceof \stdClass) {
      throw new \InvalidArgumentException('The request body must be a JSON object.');
    }
    return json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Reads a nonempty string from an input object.
   */
  private function text(array $data, string $key): string {
    if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
      throw new \InvalidArgumentException($key . ' must be a nonempty string.');
    }
    return $data[$key];
  }

  /**
   * Validates an optional lease duration.
   */
  private function lease(array $data): int {
    $seconds = $data['lease_seconds'] ?? 300;
    if (!is_int($seconds) || $seconds < 30 || $seconds > 3600) {
      throw new \InvalidArgumentException('lease_seconds must be an integer between 30 and 3600.');
    }
    return $seconds;
  }

  /**
   * Maps expected failures to HTTP status codes without leaking internals.
   */
  private function respond(callable $operation, int $status = 200): JsonResponse {
    try {
      $response = new JsonResponse($operation(), $status);
    }
    catch (\InvalidArgumentException $exception) {
      $response = new JsonResponse(['error' => $exception->getMessage()], 400);
    }
    catch (HttpExceptionInterface $exception) {
      $response = new JsonResponse(['error' => $exception->getMessage()], $exception->getStatusCode());
    }
    catch (\RuntimeException $exception) {
      $response = new JsonResponse(['error' => $exception->getMessage()], 409);
    }
    catch (\Throwable $exception) {
      $this->getLogger('genemedi_i18n_workflow')->error('Unexpected workflow API error of type @type.', ['@type' => $exception::class]);
      $response = new JsonResponse(['error' => 'The workflow request could not be completed.'], 500);
    }
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
