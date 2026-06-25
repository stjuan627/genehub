<?php

declare(strict_types=1);

namespace Drupal\genehub_solidex\Entity;

use Drupal\content_translation\ContentTranslationHandler;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genehub_solidex\Form\SolidexProductForm;
use Drupal\genehub_solidex\SolidexProductListBuilder;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the SOLIDEX product entity class.
 */
#[ContentEntityType(
  id: 'product_solidex',
  label: new TranslatableMarkup('SOLIDEX product'),
  label_collection: new TranslatableMarkup('SOLIDEX products'),
  label_singular: new TranslatableMarkup('SOLIDEX product'),
  label_plural: new TranslatableMarkup('SOLIDEX products'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'product_name',
    'langcode' => 'langcode',
    'owner' => 'uid',
    'published' => 'status',
  ],
  handlers: [
    'access' => EntityAccessControlHandler::class,
    'list_builder' => SolidexProductListBuilder::class,
    'view_builder' => EntityViewBuilder::class,
    'form' => [
      'add' => SolidexProductForm::class,
      'edit' => SolidexProductForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'translation' => ContentTranslationHandler::class,
  ],
  links: [
    'collection' => '/admin/content/products/solidex',
    'add-form' => '/admin/content/products/add/solidex',
    'canonical' => '/admin/content/products/solidex/{product_solidex}',
    'edit-form' => '/admin/content/products/solidex/{product_solidex}/edit',
    'delete-form' => '/admin/content/products/solidex/{product_solidex}/delete',
    'delete-multiple-form' => '/admin/content/products/solidex/delete-multiple',
    'drupal:content-translation-overview' => '/admin/content/products/solidex/{product_solidex}/translations',
    'drupal:content-translation-add' => '/admin/content/products/solidex/{product_solidex}/translations/add/{source}/{target}',
    'drupal:content-translation-edit' => '/admin/content/products/solidex/{product_solidex}/translations/edit/{language}',
    'drupal:content-translation-delete' => '/admin/content/products/solidex/{product_solidex}/translations/delete/{language}',
  ],
  admin_permission: 'administer solidex products',
  base_table: 'product_solidex',
  data_table: 'product_solidex_field_data',
  field_ui_base_route: 'entity.product_solidex.settings',
  translatable: TRUE,
)]
final class SolidexProduct extends ContentEntityBase implements EntityOwnerInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Owner'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner')
      ->setTranslatable(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 90,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDefaultValue(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 95,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setTranslatable(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'datetime_timestamp',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setTranslatable(FALSE);

    $fields['product_name'] = static::stringField(t('Product name'), TRUE, TRUE, -50)
      ->setDescription(t('The product name used as the entity label.'));
    $fields['cat_no'] = static::stringField(t('Catalog number'), TRUE, FALSE, -45);
    $fields['products_link'] = static::plainLongTextField(t('Products link'), FALSE, FALSE, -40);
    $fields['biomarker_cat_id'] = static::stringField(t('Biomarker category ID'), FALSE, FALSE, -35);
    $fields['cell_type'] = static::stringField(t('Cell type'), FALSE, FALSE, -30);
    $fields['isolation_method'] = static::stringField(t('Isolation method'), FALSE, FALSE, -25);
    $fields['description'] = static::formattedLongTextField(t('Description'), FALSE, TRUE, -20);
    $fields['brief_description'] = static::formattedLongTextField(t('Brief description'), FALSE, TRUE, -15);
    $fields['application'] = static::formattedLongTextField(t('Application'), FALSE, TRUE, -10);
    $fields['application_detail'] = static::formattedLongTextField(t('Application detail'), FALSE, TRUE, -5);
    $fields['labeling_type'] = static::stringField(t('Labeling type'), FALSE, FALSE, 0);
    $fields['bead_type'] = static::stringField(t('Bead type'), FALSE, FALSE, 5);
    $fields['format'] = static::stringField(t('Format'), FALSE, FALSE, 10);
    $fields['key_feature'] = static::formattedLongTextField(t('Key feature'), FALSE, TRUE, 15);
    $fields['size'] = static::stringField(t('Size'), FALSE, FALSE, 20);
    $fields['units'] = static::stringField(t('Units'), FALSE, FALSE, 25);
    $fields['sales_units'] = BaseFieldDefinition::create('genehub_sales_unit')
      ->setLabel(t('Sales options'))
      ->setDescription(t('Available sales units for this product.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setRequired(FALSE)
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'genehub_sales_unit_default',
        'weight' => 27,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'genehub_sales_unit_default',
        'weight' => 27,
      ])
      ->setDisplayConfigurable('form', TRUE);
    $fields['cell_population'] = static::stringField(t('Cell population'), FALSE, FALSE, 30);

    $fields['components'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Components'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setRequired(FALSE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 35,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 35,
        'settings' => [
          'rows' => 3,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['validation_data'] = static::formattedLongTextField(t('Validation data'), FALSE, TRUE, 40);
    $fields['protocol'] = static::formattedLongTextField(t('Protocol'), FALSE, TRUE, 45);
    $fields['background'] = static::formattedLongTextField(t('Background'), FALSE, TRUE, 50);
    $fields['faq'] = static::formattedLongTextField(t('FAQ'), FALSE, TRUE, 55);

    return $fields;
  }

  /**
   * Creates a short plain text base field.
   */
  private static function stringField(TranslatableMarkup $label, bool $required, bool $translatable, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('string')
      ->setLabel($label)
      ->setRequired($required)
      ->setTranslatable($translatable)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('form', TRUE);
  }

  /**
   * Creates a long plain text base field.
   */
  private static function plainLongTextField(TranslatableMarkup $label, bool $required, bool $translatable, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('string_long')
      ->setLabel($label)
      ->setRequired($required)
      ->setTranslatable($translatable)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => $weight,
        'settings' => [
          'rows' => 3,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);
  }

  /**
   * Creates a formatted long text base field.
   */
  private static function formattedLongTextField(TranslatableMarkup $label, bool $required, bool $translatable, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('text_long')
      ->setLabel($label)
      ->setRequired($required)
      ->setTranslatable($translatable)
      ->setSetting('allowed_formats', ['full_html'])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => $weight,
        'settings' => [
          'rows' => 5,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE);
  }

}
