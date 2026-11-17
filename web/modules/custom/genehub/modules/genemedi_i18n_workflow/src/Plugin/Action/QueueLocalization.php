<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\EntityActionBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genemedi_i18n_workflow\Plugin\Action\Derivative\QueueLocalizationDeriver;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Carries a user-private selection to the language configuration form.
 */
#[Action(
  id: 'genemedi_i18n_queue',
  action_label: new TranslatableMarkup('Queue localization for'),
  deriver: QueueLocalizationDeriver::class,
)]
final class QueueLocalization extends EntityActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL) {
    $this->executeMultiple([$object]);
  }

  /**
   * {@inheritdoc}
   */
  public function executeMultiple(array $entities) {
    $selection = [];
    foreach ($entities as $entity) {
      if (!$this->access($entity)) {
        throw new AccessDeniedHttpException('You cannot queue localization for this entity.');
      }
      $selection[$entity->getEntityTypeId()][$entity->id()] = (string) $entity->id();
    }
    $store = \Drupal::service('tempstore.private')->get('genemedi_i18n_bulk');
    $key = \Drupal::service('uuid')->generate();
    $store->set('selection:' . $key, $selection);
    $store->set('latest_selection', $key);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= \Drupal::currentUser();
    $supported = $object instanceof ContentEntityInterface
      && in_array($object->getEntityTypeId(), ['node', 'product_generic', 'product_solidex'], TRUE)
      && ($object->getEntityTypeId() !== 'node' || $object->bundle() === 'page');
    $access = AccessResult::allowedIf($supported)
      ->andIf(AccessResult::allowedIfHasPermission($account, 'administer genemedi i18n workflow'));
    if ($supported) {
      $access = $access->andIf($object->access('update', $account, TRUE));
    }
    return $return_as_object ? $access : $access->isAllowed();
  }

}
