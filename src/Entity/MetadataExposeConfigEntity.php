<?php
namespace Drupal\format_strawberryfield\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\format_strawberryfield\MetadataConfigInterface;
use Drupal\format_strawberryfield\MetadataDisplayInterface;
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
 *     "targetEntityTypes",
 *     "sourceEntity",
 *     "processorEntity",
 *     "cache",
 *     "active",
 *   },
 *   links = {
 *     "canonical" = "/admin/config/archipelago/metadataexpose/{metadataexpose_entity}",
 *     "edit-form" = "/admin/config/archipelago/metadataexpose/{metadataexpose_entity}",
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
   * The entity types this configuration entity can target
   *
   * @var array
   */
  protected $targetEntityTypes = [];

  /**
   * The Metadata Display entity this configuration will use to process the JSON
   *
   *
   * @var \Drupal\format_strawberryfield\MetadataDisplayInterface
   */
  protected $processorEntity;


  /**
   * The Field Name that will server as source
   *
   * @var string
   */
  protected $sourceEntityFieldName;


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
  protected $active = true;


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
   * @return mixed
   */
  public function getProcessorEntity() {
    return $this->sourceEntity;
  }


  /**
   * @param \Drupal\format_strawberryfield\MetadataDisplayInterface $metadatadisplay_entity
   *
   * @return $this
   */
  public function setProcessorEntity(MetadataDisplayInterface $metadatadisplay_entity) {
    $this->sourceEntity = $metadatadisplay_entity;
    return $this;
  }


  /**
   * @return string
   */
  public function getTargetEntityTypes(): array {
    return $this->targetEntityTypes;
  }


  /**
   * @param string $targetEntityType
   *
   * @return $this
   */
  public function setTargetEntityTypes(array $targetEntityTypes) {
    $this->targetEntityTypes = $targetEntityTypes;
    return $this;
  }


  /**
   * @return string
   */
  public function getSourceEntityFieldName(): string {
    return $this->sourceEntityFieldName;
  }

  /**
   * @param string $sourceEntityFieldName
   */
  public function setSourceEntityFieldName(string $sourceEntityFieldName
  ) {
    $this->sourceEntityFieldName = $sourceEntityFieldName;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $target_entity_type = $this->entityTypeManager->getDefinition(
      $this->targetEntityType
    );
    $source_entity_type = $this->entityTypeManager->getDefinition(
      $this->sourceEntity->getEntityTypeId()
    );

    dpm(\Drupal::service('entity_field.manager')->getFieldMapByFieldType('strawberryfield_field'));

    $this->addDependency('module', $target_entity_type->getProvider());
    $this->addDependency('module', $source_entity_type->getProvider());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    \Drupal::entityTypeManager()->clearCachedFieldDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  public static function preDelete(
    EntityStorageInterface $storage,
    array $entities
  ) {
    parent::preDelete($storage, $entities);
    \Drupal::entityTypeManager()->clearCachedFieldDefinitions();
  }

  /**
   * {@inheritdoc}
   */
  protected function urlRouteParameters($rel) {
    $uri_route_parameters = parent::urlRouteParameters($rel);
    if ($rel === 'add-form') {
      $uri_route_parameters['entity_type_id'] = $this->getTargetEntityType();
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