<?php

declare(strict_types=1);

namespace Drupal\genehub\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Provides aggregate GeneHub product administration pages.
 */
final class ProductController extends ControllerBase {

  /**
   * Builds the product administration overview page.
   */
  public function overview(): array {
    return [
      '#markup' => $this->t('Select a product family tab or menu item to manage products provided by enabled GeneHub modules.'),
    ];
  }

  /**
   * Builds the product add overview page.
   */
  public function addOverview(): array {
    return [
      '#markup' => $this->t('Select a product type action to create a product provided by an enabled GeneHub module.'),
    ];
  }

}
