<?php

declare(strict_types=1);

namespace Drupal\genehub_translation\Controller;

use Drupal\ai_translate\Controller\ContentTranslationControllerOverride;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Adds an AI source-language selector to product translation overviews.
 */
final class AiTranslationOverviewController extends ContentTranslationControllerOverride {

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param string|null $entity_type_id
   *   The entity type ID.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request.
   */
  public function overview(
    RouteMatchInterface $route_match,
    $entity_type_id = NULL,
    ?Request $request = NULL,
  ): array {
    $build = parent::overview($route_match, $entity_type_id);
    $entity = $route_match->getParameter($entity_type_id);
    if (!$entity instanceof ContentEntityInterface) {
      return $build;
    }

    $translations = $entity->getTranslationLanguages();
    if (count($translations) < 2) {
      return $build;
    }

    $source_langcode = $this->resolveSourceLangcode(
      $entity,
      $translations,
      $request,
    );
    $this->replaceAiTranslationLinks(
      $build,
      $entity,
      (string) $entity_type_id,
      $source_langcode,
    );
    $this->addSourceLanguageMenu($build, $entity, $translations, $source_langcode);

    (new CacheableMetadata())
      ->setCacheContexts(['url.query_args:ai_source'])
      ->applyTo($build);

    return $build;
  }

  /**
   * Resolves and validates the requested AI source language.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The translated entity.
   * @param \Drupal\Core\Language\LanguageInterface[] $translations
   *   Languages for existing entity translations.
   * @param \Symfony\Component\HttpFoundation\Request|null $request
   *   The current request.
   *
   * @return string
   *   A valid source language code.
   */
  private function resolveSourceLangcode(
    ContentEntityInterface $entity,
    array $translations,
    ?Request $request,
  ): string {
    $requested = $request?->query->get('ai_source');
    if (is_string($requested) && isset($translations[$requested])) {
      return $requested;
    }

    return $entity->getUntranslated()->language()->getId();
  }

  /**
   * Rebuilds the AI column links using the selected source translation.
   */
  private function replaceAiTranslationLinks(
    array &$build,
    ContentEntityInterface $entity,
    string $entity_type_id,
    string $source_langcode,
  ): void {
    $overview = &$this->findOverviewTable($build);
    if ($overview === NULL) {
      return;
    }

    $ai_column = NULL;
    foreach ($overview['#header'] as $index => $header) {
      if ((string) $header === (string) $this->t('AI Translations')) {
        $ai_column = $index;
        break;
      }
    }
    if ($ai_column === NULL) {
      return;
    }

    $languages = $this->languageManager()->getLanguages();
    $key = 0;
    foreach ($languages as $langcode => $language) {
      if (isset($overview['#rows'][$key])) {
        $row = &$overview['#rows'][$key];
        $key++;
      }
      elseif (isset($overview['#options'][$langcode])) {
        $row = &$overview['#options'][$langcode];
      }
      else {
        continue;
      }

      if (!isset($row[$ai_column])) {
        continue;
      }

      if ($source_langcode === $langcode || $entity->hasTranslation($langcode)) {
        $row[$ai_column] = $this->t('n/a');
        continue;
      }

      $existing_link = $row[$ai_column];
      if (!$existing_link instanceof MarkupInterface && $existing_link === '') {
        continue;
      }

      $url = Url::fromRoute(
        'ai_translate.translate_content',
        [
          'entity_type' => $entity_type_id,
          'entity_id' => $entity->id(),
          'lang_from' => $source_langcode,
          'lang_to' => $langcode,
        ],
      );
      if (!$url->access()) {
        $row[$ai_column] = '';
        continue;
      }
      $row[$ai_column] = Link::fromTextAndUrl(
        $this->t('Translate from @language using AI', [
          '@language' => $languages[$source_langcode]->getName(),
        ]),
        $url,
      )->toString();
    }
  }

  /**
   * Adds the source-language menu above the translations table.
   *
   * @param array $build
   *   The translation overview render array.
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The translated entity.
   * @param \Drupal\Core\Language\LanguageInterface[] $translations
   *   Languages for existing entity translations.
   * @param string $source_langcode
   *   The selected AI source language code.
   */
  private function addSourceLanguageMenu(
    array &$build,
    ContentEntityInterface $entity,
    array $translations,
    string $source_langcode,
  ): void {
    $options = [];
    $selected_url = '';
    foreach ($translations as $langcode => $language) {
      $url = $entity->toUrl('drupal:content-translation-overview')
        ->setOption('query', ['ai_source' => $langcode])
        ->toString();
      $options[$url] = $language->getName();
      if ($langcode === $source_langcode) {
        $selected_url = $url;
      }
    }

    $selector = [
      '#type' => 'container',
      '#attributes' => ['class' => ['genehub-ai-translation-source']],
      '#attached' => [
        'library' => ['genehub_translation/ai_source_selector'],
      ],
      'label' => [
        '#markup' => '<label for="genehub-ai-translation-source-select">'
        . $this->t('AI translation source')
        . '</label>',
      ],
      'select' => [
        '#type' => 'select',
        '#title' => $this->t('AI translation source'),
        '#title_display' => 'invisible',
        '#options' => $options,
        '#value' => $selected_url,
        '#attributes' => [
          'id' => 'genehub-ai-translation-source-select',
          'class' => ['genehub-ai-translation-source__select'],
        ],
      ],
    ];

    $build = ['genehub_ai_translation_source' => $selector] + $build;
  }

  /**
   * Finds the table or tableselect built by the translation controller.
   */
  private function &findOverviewTable(array &$build): mixed {
    $not_found = NULL;
    foreach (Element::children($build) as $child) {
      if (($build[$child]['#theme'] ?? NULL) === 'table'
        || ($build[$child]['#type'] ?? NULL) === 'tableselect') {
        return $build[$child];
      }
    }

    return $not_found;
  }

}
