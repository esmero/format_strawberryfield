<?php

namespace Drupal\format_strawberryfield\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\format_strawberryfield\MetadataConfigInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;

/**
 * Defines the MetadataExposeConfigEntity entity.*.
 *
 * This entity binds Bundle and a Metadata Display.
 * Allows direct access exposure of Twig Template generated Metadata per node.
 *
 * @ConfigEntityType(
 *   id = "metadataexpose_entity",
 *   label = @Translation("Exposed Metadata using Twig Configuration"),
 *   handlers = {
 *     "list_builder" = "\Drupal\format_strawberryfield\Entity\Controller\MetadataExposeConfigEntityListBuilder",
 *     "form" = {
 *       "add" = "Drupal\format_strawberryfield\Form\MetadataExposeConfigEntityForm",
 *       "edit" = "Drupal\format_strawberryfield\Form\MetadataExposeConfigEntityForm",
 *       "delete" = "Drupal\format_strawberryfield\Form\MetadataExposeConfigEntityDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\format_strawberryfield\MetadataExposeConfigEntityHtmlRouteProvider",
 *     },
 *   },
 *   config_prefix = "metadataexpose_entity",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "active" = "active",
 *   },
 *  config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "target_entity_types",
 *     "source_entityfield_name",
 *     "processor_entity_id",
 *     "cache",
 *     "active",
 *   },
 *   links = {
 *     "edit-form" = "/admin/config/archipelago/metadataexpose/{metadataexpose_entity}/edit",
 *     "add-form" = "/admin/config/archipelago/metadataexpose/add",
 *     "delete-form" = "/admin/config/archipelago/metadataexpose/{metadataexpose_entity}/delete",
 *     "collection" = "/admin/config/archipelago/metadataexpose",
 *   }
 * )
 */
class MetadataExposeConfigEntity extends ConfigEntityBase implements MetadataConfigInterface {

  use DependencySerializationTrait;
  /**
   * The ID of the Metadata Config Entity.
   *
   * @var string
   */

  protected $id;

  /**
   * The human-readable name of the form or view mode.
   *
   * @var string
   */
  protected $label;

  /**
   * The node entity types this configuration entity can target.
   *
   * @var array
   */
  protected $target_entity_types = [];

  /**
   * The Metadata Display entity id.
   *
   * Of type \Drupal\format_strawberryfield\MetadataDisplayInterface.
   *
   * @var string
   */
  protected $processor_entity_id = NULL;

  /**
   * The MetadataDisplay Entity.
   *
   * @var \Drupal\format_strawberryfield\MetadataDisplayInterface
   */
  protected $metadataDisplayEntity = NULL;

  /**
   * The Node Field Name that will server as source.
   *
   * @var string
   */
  protected $source_entityfield_name = NULL;

  /**
   * Whether or not the rendered output of is cached by default.
   *
   * @var bool
   */
  protected $cache = TRUE;

  /**
   * If the Config Entity is active or not.
   *
   * @var bool
   */
  protected $active = TRUE;

  /**
   * The Label for this config entity.
   *
   * @return string
   *   The label.
   */
  public function getLabel(): string {
    return $this->label;
  }

  /**
   * Label setter.
   *
   * @param string $label
   *   The config entity label.
   */
  public function setLabel(string $label): void {
    $this->label = $label;
  }

  /**
   * Returns target Entity Types.
   *
   * @return array
   *   The target entity types / Node bundles.
   */
  public function getTargetEntityTypes(): array {
    return $this->target_entity_types;
  }

  /**
   * Target Entity Types setter.
   *
   * @param array $target_entity_types
   *   A list of Node Types or Bundle names.
   */
  public function setTargetEntityTypes(array $target_entity_types): void {
    $this->target_entity_types = $target_entity_types;
  }

  /**
   * Processor entity id getter.
   *
   * @return string
   *   The processor entity id.
   */
  public function getProcessorEntityId() {
    return $this->processor_entity_id;
  }

  /**
   * Processor entity id setter.
   *
   * @param string $processor_entity_id
   *   The processor entity ID.
   */
  public function setProcessorEntityId(string $processor_entity_id): void {
    $this->processor_entity_id = $processor_entity_id;
  }

  /**
   * Gets the Metadata Display Entity.
   *
   * @return \Drupal\format_strawberryfield\MetadataDisplayInterface|Null
   *   Either a Metadata Display entity or missing reference.
   */
  public function getMetadataDisplayEntity() {
    if (empty($this->metadataDisplayEntity)) {
      $this->metadataDisplayEntity = \Drupal::service('entity_type.manager')->getStorage('metadatadisplay_entity')
        ->load($this->processor_entity_id);
    }
    return $this->metadataDisplayEntity;
  }

  /**
   * Source Entity Field Name Getter.
   */
  public function getSourceEntityfieldName() {
    return $this->source_entityfield_name;
  }

  /**
   * Sets the Source Entity Field name.
   *
   * @param string $source_entityfield_name
   *   The source entity field name.
   *
   * @return $this
   *   The Class instance.
   */
  public function setSourceEntityfieldName(string $source_entityfield_name
  ) {
    $this->source_entityfield_name = $source_entityfield_name;
    return $this;
  }

  /**
   * Checks if cached.
   *
   * @return bool
   *   True If this is cached.
   */
  public function isCache(): bool {
    return $this->cache;
  }

  /**
   * Sets cached flag.
   *
   * @param bool $cache
   *   The cache Flag.
   */
  public function setCache(bool $cache): void {
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function sort(
    ConfigEntityInterface $a,
    ConfigEntityInterface $b
  ) {
    /** @var \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity $a */
    /** @var \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity $b */
    // Sort by the type the source Metadata Display this entity uses.
    $a_type = $a->getLabel();
    $b_type = $b->getLabel();
    $type_order = strnatcasecmp($a_type, $b_type);
    return $type_order != 0 ? $type_order : parent::sort($a, $b);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    $this->addDependency('module', \Drupal::entityTypeManager()->getDefinition(
      'node')->getProvider());
    $this->addDependency('module', \Drupal::entityTypeManager()->getDefinition(
      'metadatadisplay_entity')->getProvider());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    \Drupal::entityTypeManager()->clearCachedDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(
    EntityStorageInterface $storage,
    array $entities
  ) {
    parent::preDelete($storage, $entities);
    \Drupal::entityTypeManager()->clearCachedDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  protected function urlRouteParameters($rel) {
    $uri_route_parameters = parent::urlRouteParameters($rel);
    if ($rel === 'add-form') {
      $uri_route_parameters['entity_type_id'] = $this->getProcessorEntityId();
    }
    return $uri_route_parameters;
  }

  /**
   * Checks if this Config is active.
   *
   * @return bool
   *   True if active.
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * Sets the active flag.
   *
   * @param bool $active
   *   True to set Active.
   */
  public function setActive(bool $active): void {
    $this->active = $active;
  }


 /* Generates a valid Example URL given a Node UUID.
  *
  * @param string $uuid
  * An UUID of a node configured for this Exposed endpoint.
  * @return \Drupal\Core\GeneratedUrl|null|string
  * A Drupal URL if we have enough arguments or NULL if not.
 */
  public function getUrlForItemFromNodeUUID(string $uuid, $absolute = FALSE) {

    $url = NULL;
    $extension = NULL;

    try {
      $responsetypefield = $this->getMetadataDisplayEntity()->get('mimetype');
      $responsetype = $responsetypefield->first()->getValue();
      // We can have a LogicException or a Data One, both extend different
      // classes, so better catch any.
    }
    catch (\Exception $exception) {
      $this->messenger()->addError(
        'For Metadata endpoint @metadataexposed, either @metadatadisplay does not exist or has no mimetype Drupal field setup or no value for it. Please check that @metadatadisplay still exists, the entity has that field and there is a default Output Format value for it. Error message is @e',
        [
          '@metadataexposed' => $this->label(),
          '@metadatadisplay' => $this->getMetadataDisplayEntity()->label(),
          '@e' => $exception->getMessage(),
        ]
      );
      return $url;
    }

    $responsetype = !empty($responsetype['value']) ? $responsetype['value'] : 'text/html';

    // Guess extension based on mime,
    // \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeExtensionGuesser
    // has no application/ld+json even if recent

    if ($responsetype == 'application/ld+json') {
      $extension = 'jsonld';
    }
    else {
      $guesser = ExtensionGuesser::getInstance();
      $extension = $guesser->guess($responsetype);
    }

    $filename = !empty($extension) ? 'default.' . $extension : 'default.html';

    $url = \Drupal::urlGenerator()
      ->generateFromRoute(
        'format_strawberryfield.metadatadisplay_caster',
        [
          'node' => $uuid,
          'metadataexposeconfig_entity' => $this->id(),
          'format' => $filename,
        ],
        [
          'absolute' => $absolute
        ]
      );
    return $url;
  }



}
