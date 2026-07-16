<?php

declare(strict_types=1);

namespace Drupal\genehub_generic;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Builds the generic product admin listing.
 */
final class GenericProductListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Product name');
    $header['cat_no'] = $this->t('Primary catalog number');
    $header['product_kind'] = $this->t('Product kind');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['label']['data'] = $entity->hasLinkTemplate('edit-form') && $entity->access('update')
      ? $entity->toLink(NULL, 'edit-form')->toRenderable()
      : ['#plain_text' => $entity->label() ?? ''];

    $row['cat_no'] = $entity->hasField('cat_no') && !$entity->get('cat_no')->isEmpty()
      ? $entity->get('cat_no')->value
      : '';

    $row['product_kind']['data'] = $entity->hasField('product_kind') && !$entity->get('product_kind')->isEmpty()
      ? $entity->get('product_kind')->view(['label' => 'hidden'])
      : [];

    $row['status'] = $entity->hasField('status') && (bool) $entity->get('status')->value
      ? $this->t('Published')
      : $this->t('Unpublished');

    return $row + parent::buildRow($entity);
  }

}
