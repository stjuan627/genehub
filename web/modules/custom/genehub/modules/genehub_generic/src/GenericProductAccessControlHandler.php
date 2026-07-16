<?php

declare(strict_types=1);

namespace Drupal\genehub_generic;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\genehub_generic\Entity\GenericProduct;

/**
 * Access control handler for generic products.
 */
final class GenericProductAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if (!$entity instanceof GenericProduct) {
      return AccessResult::neutral()->addCacheableDependency($entity);
    }

    $admin_permission = (string) $this->entityType->getAdminPermission();

    return match ($operation) {
      'view', 'view label' => $entity->isPublished()
        ? AccessResult::allowedIfHasPermission($account, 'access content')
          ->orIf(AccessResult::allowedIfHasPermission($account, $admin_permission))
          ->addCacheableDependency($entity)
        : AccessResult::allowedIfHasPermission($account, $admin_permission)
          ->addCacheableDependency($entity),
      'update', 'delete' => AccessResult::allowedIfHasPermission($account, $admin_permission)
        ->addCacheableDependency($entity),
      default => parent::checkAccess($entity, $operation, $account),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermission($account, (string) $this->entityType->getAdminPermission());
  }

}
