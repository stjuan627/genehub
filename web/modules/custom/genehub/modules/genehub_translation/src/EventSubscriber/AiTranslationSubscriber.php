<?php

declare(strict_types=1);

namespace Drupal\genehub_translation\EventSubscriber;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\genehub_translation\TerminologyManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies terminology to English-to-Chinese AI TMGMT requests.
 */
final class AiTranslationSubscriber implements EventSubscriberInterface {

  /**
   * Metadata key shared between the pre- and post-response events.
   */
  private const METADATA_KEY = 'genehub_translation';

  /**
   * Constructs the AI translation subscriber.
   */
  public function __construct(
    private readonly TerminologyManager $terminologyManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => ['onPreGenerate', -10],
      PostGenerateResponseEvent::EVENT_NAME => ['onPostGenerate', -10],
    ];
  }

  /**
   * Protects matched source terms and adds request-specific instructions.
   */
  public function onPreGenerate(PreGenerateResponseEvent $event): void {
    if (!in_array('ai_tmgmt', $event->getTags(), TRUE)) {
      return;
    }
    $input = $event->getInput();
    if (!$input instanceof ChatInput) {
      return;
    }

    $messages = $input->getMessages();
    $system = $this->findMessage($messages, 'system');
    $user = $this->findMessage($messages, 'user');
    if (!$system || !$user || !$this->isEnglishToChinese($system->getText())) {
      return;
    }

    $terminology = $this->terminologyManager->getTerminology();
    $placeholders = [];
    $matchedTerms = [];
    $translatedInput = $this->transformTextSegments(
      $user->getText(),
      function (string $text) use ($terminology, &$placeholders, &$matchedTerms): string {
        foreach ($terminology['terms'] as $source => $target) {
          $pattern = $this->buildTermPattern($source);
          $text = preg_replace_callback(
            $pattern,
            function () use ($source, $target, &$placeholders, &$matchedTerms): string {
              $placeholder = sprintf('__GENEHUB_TERM_%04d__', count($placeholders) + 1);
              $placeholders[$placeholder] = $target;
              $matchedTerms[$source] = $target;
              return $placeholder;
            },
            $text,
          ) ?? $text;
        }
        return $text;
      },
    );

    if ($placeholders !== []) {
      $user->setText($translatedInput);
      $instructions = [
        '',
        'GeneHub terminology requirements:',
        '- Tokens matching __GENEHUB_TERM_0000__ are immutable placeholders. Copy every placeholder exactly once and do not translate, edit, split, or remove it.',
        '- Preserve HTML tags, attributes, URLs, product codes, whitespace inside tags, and placeholder order.',
        '- Return only the translated content.',
        'Matched terminology for context:',
      ];
      foreach ($matchedTerms as $source => $target) {
        $instructions[] = sprintf('- %s => %s', $source, $target);
      }
      $system->setText(rtrim($system->getText()) . "\n" . implode("\n", $instructions));
      $input->setMessages($messages);
      $event->setInput($input);
    }

    $event->setMetadata(self::METADATA_KEY, [
      'english_to_chinese' => TRUE,
      'placeholders' => $placeholders,
    ]);
  }

  /**
   * Restores protected targets and normalizes known Chinese output aliases.
   */
  public function onPostGenerate(PostGenerateResponseEvent $event): void {
    if (!in_array('ai_tmgmt', $event->getTags(), TRUE)) {
      return;
    }
    $metadata = $event->getMetadata(self::METADATA_KEY);
    if (!is_array($metadata) || empty($metadata['english_to_chinese'])) {
      return;
    }

    $output = $event->getOutput();
    if (!$output instanceof ChatOutput || !$output->getNormalized() instanceof ChatMessage) {
      return;
    }

    $text = $output->getNormalized()->getText();
    $placeholders = $metadata['placeholders'] ?? [];
    foreach ($placeholders as $placeholder => $target) {
      if (substr_count($text, $placeholder) !== 1) {
        throw new \UnexpectedValueException(sprintf('AI translation did not preserve terminology placeholder %s exactly once.', $placeholder));
      }
      $text = str_replace($placeholder, (string) $target, $text);
    }

    $normalizations = $this->terminologyManager
      ->getTerminology()['normalizations'];
    if ($normalizations !== []) {
      $text = $this->transformTextSegments(
        $text,
        static fn(string $segment): string => strtr($segment, $normalizations),
      );
    }
    $output->getNormalized()->setText($text);
    $event->setOutput($output);
  }

  /**
   * Finds the first chat message with the requested role.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatMessage[] $messages
   *   The messages to search.
   * @param string $role
   *   The requested role.
   */
  private function findMessage(array $messages, string $role): ?ChatMessage {
    foreach ($messages as $message) {
      if ($message instanceof ChatMessage && $message->getRole() === $role) {
        return $message;
      }
    }
    return NULL;
  }

  /**
   * Checks the stable language-code markers in the TMGMT system prompt.
   */
  private function isEnglishToChinese(string $systemPrompt): bool {
    if (preg_match('/Source language code:\s*en\b/i', $systemPrompt) === 1
      && preg_match('/Target language code:\s*zh-hans\b/i', $systemPrompt) === 1) {
      return TRUE;
    }

    $sourceName = $this->languageManager->getLanguage('en')?->getName();
    $targetName = $this->languageManager->getLanguage('zh-hans')?->getName();
    if (!$sourceName || !$targetName) {
      return FALSE;
    }
    $pattern = sprintf(
      '/from\s+%s\s+into\s+%s(?:\s+language)?/iu',
      preg_quote($sourceName, '/'),
      preg_quote($targetName, '/'),
    );
    return preg_match($pattern, $systemPrompt) === 1;
  }

  /**
   * Builds a case-sensitive, Unicode-aware term matching pattern.
   */
  private function buildTermPattern(string $term): string {
    $prefix = preg_match('/^[\p{L}\p{N}]/u', $term) === 1
      ? '(?<![\p{L}\p{N}])'
      : '';
    $suffix = preg_match('/[\p{L}\p{N}]$/u', $term) === 1
      ? '(?![\p{L}\p{N}])'
      : '';
    return '/' . $prefix . preg_quote($term, '/') . $suffix . '/u';
  }

  /**
   * Transforms text outside HTML tags without parsing or serializing markup.
   *
   * @param string $value
   *   Plain text or an HTML fragment.
   * @param callable(string): string $callback
   *   Text-segment transformer.
   */
  private function transformTextSegments(string $value, callable $callback): string {
    $segments = preg_split('/(<[^>]+>)/u', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($segments === FALSE) {
      return $callback($value);
    }
    foreach ($segments as $index => &$segment) {
      if ($index % 2 === 0) {
        $segment = $callback($segment);
      }
    }
    unset($segment);
    return implode('', $segments);
  }

}
