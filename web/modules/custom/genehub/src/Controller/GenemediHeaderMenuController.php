<?php

declare(strict_types=1);

namespace Drupal\genehub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\menu_link_content\Plugin\Menu\MenuLinkContent;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Exports the live GeneMedi header menu for a requested language.
 */
final class GenemediHeaderMenuController extends ControllerBase {

  public function __construct(
    private readonly MenuLinkTreeInterface $menuTree,
    private readonly WorkspaceManagerInterface $workspaceManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('menu.link_tree'),
      $container->get('workspaces.manager'),
    );
  }

  /**
   * Returns the published menu tree in Drupal menu order.
   */
  public function view(string $langcode): JsonResponse {
    $id = 'genemedi-header-' . $langcode;
    if ($this->workspaceManager->hasActiveWorkspace()) {
      throw new NotFoundHttpException();
    }
    if (!$this->entityTypeManager()->getStorage('menu')->load($id)) {
      // Let the frontend cache the absence until the menu is created.
      return new JsonResponse(['menu' => $id, 'items' => NULL], 404, ['Cache-Control' => 'private, no-store']);
    }

    $parameters = (new MenuTreeParameters())->onlyEnabledLinks()->setMaxDepth(5);
    $tree = $this->menuTree->transform($this->menuTree->load($id, $parameters), [
      ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
    ]);

    return new JsonResponse([
      'menu' => $id,
      'items' => $this->exportItems($tree),
    ], 200, ['Cache-Control' => 'private, no-store']);
  }

  /**
   * Converts enabled content links into the frontend menu contract.
   */
  private function exportItems(array $tree): array {
    $items = [];
    foreach ($tree as $element) {
      if (!$element->link instanceof MenuLinkContent) {
        continue;
      }
      $link = $element->link->getEntity()->get('link')->first();
      $options = $link->options['genemedi'] ?? [];
      $uri = (string) $link->uri;
      $href = str_starts_with($uri, 'internal:') ? substr($uri, 9) : $uri;
      // Root-relative destinations belong to GeneMedi, not the CMS host.
      if (!preg_match('~^(?:https?://[^/\s]+|/(?!/)|#)~i', $href)
        || str_contains($href, '\\') || preg_match('/[[:cntrl:]]/', $href)) {
        $href = '#';
      }
      $items[] = [
        'label' => $element->link->getTitle(),
        'url' => $href,
        'icon_class' => $options['icon'] ?? '',
        'badge' => $options['badge'] ?? '',
        'children' => $this->exportItems($element->subtree),
      ];
    }
    return $items;
  }

}
