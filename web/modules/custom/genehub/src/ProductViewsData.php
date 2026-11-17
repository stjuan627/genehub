<?php

declare(strict_types=1);

namespace Drupal\genehub;

use Drupal\views\EntityViewsData;

/**
 * Exposes product fields and core bulk actions to Views.
 */
final class ProductViewsData extends EntityViewsData {

  /**
   * {@inheritdoc}
   */
  public function getViewsData() {
    $data = parent::getViewsData();
    $data[$this->entityType->getBaseTable()]['bulk_form'] = [
      'title' => $this->t('Bulk operations'),
      'field' => ['id' => 'bulk_form'],
    ];
    return $data;
  }

}
