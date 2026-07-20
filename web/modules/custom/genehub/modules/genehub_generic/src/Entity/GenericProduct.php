<?php

declare(strict_types=1);

namespace Drupal\genehub_generic\Entity;

use Drupal\content_translation\ContentTranslationHandler;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genehub_generic\Form\GenericProductForm;
use Drupal\genehub_generic\GenericProductAccessControlHandler;
use Drupal\genehub_generic\GenericProductListBuilder;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the generic product entity class.
 */
#[ContentEntityType(
  id: 'product_generic',
  label: new TranslatableMarkup('Generic product'),
  label_collection: new TranslatableMarkup('Generic products'),
  label_singular: new TranslatableMarkup('Generic product'),
  label_plural: new TranslatableMarkup('Generic products'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'product_name',
    'langcode' => 'langcode',
    'owner' => 'uid',
    'published' => 'status',
  ],
  handlers: [
    'access' => GenericProductAccessControlHandler::class,
    'list_builder' => GenericProductListBuilder::class,
    'view_builder' => EntityViewBuilder::class,
    'form' => [
      'add' => GenericProductForm::class,
      'edit' => GenericProductForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'translation' => ContentTranslationHandler::class,
  ],
  links: [
    'collection' => '/admin/content/products/generic',
    'add-form' => '/admin/content/products/add/generic',
    'canonical' => '/genehub/generic/{product_generic}',
    'edit-form' => '/admin/content/products/generic/{product_generic}/edit',
    'delete-form' => '/admin/content/products/generic/{product_generic}/delete',
    'delete-multiple-form' => '/admin/content/products/generic/delete-multiple',
    'drupal:content-translation-overview' => '/admin/content/products/generic/{product_generic}/translations',
    'drupal:content-translation-add' => '/admin/content/products/generic/{product_generic}/translations/add/{source}/{target}',
    'drupal:content-translation-edit' => '/admin/content/products/generic/{product_generic}/translations/edit/{language}',
    'drupal:content-translation-delete' => '/admin/content/products/generic/{product_generic}/translations/delete/{language}',
  ],
  admin_permission: 'administer generic products',
  base_table: 'product_generic',
  data_table: 'product_generic_field_data',
  field_ui_base_route: 'entity.product_generic.settings',
  translatable: TRUE,
)]
final class GenericProduct extends ContentEntityBase implements EntityOwnerInterface, EntityPublishedInterface {

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

    $fields['cat_no'] = static::stringField(t('Primary catalog number'), FALSE, FALSE, -45)
      ->setDescription(t('An optional primary catalog number or catalog family identifier.'));

    $fields['product_kind'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('Product kind'))
      ->setDescription(t('A broad category used to organize generic products.'))
      ->setRequired(FALSE)
      ->setTranslatable(FALSE)
      ->setSetting('allowed_values', [
        'aav_purification' => t('AAV purification'),
        'aav_titration' => t('AAV titration'),
        'car_detection_antibody' => t('CAR detection antibody'),
        'other' => t('Other'),
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => -40,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => -40,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['products_link'] = static::plainLongTextField(t('Legacy product link'), FALSE, FALSE, -35)
      ->setDescription(t('The product URL or path from the legacy site.'));

    $fields['brief_description'] = static::formattedLongTextField(t('Brief description'), FALSE, TRUE, -30)
      ->setDescription(t('A short product summary for listings and introductory display.'));

    $fields['image'] = BaseFieldDefinition::create('image')
      ->setLabel(t('Image'))
      ->setDescription(t('The primary product image.'))
      ->setRequired(FALSE)
      ->setTranslatable(TRUE)
      ->setSetting('uri_scheme', 'public')
      ->setSetting('file_directory', 'genehub/products/generic/images')
      ->setSetting('file_extensions', 'png jpg jpeg webp')
      ->setSetting('alt_field', TRUE)
      ->setSetting('alt_field_required', FALSE)
      ->setSetting('title_field', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'image',
        'weight' => -25,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'image_image',
        'weight' => -25,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['documents'] = static::documentsField(
      'genehub/products/generic/documents',
      -20,
    );

    $fields['sales_units'] = BaseFieldDefinition::create('genehub_sales_unit')
      ->setLabel(t('Sales options'))
      ->setDescription(t('Available sales units for this product.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setRequired(FALSE)
      ->setTranslatable(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'genehub_sales_unit_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'genehub_sales_unit_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['sections'] = BaseFieldDefinition::create('genehub_section')
      ->setLabel(t('Sections'))
      ->setDescription(t('Ordered content sections containing a heading and formatted body.'))
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setRequired(FALSE)
      ->setTranslatable(TRUE)
      ->setSetting('allowed_formats', ['full_html'])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'genehub_section_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'genehub_section_default',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', TRUE);

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

  /**
   * Creates a translatable single-value PDF base field.
   */
  private static function pdfField(TranslatableMarkup $label, TranslatableMarkup $description, string $directory, int $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('file')
      ->setLabel($label)
      ->setDescription($description)
      ->setRequired(FALSE)
      ->setTranslatable(TRUE)
      ->setSetting('uri_scheme', 'public')
      ->setSetting('file_directory', $directory)
      ->setSetting('file_extensions', 'pdf')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'file_default',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'file_generic',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('form', TRUE);
  }

  /**
   * Creates a multi-value PDF base field with a per-item display name.
   *
   * The description_field setting is enabled so editors can attach a
   * display name (e.g. "Manual", "Spec") to each uploaded file.
   *
   * @param string $directory
   *   The file_directory setting (relative to the public scheme).
   * @param int|float $weight
   *   Form/view weight.
   */
  private static function documentsField(string $directory, int|float $weight): BaseFieldDefinition {
    return BaseFieldDefinition::create('file')
      ->setLabel(t('Documents'))
      ->setDescription(t('Additional downloadable documents. Each file may carry a display name such as "Manual", "Spec", or "Brochure".'))
      ->setRequired(FALSE)
      ->setTranslatable(TRUE)
      ->setCardinality(BaseFieldDefinition::CARDINALITY_UNLIMITED)
      ->setSetting('uri_scheme', 'public')
      ->setSetting('file_directory', $directory)
      ->setSetting('file_extensions', 'pdf')
      ->setSetting('description_field', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'file_default',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'file_generic',
        'weight' => $weight,
      ])
      ->setDisplayConfigurable('form', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isPublished(): bool {
    return (bool) $this->getEntityKey('published');
  }

  /**
   * {@inheritdoc}
   */
  public function setPublished(): static {
    $this->set($this->getEntityType()->getKey('published'), TRUE);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setUnpublished(): static {
    $this->set($this->getEntityType()->getKey('published'), FALSE);
    return $this;
  }

}
