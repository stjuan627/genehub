<?php

declare(strict_types=1);

namespace Drupal\genehub_translation\ParamConverter;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;

/**
 * Decorates JSON:API EntityUuidConverter to auto-create translations on PATCH.
 */
class GenehubTranslationEntityUuidConverter implements ParamConverterInterface {

  /**
   * Constructs a new GenehubTranslationEntityUuidConverter.
   *
   * @param \Drupal\Core\ParamConverter\ParamConverterInterface $inner
   *   The decorated inner entity UUID converter service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user account.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    protected readonly ParamConverterInterface $inner,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly LanguageManagerInterface $languageManager,
    protected readonly AccountInterface $currentUser,
    protected readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    /** @var \Symfony\Component\Routing\Route|null $route */
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT] ?? NULL;
    $methods = $route ? $route->getMethods() : [];
    $method = !empty($methods) ? $methods[0] : 'GET';

    // Only intervene on PATCH requests.
    if ($method !== 'PATCH') {
      return $this->inner->convert($value, $definition, $name, $defaults);
    }

    $entity_type_id = $this->getEntityTypeFromDefaults($definition, $name, $defaults);
    if (!$entity_type_id || !$this->entityTypeManager->hasDefinition($entity_type_id)) {
      return $this->inner->convert($value, $definition, $name, $defaults);
    }

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $uuid_key = $this->entityTypeManager->getDefinition($entity_type_id)->getKey('uuid');
    if (!$uuid_key) {
      return $this->inner->convert($value, $definition, $name, $defaults);
    }

    $entities = $storage->loadByProperties([$uuid_key => $value]);
    if (!$entities) {
      return NULL;
    }
    $entity = reset($entities);

    // Check if the entity is a translatable content entity.
    if ($entity instanceof ContentEntityInterface && $entity->isTranslatable()) {
      $current_content_language = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();

      // If the entity does not yet have a translation for current content lang:
      if (!$entity->hasTranslation($current_content_language)) {
        // Ensure the target language is a valid configured language.
        $configured_languages = $this->languageManager->getLanguages();
        if (!isset($configured_languages[$current_content_language])) {
          return $this->inner->convert($value, $definition, $name, $defaults);
        }

        // Check if the user has permission to update the entity.
        if (!$entity->access('update', $this->currentUser)) {
          throw new AccessDeniedHttpException('You do not have access to create translations for this entity.');
        }

        // Allow modules to alter or disallow auto translation creation.
        $allow = TRUE;
        $context = [
          'entity' => $entity,
          'langcode' => $current_content_language,
          'defaults' => $defaults,
        ];
        $this->moduleHandler->alter('genehub_translation_jsonapi_auto_translate', $allow, $context);
        if (!$allow) {
          return $this->inner->convert($value, $definition, $name, $defaults);
        }

        // Initialize translation from default translation values.
        $default_translation = $entity->getUntranslated();
        $source_values = $default_translation->toArray();
        $entity->addTranslation($current_content_language, $source_values);

        return $entity->getTranslation($current_content_language);
      }
    }

    return $this->inner->convert($value, $definition, $name, $defaults);
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return $this->inner->applies($definition, $name, $route);
  }

  /**
   * Extracts the entity type ID from the parameter definition or defaults.
   *
   * @param array $definition
   *   The parameter definition.
   * @param string $name
   *   The parameter name.
   * @param array $defaults
   *   The route defaults.
   *
   * @return string|null
   *   The entity type ID, or NULL if it could not be determined.
   */
  protected function getEntityTypeFromDefaults(array $definition, string $name, array $defaults): ?string {
    $type = $definition['type'] ?? NULL;
    if (is_string($type) && str_starts_with($type, 'entity:')) {
      return substr($type, 7);
    }
    if (isset($defaults['_entity_type'])) {
      return $defaults['_entity_type'];
    }
    return NULL;
  }

}
