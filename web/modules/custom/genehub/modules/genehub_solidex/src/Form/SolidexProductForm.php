<?php

declare(strict_types=1);

namespace Drupal\genehub_solidex\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for SOLIDEX product add and edit forms.
 */
final class SolidexProductForm extends ContentEntityForm {

  /**
   * Constructs a SolidexProductForm object.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    protected DateFormatterInterface $dateFormatter,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('date.formatter'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $product = $this->entity;

    $form['advanced']['#attributes']['class'][] = 'entity-meta';

    $form['meta'] = [
      '#type' => 'details',
      '#group' => 'advanced',
      '#weight' => -10,
      '#title' => $this->t('Status'),
      '#attributes' => ['class' => ['entity-meta__header']],
      '#tree' => TRUE,
    ];
    $form['meta']['published'] = [
      '#type' => 'item',
      '#markup' => !$product->isNew() && !$product->get('status')->isEmpty() && (bool) $product->get('status')->value
        ? $this->t('Published')
        : $this->t('Not published'),
      '#access' => !$product->isNew(),
      '#wrapper_attributes' => ['class' => ['entity-meta__title']],
    ];
    $form['meta']['changed'] = [
      '#type' => 'item',
      '#title' => $this->t('Last saved'),
      '#markup' => !$product->isNew() && !$product->get('changed')->isEmpty()
        ? $this->dateFormatter->format((int) $product->get('changed')->value, 'short')
        : $this->t('Not saved yet'),
      '#wrapper_attributes' => ['class' => ['entity-meta__last-saved']],
    ];
    $form['meta']['owner'] = [
      '#type' => 'item',
      '#title' => $this->t('Owner'),
      '#markup' => $product->getOwner()?->getDisplayName() ?? $this->t('Unknown'),
      '#wrapper_attributes' => ['class' => ['entity-meta__author']],
    ];

    $form['catalog'] = [
      '#type' => 'details',
      '#title' => $this->t('Catalog information'),
      '#group' => 'advanced',
      '#weight' => 10,
      '#optional' => TRUE,
    ];
    foreach (['cat_no', 'products_link', 'biomarker_cat_id'] as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#group'] = 'catalog';
      }
    }

    $form['sales_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Sales options'),
      '#weight' => 20,
      '#open' => TRUE,
    ];
    if (isset($form['sales_units'])) {
      $form['sales_units']['#group'] = 'sales_options';
    }

    $form['author'] = [
      '#type' => 'details',
      '#title' => $this->t('Authoring information'),
      '#group' => 'advanced',
      '#weight' => 90,
      '#optional' => TRUE,
    ];
    foreach (['uid', 'created'] as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#group'] = 'author';
      }
    }

    $form['language_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Language'),
      '#group' => 'advanced',
      '#weight' => 95,
      '#optional' => TRUE,
    ];
    if (isset($form['langcode'])) {
      $form['langcode']['#group'] = 'language_settings';
    }

    return $form;
  }

}
