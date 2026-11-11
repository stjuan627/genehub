<?php

declare(strict_types=1);

namespace Drupal\content_dashboard\Service;

use Drupal\content_dashboard\Model\ChangeAnalysisResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Service to analyze content revisions and detect major modifications.
 */
class MajorChangeDetector {

  /**
   * The content dashboard configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a MajorChangeDetector.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $configFactory) {
    $this->config = $configFactory->get('content_dashboard.settings');
  }

  /**
   * Analyzes the differences between two revisions of a node.
   *
   * @param \Drupal\node\NodeInterface $current
   *   The current/newer node revision.
   * @param \Drupal\node\NodeInterface|null $previous
   *   The previous node revision, or NULL if this is initial creation.
   *
   * @return \Drupal\content_dashboard\Model\ChangeAnalysisResult
   *   The quantitative analysis result.
   */
  public function analyze(NodeInterface $current, ?NodeInterface $previous = NULL): ChangeAnalysisResult {
    // 1. Initial creation check.
    if ($previous === NULL) {
      $wordCount = $this->countWords($this->getNodeBodyText($current));
      return new ChangeAnalysisResult(
        isMajor: TRUE,
        changeFlags: ['created'],
        summary: "- **Created**: Initial content creation and publication.",
        metrics: [
          'action' => 'created',
          'words_total' => $wordCount,
          'title' => $current->getTitle(),
        ]
      );
    }

    $changeFlags = [];
    $summaryParts = [];
    $metrics = [];

    // 2. Status change check (Published / Unpublished).
    $currStatus = (int) $current->isPublished();
    $prevStatus = (int) $previous->isPublished();
    if ($currStatus !== $prevStatus) {
      $statusLabel = $currStatus ? 'Published' : 'Unpublished';
      $changeFlags[] = 'status';
      $summaryParts[] = "- **Status**: Changed to {$statusLabel}";
      $metrics['status_changed'] = TRUE;
      $metrics['status_new'] = $currStatus;
      $metrics['status_old'] = $prevStatus;
    }

    // 3. Title change check.
    $currTitle = trim((string) $current->getTitle());
    $prevTitle = trim((string) $previous->getTitle());
    if ($currTitle !== $prevTitle) {
      $cleanCurrTitle = $this->stripPunctuationAndWhitespace($currTitle);
      $cleanPrevTitle = $this->stripPunctuationAndWhitespace($prevTitle);
      if ($cleanCurrTitle !== $cleanPrevTitle) {
        $changeFlags[] = 'title';
        $summaryParts[] = "- **Title**: Updated from '{$prevTitle}' to '{$currTitle}'";
        $metrics['title_changed'] = TRUE;
        $metrics['title_old'] = $prevTitle;
        $metrics['title_new'] = $currTitle;
      }
      else {
        $metrics['title_punctuation_only'] = TRUE;
      }
    }

    // 4. H1 change check.
    $currH1 = $this->extractH1($current);
    $prevH1 = $this->extractH1($previous);
    if ($currH1 !== $prevH1) {
      $cleanCurrH1 = $this->stripPunctuationAndWhitespace($currH1);
      $cleanPrevH1 = $this->stripPunctuationAndWhitespace($prevH1);
      if ($cleanCurrH1 !== $cleanPrevH1) {
        $changeFlags[] = 'h1';
        $summaryParts[] = "- **H1**: Page heading changed from '{$prevH1}' to '{$currH1}'";
        $metrics['h1_changed'] = TRUE;
        $metrics['h1_old'] = $prevH1;
        $metrics['h1_new'] = $currH1;
      }
    }

    // 5. Body/Content change analysis.
    $currBody = $this->getNodeBodyText($current);
    $prevBody = $this->getNodeBodyText($previous);
    $bodyDiff = $this->calculateTextDifference($prevBody, $currBody);

    $metrics['body_metrics'] = $bodyDiff;

    if ($bodyDiff['is_substantive']) {
      $ratioThreshold = (float) $this->config->get('body_diff_ratio_threshold') ?: 0.15;
      $wordsThreshold = (int) $this->config->get('body_diff_words_threshold') ?: 50;

      if ($bodyDiff['diff_ratio'] >= $ratioThreshold || $bodyDiff['delta_words'] >= $wordsThreshold) {
        $changeFlags[] = 'body_major';
        $percentText = round($bodyDiff['diff_ratio'] * 100, 1) . '%';
        $summaryParts[] = "- **Content**: Substantive body modification ({$percentText} changed, +{$bodyDiff['words_added']} / -{$bodyDiff['words_deleted']} words)";
      }
      else {
        // Minor textual edits (e.g. fewer than 5 words or very small ratio).
        $metrics['minor_edits'] = TRUE;
      }
    }

    // 6. Final classification.
    $isMajor = !empty($changeFlags);
    $summary = $isMajor
      ? implode("\n", $summaryParts)
      : "- **Minor**: Minor formatting, punctuation, or trivial wording adjustments.";

    return new ChangeAnalysisResult(
      isMajor: $isMajor,
      changeFlags: $changeFlags,
      summary: $summary,
      metrics: $metrics
    );
  }

  /**
   * Calculates quantitative differences between two text versions.
   *
   * @param string $oldText
   *   The previous plain text.
   * @param string $newText
   *   The new plain text.
   *
   * @return array<string, mixed>
   *   Detailed calculation metrics.
   */
  public function calculateTextDifference(string $oldText, string $newText): array {
    $cleanOld = $this->stripPunctuationAndWhitespace($oldText);
    $cleanNew = $this->stripPunctuationAndWhitespace($newText);

    // If normalized strings without punctuation are identical,
    // this edit is purely punctuation or whitespace change.
    if ($cleanOld === $cleanNew) {
      return [
        'is_substantive' => FALSE,
        'reason' => 'punctuation_or_whitespace_only',
        'delta_words' => 0,
        'diff_ratio' => 0.0,
        'words_added' => 0,
        'words_deleted' => 0,
      ];
    }

    $oldTokens = $this->tokenize($oldText);
    $newTokens = $this->tokenize($newText);

    $oldLen = count($oldTokens);
    $newLen = count($newTokens);
    $maxLen = max($oldLen, $newLen, 1);

    $oldCount = array_count_values($oldTokens);
    $newCount = array_count_values($newTokens);

    $added = 0;
    $deleted = 0;

    $allWords = array_unique(array_merge(array_keys($oldCount), array_keys($newCount)));
    foreach ($allWords as $word) {
      $cOld = $oldCount[$word] ?? 0;
      $cNew = $newCount[$word] ?? 0;
      if ($cNew > $cOld) {
        $added += ($cNew - $cOld);
      }
      elseif ($cOld > $cNew) {
        $deleted += ($cOld - $cNew);
      }
    }

    $deltaWords = $added + $deleted;
    $diffRatio = round($deltaWords / $maxLen, 4);

    return [
      'is_substantive' => TRUE,
      'old_word_count' => $oldLen,
      'new_word_count' => $newLen,
      'words_added' => $added,
      'words_deleted' => $deleted,
      'delta_words' => $deltaWords,
      'diff_ratio' => $diffRatio,
    ];
  }

  /**
   * Extracts H1 content from the node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return string
   *   The extracted H1 text, or empty string.
   */
  public function extractH1(NodeInterface $node): string {
    // 1. Check custom field_h1 if present.
    if ($node->hasField('field_h1') && !$node->get('field_h1')->isEmpty()) {
      return trim((string) $node->get('field_h1')->value);
    }

    // 2. Extract first <h1> tag from the body if present.
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $rawHtml = (string) $node->get('body')->value;
      if (preg_match('/<h1[^>]*>(.*?)<\/h1>/is', $rawHtml, $matches)) {
        return trim(strip_tags($matches[1]));
      }
    }

    // 3. Fallback to node title if no custom H1 is defined.
    return trim((string) $node->getTitle());
  }

  /**
   * Extracts clean plain body text from node.
   */
  public function getNodeBodyText(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    $rawHtml = (string) $node->get('body')->value;
    return $this->extractPlainText($rawHtml);
  }

  /**
   * Strips HTML tags and normalizes spaces.
   */
  public function extractPlainText(string $html): string {
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Replace block tags with newline.
    $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text);
    $text = strip_tags((string) $text);
    return trim((string) preg_replace('/\s+/u', ' ', $text));
  }

  /**
   * Strips all punctuation and whitespaces for pure lexical comparison.
   */
  public function stripPunctuationAndWhitespace(string $text): string {
    // Strip Unicode punctuation (\p{P}), separators (\p{Z}), and whitespace.
    return mb_strtolower((string) preg_replace('/[\p{P}\p{Z}\s]+/u', '', $text), 'UTF-8');
  }

  /**
   * Splits text into token array supporting English and Chinese.
   *
   * @param string $text
   *   Text to tokenize.
   *
   * @return array<int, string>
   *   Tokenized array.
   */
  public function tokenize(string $text): array {
    $clean = mb_strtolower(trim($text), 'UTF-8');
    if ($clean === '') {
      return [];
    }

    // Extract word-like sequences or Asian characters.
    preg_match_all('/[\p{L}\p{N}]+/u', $clean, $matches);
    $words = $matches[0] ?? [];

    $tokens = [];
    foreach ($words as $word) {
      // If the word contains CJK characters, break into individual chars.
      if (preg_match('/[\x{4e00}-\x{9fa5}]/u', $word)) {
        $chars = mb_str_split($word);
        foreach ($chars as $c) {
          if ($c !== '') {
            $tokens[] = $c;
          }
        }
      }
      else {
        $tokens[] = $word;
      }
    }

    return $tokens;
  }

  /**
   * Counts words in text.
   */
  public function countWords(string $text): int {
    return count($this->tokenize($text));
  }

}
