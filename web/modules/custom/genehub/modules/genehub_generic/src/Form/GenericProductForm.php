<?php

declare(strict_types=1);

namespace Drupal\genehub_generic\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form handler for generic product add and edit forms.
 */
final class GenericProductForm extends ContentEntityForm {

  /**
   * Constructs a GenericProductForm object.
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
    $form['#attached']['library'][] = 'genehub/admin_form';
    $form['#attributes']['class'][] = 'genehub-sticky-vertical-tabs';

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
    if (isset($form['status'])) {
      $form['status']['#group'] = 'meta';
    }

    $form['content_tabs'] = [
      '#type' => 'vertical_tabs',
      '#weight' => -20,
    ];

    $form['overview'] = [
      '#type' => 'details',
      '#title' => $this->t('Overview'),
      '#group' => 'content_tabs',
      '#weight' => 0,
      '#optional' => TRUE,
    ];
    foreach (['product_name', 'cat_no', 'product_kind', 'products_link', 'brief_description'] as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#group'] = 'overview';
      }
    }

    $form['media'] = [
      '#type' => 'details',
      '#title' => $this->t('Media and documents'),
      '#group' => 'content_tabs',
      '#weight' => 10,
      '#optional' => TRUE,
    ];
    foreach (['image', 'datasheet', 'protocol', 'coa'] as $field_name) {
      if (isset($form[$field_name])) {
        $form[$field_name]['#group'] = 'media';
      }
    }

    $form['sales_options'] = [
      '#type' => 'details',
      '#title' => $this->t('Sales options'),
      '#group' => 'content_tabs',
      '#weight' => 20,
      '#optional' => TRUE,
    ];
    if (isset($form['sales_units'])) {
      $form['sales_units']['#group'] = 'sales_options';
    }

    $form['content_sections'] = [
      '#type' => 'details',
      '#title' => $this->t('Sections'),
      '#group' => 'content_tabs',
      '#weight' => 30,
      '#optional' => TRUE,
    ];
    if (isset($form['sections'])) {
      $form['sections']['#group'] = 'content_sections';
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
