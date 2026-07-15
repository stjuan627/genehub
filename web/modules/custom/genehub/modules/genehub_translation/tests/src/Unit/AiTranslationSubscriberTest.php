<?php

declare(strict_types=1);

namespace Drupal\Tests\genehub_translation\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\ai\Event\PostGenerateResponseEvent;
use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\genehub_translation\EventSubscriber\AiTranslationSubscriber;
use Drupal\genehub_translation\TerminologyManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests English-to-Chinese AI terminology processing.
 */
#[Group('genehub_translation')]
final class AiTranslationSubscriberTest extends UnitTestCase {

  /**
   * Tests placeholder protection, restoration, and output normalization.
   */
  public function testEnglishToChineseRoundTrip(): void {
    $subscriber = $this->createSubscriber();
    $input = new ChatInput([
      new ChatMessage('system', $this->systemPrompt('en', 'zh-hans')),
      new ChatMessage('user', '<strong title="AAVPure Affinity Resin">AAVPure Affinity Resin</strong> with AAV and catalyst.'),
    ]);
    $pre = $this->createPreEvent($input);

    $subscriber->onPreGenerate($pre);

    $messages = $input->getMessages();
    $this->assertStringContainsString(
      '<strong title="AAVPure Affinity Resin">__GENEHUB_TERM_0001__</strong>',
      $messages[1]->getText(),
    );
    $this->assertStringContainsString('__GENEHUB_TERM_0002__', $messages[1]->getText());
    $this->assertStringNotContainsString('__GENEHUB_TERM_0003__', $messages[1]->getText());
    $this->assertStringContainsString('AAVPure Affinity Resin => AAVPure 亲和树脂', $messages[0]->getText());

    $chatMessage = new ChatMessage(
      'assistant',
      '<strong title="AAVPure 亲和层析填料">__GENEHUB_TERM_0001__</strong>搭配__GENEHUB_TERM_0002__和AAV 亲和层析填料。',
    );
    $post = $this->createPostEvent($input, $chatMessage, $pre->getAllMetadata());
    $subscriber->onPostGenerate($post);

    $this->assertSame(
      '<strong title="AAVPure 亲和层析填料">AAVPure 亲和树脂</strong>搭配AAV和AAV 亲和树脂。',
      $chatMessage->getText(),
    );
  }

  /**
   * Tests that other translation directions are not changed.
   */
  public function testOtherDirectionIsIgnored(): void {
    $subscriber = $this->createSubscriber();
    $input = new ChatInput([
      new ChatMessage('system', $this->systemPrompt('en', 'ja')),
      new ChatMessage('user', 'AAVPure Affinity Resin'),
    ]);
    $event = $this->createPreEvent($input);

    $subscriber->onPreGenerate($event);

    $this->assertSame('AAVPure Affinity Resin', $input->getMessages()[1]->getText());
    $this->assertNull($event->getMetadata('genehub_translation'));
  }

  /**
   * Tests that lost placeholders fail rather than leaking into stored output.
   */
  public function testMissingPlaceholderFails(): void {
    $subscriber = $this->createSubscriber();
    $input = new ChatInput([
      new ChatMessage('system', $this->systemPrompt('en', 'zh-hans')),
      new ChatMessage('user', 'AAV'),
    ]);
    $pre = $this->createPreEvent($input);
    $subscriber->onPreGenerate($pre);
    $post = $this->createPostEvent(
      $input,
      new ChatMessage('assistant', '腺相关病毒'),
      $pre->getAllMetadata(),
    );

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('__GENEHUB_TERM_0001__');
    $subscriber->onPostGenerate($post);
  }

  /**
   * Creates the subscriber with a small in-memory glossary.
   */
  private function createSubscriber(): AiTranslationSubscriber {
    $configFactory = $this->getConfigFactoryStub([
      TerminologyManager::CONFIG_NAME => [
        'terminology' => <<<'CSV'
source_term,target_term
AAV,AAV
AAVPure Affinity Resin,AAVPure 亲和树脂
AAV 亲和层析填料,AAV 亲和树脂
CSV,
      ],
    ]);
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getLanguage')->willReturnMap([
      ['en', new Language(['id' => 'en', 'name' => 'English'])],
      ['zh-hans', new Language(['id' => 'zh-hans', 'name' => 'Chinese, Simplified'])],
    ]);
    return new AiTranslationSubscriber(
      new TerminologyManager($configFactory),
      $languageManager,
    );
  }

  /**
   * Creates a pre-generation event.
   */
  private function createPreEvent(ChatInput $input): PreGenerateResponseEvent {
    return new PreGenerateResponseEvent(
      requestThreadId: 'test-thread',
      providerId: 'echoai',
      operationType: 'chat',
      configuration: [],
      input: $input,
      modelId: 'test-model',
      tags: ['ai_tmgmt'],
    );
  }

  /**
   * Creates a post-generation event.
   *
   * @param \Drupal\ai\OperationType\Chat\ChatInput $input
   *   The original request input.
   * @param \Drupal\ai\OperationType\Chat\ChatMessage $message
   *   The normalized provider response message.
   * @param array<string, mixed> $metadata
   *   Metadata copied from the pre-generation event.
   */
  private function createPostEvent(
    ChatInput $input,
    ChatMessage $message,
    array $metadata,
  ): PostGenerateResponseEvent {
    return new PostGenerateResponseEvent(
      requestThreadId: 'test-thread',
      providerId: 'echoai',
      operationType: 'chat',
      configuration: [],
      input: $input,
      modelId: 'test-model',
      output: new ChatOutput($message, '', []),
      tags: ['ai_tmgmt'],
      metadata: $metadata,
    );
  }

  /**
   * Builds the stable system-prompt language markers.
   */
  private function systemPrompt(string $source, string $target): string {
    return "Translate content.\nSource language code: $source\nTarget language code: $target";
  }

}
