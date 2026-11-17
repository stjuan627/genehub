<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\Form;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds selection and workflow actions to the existing product list columns.
 */
final class ProductBulkForm extends FormBase {

  /**
   * Constructs the product selection form.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ActionManager $actions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('plugin.manager.action'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'genemedi_i18n_product_bulk';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $entity_type = ''): array {
    if (!in_array($entity_type, ['product_generic', 'product_solidex'], TRUE)
      || !$this->currentUser()->hasPermission('administer genemedi i18n workflow')) {
      throw new AccessDeniedHttpException();
    }
    $form_state->set('entity_type', $entity_type);
    $builder = $this->entities->getListBuilder($entity_type);
    $options = [];
    $action = $this->actions->createInstance('genemedi_i18n_queue:' . $entity_type);
    foreach ($builder->load() as $id => $entity) {
      $options[$id] = $builder->buildRow($entity);
    }
    $form['action'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => ['queue' => $this->t('Queue localization tasks')],
    ];
    $form['submit'] = ['#type' => 'submit', '#value' => $this->t('Apply to selected items')];
    $form['entities'] = [
      '#type' => 'tableselect',
      '#header' => $builder->buildHeader(),
      '#options' => $options,
      '#empty' => $this->t('No products found.'),
    ];
    foreach ($this->entities->getStorage($entity_type)->loadMultiple(array_keys($options)) as $id => $entity) {
      if (!$action->access($entity)) {
        $form['entities'][$id]['#disabled'] = TRUE;
      }
    }
    $form['pager'] = ['#type' => 'pager'];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter($form_state->getValue('entities', []));
    if (!$selected || array_diff(array_keys($selected), array_keys($form['entities']['#options']))) {
      $form_state->setErrorByName('entities', $this->t('Select at least one product from this page.'));
      return;
    }
    $type = $form_state->get('entity_type');
    $entities = $this->entities->getStorage($type)->loadMultiple(array_keys($selected));
    $action = $this->actions->createInstance('genemedi_i18n_queue:' . $type);
    foreach ($selected as $id => $value) {
      if (!isset($entities[$id]) || !$action->access($entities[$id])) {
        $form_state->setErrorByName('entities', $this->t('A selected product is unavailable or cannot be updated.'));
        return;
      }
    }
    $form_state->set('selected_entities', $entities);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->actions->createInstance('genemedi_i18n_queue:' . $form_state->get('entity_type'))
      ->executeMultiple($form_state->get('selected_entities'));
    $form_state->setIgnoreDestination(TRUE);
    $form_state->setRedirect('genemedi_i18n_workflow.bulk_enqueue');
  }

}
