<?php

declare(strict_types=1);

namespace Drupal\genehub_generic\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides the generic product entity type settings page.
 */
final class GenericProductSettingsController extends ControllerBase {

  /**
   * Builds the settings overview page.
   */
  public function overview(): array {
    return [
      '#type' => 'container',
      'description' => [
        '#markup' => $this->t('Use the local tasks on this page to manage generic product fields, form display, and display settings.'),
      ],
    ];
  }

}
