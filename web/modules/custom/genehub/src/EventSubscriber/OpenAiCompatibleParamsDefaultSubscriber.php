<?php

declare(strict_types=1);

namespace Drupal\genehub\EventSubscriber;

use Drupal\ai\Event\PreGenerateResponseEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects provider model params as default request configuration values.
 */
final class OpenAiCompatibleParamsDefaultSubscriber implements EventSubscriberInterface {

  /**
   * Creates a new subscriber instance.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PreGenerateResponseEvent::EVENT_NAME => 'onPreGenerateResponse',
    ];
  }

  /**
   * Adds OpenAI-compatible model params as request defaults.
   */
  public function onPreGenerateResponse(PreGenerateResponseEvent $event): void {
    if ($event->getProviderId() !== 'openai_compatible') {
      return;
    }

    $model_id = $event->getModelId();
    if ($model_id === '') {
      return;
    }

    $defaults = $this->loadModelParameters($model_id);
    if ($defaults === []) {
      return;
    }

    $event->setConfiguration($this->mergeDefaults($event->getConfiguration(), $defaults));
  }

  /**
   * Loads configured model parameters from provider settings.
   */
  private function loadModelParameters(string $modelId): array {
    $models = $this->configFactory
      ->get('ai_provider_openai_compatible.settings')
      ->get('models');

    if (!is_array($models)) {
      return [];
    }

    foreach ($models as $model) {
      if (!is_array($model) || ($model['id'] ?? NULL) !== $modelId) {
        continue;
      }

      return isset($model['parameters']) && is_array($model['parameters'])
        ? $model['parameters']
        : [];
    }

    return [];
  }

  /**
   * Recursively applies defaults without overwriting existing values.
   */
  private function mergeDefaults(array $configuration, array $defaults): array {
    foreach ($defaults as $key => $value) {
      if (!array_key_exists($key, $configuration)) {
        $configuration[$key] = $value;
        continue;
      }

      if (is_array($configuration[$key]) && is_array($value)) {
        $configuration[$key] = $this->mergeDefaults($configuration[$key], $value);
      }
    }

    return $configuration;
  }

}
