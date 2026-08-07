<?php

declare(strict_types=1);

namespace Drupal\genehub\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rewrites same-site relative URLs in <a>/<img>/<source>/<iframe> to absolute.
 *
 * Intended to be added near the bottom of the filter chain for text formats
 * that are served through JSON:API (or any other API where the front-end has
 * no Drupal request context to resolve `/sites/default/files/...` against).
 *
 * Without this filter, CKEditor emits `<a href="/node/1">` or
 * `<img src="/sites/default/files/foo.png">`; on a decoupled site those
 * relative paths break unless the front-end prepends an origin it does not
 * know about.
 *
 * The base origin comes from `genehub.settings:public_base_url`, falling
 * back to the current request's scheme + host when unset. Configuring an
 * explicit base URL is strongly recommended: under JSON:API the request is
 * served to the front-end and `getSchemeAndHttpHost()` may return the
 * front-end hostname, not the one Drupal intends to publish.
 *
 * Filters that emit image tags later in the chain (for example the
 * "Convert Media tags to markup" filter from core media module) must run
 * BEFORE this filter — otherwise their generated `<img>` tags will not be
 * rewritten.
 *
 * @see \Drupal\filter\Plugin\Filter\FilterImageLazyLoad
 */
#[Filter(
  id: "genehub_absolute_url",
  title: new TranslatableMarkup("Convert same-site relative URLs to absolute"),
  description: new TranslatableMarkup("Rewrites relative paths in src/href/srcset/poster/data-src to absolute URLs using the configured public base URL. Useful for decoupled consumers (JSON:API, RSS) that cannot resolve /sites/default/.../ from the request."),
  type: FilterInterface::TYPE_TRANSFORM_REVERSIBLE,
  weight: 100,
  status: TRUE,
)]
final class AbsoluteUrlFilter extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * Attributes whose value is a single URL (potentially relative).
   */
  private const URL_ATTRIBUTES = [
    'src' => TRUE,
    'href' => TRUE,
    'poster' => TRUE,
    'data-src' => TRUE,
    'cite' => TRUE,
  ];

  /**
   * Attributes whose value is a comma-separated list of URLs (srcset).
   */
  private const SRCSET_ATTRIBUTES = [
    'srcset' => TRUE,
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode): FilterProcessResult {
    $result = new FilterProcessResult($text);

    // Cheap bail-out: nothing to rewrite if no relevant attribute is present.
    if (
      stripos($text, '<img ') === FALSE
      && stripos($text, '<a ') === FALSE
      && stripos($text, '<source ') === FALSE
      && stripos($text, '<iframe ') === FALSE
      && stripos($text, '<video ') === FALSE
      && stripos($text, '<audio ') === FALSE
      && stripos($text, '<link ') === FALSE
      && stripos($text, 'srcset') === FALSE
    ) {
      return $result;
    }

    $base = $this->resolveBaseUrl();
    if ($base === NULL) {
      return $result;
    }

    $rewritten = $this->rewriteHtml($text, $base);
    if ($rewritten === $text) {
      return $result;
    }

    return $result->setProcessedText($rewritten);
  }

  /**
   * Resolves the base URL used to rewrite relative paths.
   *
   * Priority:
   *   1. `genehub.settings:public_base_url` (configured by site admin).
   *   2. Scheme + host of the current request.
   *
   * @return string|null
   *   Base URL with no trailing slash, or NULL when no base is available.
   */
  private function resolveBaseUrl(): ?string {
    $configured = (string) $this->configFactory
      ->get('genehub.settings')
      ->get('public_base_url');

    if ($configured !== '') {
      return rtrim($configured, '/');
    }

    // Fall back to the current request host. Under JSON:API requests this
    // is the front-end host, so callers are warned to configure an explicit
    // base URL in settings.
    $request = \Drupal::request();
    $host = $request->getSchemeAndHttpHost();
    return $host !== '' ? rtrim($host, '/') : NULL;
  }

  /**
   * Rewrites URL attributes in the given HTML fragment.
   *
   * @param string $text
   *   HTML fragment.
   * @param string $base
   *   Absolute base URL with no trailing slash.
   *
   * @return string
   *   Rewritten HTML.
   */
  private function rewriteHtml($text, $base): string {
    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);

    foreach ($xpath->query('//*[@src or @href or @poster or @data-src or @cite or @srcset]') as $node) {
      assert($node instanceof \DOMElement);

      foreach (self::URL_ATTRIBUTES as $attr => $_unused) {
        if (!$node->hasAttribute($attr)) {
          continue;
        }
        $rewritten = $this->rewriteSingleUrl(
          (string) $node->getAttribute($attr),
          $base,
        );
        if ($rewritten !== NULL) {
          $node->setAttribute($attr, $rewritten);
        }
      }

      foreach (self::SRCSET_ATTRIBUTES as $attr => $_unused) {
        if (!$node->hasAttribute($attr)) {
          continue;
        }
        $rewritten = $this->rewriteSrcset(
          (string) $node->getAttribute($attr),
          $base,
        );
        if ($rewritten !== NULL) {
          $node->setAttribute($attr, $rewritten);
        }
      }
    }

    return Html::serialize($dom);
  }

  /**
   * Rewrites a single URL value.
   *
   * Returns NULL when the URL should be left untouched (already absolute,
   * protocol-relative, a fragment, mailto/tel/data/javascript:, or empty).
   *
   * @return string|null
   *   The rewritten URL or NULL when nothing changed.
   */
  private function rewriteSingleUrl($value, $base): ?string {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return NULL;
    }

    $lower = strtolower($trimmed);

    // Pass through anything that already has a scheme or is scheme-relative.
    if (str_starts_with($lower, '//')) {
      return NULL;
    }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $trimmed) === 1) {
      return NULL;
    }

    // Same-page fragments must stay as fragments.
    if (str_starts_with($trimmed, '#')) {
      return NULL;
    }

    // Single leading slash → site-relative path → rewrite.
    if (str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//')) {
      return $base . $trimmed;
    }

    // Anything else (relative file names, paths without leading slash, etc.)
    // is ambiguous without a base path; leave it untouched rather than
    // guess and silently corrupt content.
    return NULL;
  }

  /**
   * Rewrites every candidate URL inside a srcset value.
   *
   * Srcset syntax: `url1 1x, url2 2x, ...`. Each URL is rewritten
   * independently; only candidates that actually need rewriting are touched.
   *
   * @return string|null
   *   The rewritten srcset value, or NULL when nothing changed.
   */
  private function rewriteSrcset($value, $base): ?string {
    $candidates = preg_split('/(\s*,\s*)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE)
      ?? [$value];
    $changed = FALSE;

    foreach ($candidates as $index => $part) {
      // Delimiters come back as their own array entries; skip those.
      if (trim($part) === '' || str_starts_with($part, ',')) {
        continue;
      }
      // Split "url descriptor" — descriptor is whitespace + optional size.
      $tokens = preg_split('/\s+/', trim($part), 2) ?: [];
      $url = $tokens[0] ?? '';
      if ($url === '') {
        continue;
      }
      $rewritten = $this->rewriteSingleUrl($url, $base);
      if ($rewritten === NULL || $rewritten === $url) {
        continue;
      }
      $descriptor = $tokens[1] ?? '';
      $candidates[$index] = $descriptor === ''
        ? $rewritten
        : $rewritten . ' ' . $descriptor;
      $changed = TRUE;
    }

    return $changed ? implode('', $candidates) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE): string {
    return (string) $this->t('Relative URLs inside links, images and other media are rewritten to absolute URLs against the configured public base URL.');
  }

}
