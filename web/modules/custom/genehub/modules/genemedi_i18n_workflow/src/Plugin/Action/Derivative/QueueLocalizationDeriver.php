<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Plugin\Action\Derivative;

use Drupal\Core\Action\Plugin\Action\Derivative\EntityActionDeriverBase;
use Drupal\Core\Entity\EntityTypeInterface;

/**
 * Exposes localization actions for supported CMS entity types.
 */
final class QueueLocalizationDeriver extends EntityActionDeriverBase {

  /**
   * {@inheritdoc}
   */
  protected function isApplicable(EntityTypeInterface $entity_type) {
    return in_array($entity_type->id(), ['node', 'product_generic', 'product_solidex'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    parent::getDerivativeDefinitions($base_plugin_definition);
    foreach ($this->derivatives as &$definition) {
      $definition['confirm_form_route_name'] = 'genemedi_i18n_workflow.bulk_enqueue';
    }
    return $this->derivatives;
  }

}
