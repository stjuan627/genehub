<?php

declare(strict_types=1);

namespace Drupal\genehub_solidex\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides the SOLIDEX product entity type settings page.
 */
final class SolidexProductSettingsController extends ControllerBase {

  /**
   * Builds the settings overview page.
   */
  public function overview(): array {
    return [
      '#type' => 'container',
      'description' => [
        '#markup' => $this->t('Use the local tasks on this page to manage SOLIDEX product fields, form display, and display settings.'),
      ],
    ];
  }

}
