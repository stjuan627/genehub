<?php

declare(strict_types=1);

namespace Drupal\genehub\Workspace;

use Drupal\workspaces\Negotiator\WorkspaceIdNegotiatorInterface;
use Drupal\workspaces\Negotiator\WorkspaceNegotiatorInterface;
use Drupal\workspaces\WorkspaceInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Activates a workspace from the JSON:API-compatible workspaceId parameter.
 */
final class WorkspaceIdNegotiator implements WorkspaceNegotiatorInterface, WorkspaceIdNegotiatorInterface {

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request): bool {
    return is_string($request->query->get('workspaceId'))
      && trim((string) $request->query->get('workspaceId')) !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function getActiveWorkspaceId(Request $request): ?string {
    $workspace_id = $request->query->get('workspaceId');
    return is_string($workspace_id) && trim($workspace_id) !== ''
      ? trim($workspace_id)
      : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setActiveWorkspace(WorkspaceInterface $workspace): void {
    // Query parameters should affect only the current request, not the session.
  }

  /**
   * {@inheritdoc}
   */
  public function unsetActiveWorkspace(): void {
    // Query parameters should affect only the current request, not the session.
  }

}
