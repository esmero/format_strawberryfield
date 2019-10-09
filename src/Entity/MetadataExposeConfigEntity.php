<?php
namespace Drupal\format_strawberryfield\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\format_strawberryfield\MetadataConfigInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Defines the MetadataExposeConfigEntity entity.*
 *
 * This entity binds Bundle and a Metadata Display to allow direct access exposure
 * of Twig Template generated Metadata output on each node.
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
   * The node entity types this configuration entity can target
   *
   * @var array
   */
  protected $target_entity_types = [];

  /**
   * The Metadata Display entity id
   * for a \Drupal\format_strawberryfield\MetadataDisplayInterface
   *
   * @var string
   */
  protected $processor_entity_id = NULL;

  /**
   * @var \Drupal\format_strawberryfield\MetadataDisplayInterface
   */
  protected $metadataDisplayEntity = NULL;

  /**
   * The Node Field Name that will server as source
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
   * If the Config Entity is active or not
   *
   * @var boolean
   */
  protected $active = TRUE;

  /**
   * @return string
   */
  public function getLabel(): string {
    return $this->label;
  }

  /**
   * @param string $label
   */
  public function setLabel(string $label): void {
    $this->label = $label;
  }

  /**
   * @return array
   */
  public function getTargetEntityTypes(): array {
    return $this->target_entity_types;
  }

  /**
   * @param array $target_entity_types
   */
  public function setTargetEntityTypes(array $target_entity_types): void {
    $this->target_entity_types = $target_entity_types;
  }

  /**
   * @return string
   */
  public function getProcessorEntityId() {
    return $this->processor_entity_id;
  }

  /**
   * @param string $processor_entity_id
   */
  public function setProcessorEntityId(string $processor_entity_id): void {
    $this->processor_entity_id = $processor_entity_id;
  }

  /**
   * @return \Drupal\format_strawberryfield\MetadataDisplayInterface
   */
  public function getMetadataDisplayEntity(
  ) {
    if (empty($this->metadataDisplayEntity)) {
      $this->metadataDisplayEntity = \Drupal::service('entity_type.manager')->getStorage('metadatadisplay_entity')
      ->load($this->processor_entity_id);
    }
    return $this->metadataDisplayEntity;
  }


  public function getSourceEntityfieldName() {
    return $this->source_entityfield_name;
  }


  public function setSourceEntityfieldName(string $source_entityfield_name
  ) {
    $this->source_entityfield_name = $source_entityfield_name;
    return $this;
  }

  /**
   * @return bool
   */
  public function isCache(): bool {
    return $this->cache;
  }


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
    /** @var \Drupal\Core\Entity\EntityDisplayModeInterface $a */
    /** @var \Drupal\Core\Entity\EntityDisplayModeInterface $b */
    // Sort by the type the source Metadata Display this entity uses.
    $a_type = $a->getProcessorEntity();
    $b_type = $b->getProcessorEntity();
    $type_order = strnatcasecmp($a_type, $b_type);
    return $type_order != 0 ? $type_order : parent::sort($a, $b);
  }


  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();

    $this->addDependency('module',  \Drupal::entityTypeManager()->getDefinition(
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
   * @return bool
   */
  public function isActive(): bool {
    return $this->active;
  }

  /**
   * @param bool $active
   */
  public function setActive(bool $active): void {
    $this->active = $active;
  }


}