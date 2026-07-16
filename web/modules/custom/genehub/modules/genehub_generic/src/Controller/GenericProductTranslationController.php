<?php

declare(strict_types=1);

namespace Drupal\genehub_generic\Controller;

use Drupal\content_translation\Controller\ContentTranslationController;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Handles generic product translation forms.
 */
final class GenericProductTranslationController extends ContentTranslationController {

  /**
   * Builds the add translation page.
   */
  public function add(LanguageInterface $source, LanguageInterface $target, RouteMatchInterface $route_match, $entity_type_id = NULL) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface|null $entity */
    $entity = $entity_type_id ? $route_match->getParameter($entity_type_id) : NULL;

    if ($entity && $entity->hasTranslation($target->getId())) {
      $this->messenger()->addStatus($this->t('The %language translation already exists. You can edit it below.', [
        '%language' => $target->getName(),
      ]));

      if ($entity->access('update') && $entity->getEntityType()->hasLinkTemplate('edit-form')) {
        return $this->redirect("entity.$entity_type_id.edit_form", [
          $entity_type_id => $entity->id(),
        ], [
          'language' => $target,
        ]);
      }

      return $this->redirect("entity.$entity_type_id.content_translation_edit", [
        $entity_type_id => $entity->id(),
        'language' => $target->getId(),
      ], [
        'language' => $target,
      ]);
    }

    return parent::add($source, $target, $route_match, $entity_type_id);
  }

}
