<?php

declare(strict_types=1);

namespace Drupal\genehub_solidex\Entity;

use Drupal\content_translation\ContentTranslationHandler;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the SOLIDEX product entity class.
 */
#[ContentEntityType(
  id: 'solidex_product',
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
    'list_builder' => EntityListBuilder::class,
    'form' => [
      'add' => 'Drupal\Core\Entity\ContentEntityForm',
      'edit' => 'Drupal\Core\Entity\ContentEntityForm',
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'translation' => ContentTranslationHandler::class,
  ],
  links: [
    'collection' => '/admin/content/solidex-product',
    'add-form' => '/admin/content/solidex-product/add',
    'canonical' => '/admin/content/solidex-product/{solidex_product}',
    'edit-form' => '/admin/content/solidex-product/{solidex_product}/edit',
    'delete-form' => '/admin/content/solidex-product/{solidex_product}/delete',
    'delete-multiple-form' => '/admin/content/solidex-product/delete-multiple',
    'drupal:content-translation-overview' => '/admin/content/solidex-product/{solidex_product}/translations',
    'drupal:content-translation-add' => '/admin/content/solidex-product/{solidex_product}/translations/add/{source}/{target}',
    'drupal:content-translation-edit' => '/admin/content/solidex-product/{solidex_product}/translations/edit/{language}',
    'drupal:content-translation-delete' => '/admin/content/solidex-product/{solidex_product}/translations/delete/{language}',
  ],
  admin_permission: 'administer solidex products',
  base_table: 'solidex_product',
  data_table: 'solidex_product_field_data',
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
      ->setDescription(t('The user ID of the SOLIDEX product owner.'))
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
      ]);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('Published'))
      ->setDescription(t('Whether the SOLIDEX product is published.'))
      ->setDefaultValue(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 95,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the SOLIDEX product was created.'))
      ->setTranslatable(FALSE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the SOLIDEX product was last edited.'))
      ->setTranslatable(FALSE);

    $fields['product_name'] = static::stringField(t('Product name'), TRUE, TRUE, -50)
      ->setDescription(t('The product name used as the entity label.'));
    $fields['cat_no'] = static::stringField(t('Catalog number'), TRUE, FALSE, -45);
    $fields['products_link'] = static::plainLongTextField(t('Products link'), FALSE, FALSE, -40);
    $fields['biomarker_cat_id'] = static::stringField(t('Biomarker category ID'), FALSE, FALSE, -35);
    $fields['cell_type'] = static::stringField(t('Cell type'), FALSE, FALSE, -30);
    $fields['isolation_method'] = static::stringField(t('Isolation method'), FALSE, FALSE, -25);
    $fields['description'] = static::formattedLongTextField(t('Description'), TRUE, TRUE, -20);
    $fields['brief_description'] = static::formattedLongTextField(t('Brief description'), FALSE, TRUE, -15);
    $fields['application'] = static::formattedLongTextField(t('Application'), TRUE, TRUE, -10);
    $fields['application_detail'] = static::formattedLongTextField(t('Application detail'), FALSE, TRUE, -5);
    $fields['labeling_type'] = static::stringField(t('Labeling type'), FALSE, FALSE, 0);
    $fields['bead_type'] = static::stringField(t('Bead type'), FALSE, FALSE, 5);
    $fields['format'] = static::stringField(t('Format'), FALSE, FALSE, 10);
    $fields['key_feature'] = static::formattedLongTextField(t('Key feature'), FALSE, TRUE, 15);
    $fields['size'] = static::stringField(t('Size'), FALSE, FALSE, 20);
    $fields['units'] = static::stringField(t('Units'), FALSE, FALSE, 25);
    $fields['cell_population'] = static::stringField(t('Cell population'), FALSE, FALSE, 30);

    $fields['components'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Components'))
      ->setDescription(t('Component values migrated from non-empty Component1 through Component4 columns.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setRequired(FALSE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => 35,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 35,
        'settings' => [
          'rows' => 3,
        ],
      ]);

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
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => $weight,
      ]);
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
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => $weight,
        'settings' => [
          'rows' => 3,
        ],
      ]);
  }

  /**
   * Creates a formatted long text base field.
   */
  private static function formattedLongTextField(TranslatableMarkup $label, bool $required, bool $translatable, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('text_long')
      ->setLabel($label)
      ->setRequired($required)
      ->setTranslatable($translatable)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => $weight,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => $weight,
        'settings' => [
          'rows' => 5,
        ],
      ]);
  }

}
