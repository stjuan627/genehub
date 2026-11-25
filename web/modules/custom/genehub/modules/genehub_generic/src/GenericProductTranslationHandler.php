<?php

declare(strict_types=1);

namespace Drupal\genehub_generic;

use Drupal\content_translation\ContentTranslationHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;

/**
 * Uses the product's native published widget on multilingual edit forms.
 */
final class GenericProductTranslationHandler extends ContentTranslationHandler {

  /**
   * {@inheritdoc}
   */
  public function entityFormAlter(array &$form, FormStateInterface $form_state, EntityInterface $entity) {
    parent::entityFormAlter($form, $form_state, $entity);
    if (isset($form['status'], $form['content_translation']['status']) && ($form['status']['#access'] ?? TRUE) !== FALSE) {
      unset($form['content_translation']['status']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function entityFormEntityBuild($entity_type, EntityInterface $entity, array $form, FormStateInterface $form_state) {
    // The native widget has already populated the entity before this builder.
    $status = $entity->get('status')->getValue();
    parent::entityFormEntityBuild($entity_type, $entity, $form, $form_state);
    if (!isset($form['content_translation']['status'])) {
      $entity->set('status', $status);
    }
  }

}
