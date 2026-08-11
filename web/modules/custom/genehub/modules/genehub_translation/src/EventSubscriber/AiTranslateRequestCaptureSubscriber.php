<?php

declare(strict_types=1);

namespace Drupal\genehub_translation\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Captures the final AI Translate request before provider serialization.
 */
final class AiTranslateRequestCaptureSubscriber implements EventSubscriberInterface {

  /**
   * State key controlling request capture.
   */
  public const ENABLED_STATE_KEY = 'genehub_translation.ai_translate_capture_enabled';

  /**
   * State key containing the latest captured request.
   */
  public const STATE_KEY = 'genehub_translation.ai_translate_last_request';

  /**
   * Constructs the request capture subscriber.
   */
  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => ['captureRequest', -1000],
    ];
  }

  /**
   * Stores a byte-preserving snapshot of the final chat input.
   */
  public function captureRequest(PreGenerateResponseEvent $event): void {
    if (!$this->state->get(self::ENABLED_STATE_KEY, FALSE)
      || !in_array('ai_translate', $event->getTags(), TRUE)) {
      return;
    }

    $input = $event->getInput();
    if (!$input instanceof ChatInput) {
      return;
    }

    $systemPrompt = $input->getSystemPrompt() ?? '';
    $messages = [];
    foreach ($input->getMessages() as $message) {
      $text = $message->getText();
      $messages[] = [
        'role' => $message->getRole(),
        'text' => $text,
        'bytes' => strlen($text),
        'sha256' => hash('sha256', $text),
        'base64' => base64_encode($text),
      ];
    }

    $this->state->set(self::STATE_KEY, [
      'captured_at' => $this->time->getCurrentTime(),
      'request_thread_id' => $event->getRequestThreadId(),
      'provider_id' => $event->getProviderId(),
      'model_id' => $event->getModelId(),
      'tags' => $event->getTags(),
      'system_prompt' => [
        'text' => $systemPrompt,
        'bytes' => strlen($systemPrompt),
        'sha256' => hash('sha256', $systemPrompt),
        'base64' => base64_encode($systemPrompt),
      ],
      'messages' => $messages,
    ]);
  }

}
