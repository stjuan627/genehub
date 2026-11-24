<?php

/**
 * @file
 * Imports a GeneMedi header menu from JSON Lines via Drush.
 */

declare(strict_types=1);

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

$path = $extra[0] ?? '';
if (!is_readable($path)) {
  throw new InvalidArgumentException('Pass the legacy menu JSON Lines file as an argument.');
}

$langcode = $extra[1] ?? 'en';
$language = \Drupal::languageManager()->getLanguage($langcode);
if (!preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/', $langcode) || $language === NULL) {
  throw new InvalidArgumentException('Pass a configured content language.');
}
$id = 'genemedi-header-' . $langcode;
$children = [];
$seen = [];
foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
  $row = json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR);
  if ($row['id'] <= 0 || isset($seen[$row['id']]) || trim($row['label']) === '') {
    throw new InvalidArgumentException('Invalid or duplicate legacy menu item.');
  }
  $seen[$row['id']] = TRUE;
  $children[$row['parent_id']][] = $row;
}
if (empty($children[0])) {
  throw new InvalidArgumentException('The source menu has no root links.');
}

$lock = \Drupal::lock();
if (!$lock->acquire($id, 120)) {
  throw new RuntimeException('Another menu import is running.');
}

try {
  if (\Drupal::entityQuery('menu_link_content')->accessCheck(FALSE)->condition('menu_name', $id)->count()->execute()) {
    throw new RuntimeException('The destination has links. Existing CMS content was preserved.');
  }

  $transaction = \Drupal::database()->startTransaction();
  try {
    if (!Menu::load($id)) {
      Menu::create([
        'id' => $id,
        'label' => 'GeneMedi header (' . $language->getName() . ')',
        'langcode' => $langcode,
      ])->save();
    }

    $count = 0;
    $import = function (int $legacyParent, string $parent, int $depth) use (&$import, &$count, $children, $id, $langcode): void {
      if ($depth > 5 && !empty($children[$legacyParent])) {
        throw new InvalidArgumentException('The source menu exceeds five levels.');
      }
      $siblings = $children[$legacyParent] ?? [];
      usort($siblings, static function (array $first, array $second): int {
        return [$first['sort_order'], $first['id']] <=> [$second['sort_order'], $second['id']];
      });
      foreach ($siblings as $weight => $row) {
        $url = trim((string) $row['url']);
        $uri = match (TRUE) {
          in_array($url, ['', '#', 'javascript:void(null)'], TRUE) => 'route:<nolink>',
          preg_match('~^/(?!/)~', $url) === 1 => 'internal:' . $url,
          preg_match('~^https?://~i', $url) === 1 => $url,
          default => throw new InvalidArgumentException('Unexpected URL on menu item ' . $row['id']),
        };
        $badge = match (trim((string) $row['extra_html'])) {
          '' => '',
          '<sup class="text-red"><em> Hot!</em></sup>' => 'hot',
          '<sup class="text-red"><em> New!</em></sup>' => 'new',
          default => throw new InvalidArgumentException('Unexpected badge on menu item ' . $row['id']),
        };
        $link = MenuLinkContent::create([
          'menu_name' => $id,
          'langcode' => $langcode,
          'title' => $row['label'],
          'link' => [
            'uri' => $uri,
            'options' => [
              'genemedi' => [
                'icon' => (string) $row['icon_class'],
                'badge' => $badge,
              ],
            ],
          ],
          'enabled' => TRUE,
          'parent' => $parent,
          'weight' => $weight,
        ]);
        $link->save();
        $count++;
        $import((int) $row['id'], $link->getPluginId(), $depth + 1);
      }
    };
    $import(0, '', 1);
    if ($count !== count($seen)) {
      throw new InvalidArgumentException('The source has links that cannot be reached from the root.');
    }
    unset($transaction);
  }
  catch (\Throwable $exception) {
    $transaction->rollBack();
    throw $exception;
  }
  print "Imported $count menu links into $id.\n";
}
finally {
  $lock->release($id);
}
