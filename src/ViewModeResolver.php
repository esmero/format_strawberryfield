<?php

namespace Drupal\format_strawberryfield;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\UseCacheBackendTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\Context\ContextRepositoryInterface;
use Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface;

/**
 * View Mode resolver service.
 *
 * @ingroup format_strawberryfield
 */
class ViewModeResolver implements ViewModeResolverInterface {
  use UseCacheBackendTrait;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * @var \Drupal\Core\Plugin\Context\ContextRepositoryInterface
   */
  private ContextRepositoryInterface $ContextRepository;

  /**
   * DisplayResolver constructor.
   *
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityServiceInterface $strawberryfield_utility_service
   *   The SBF utility Service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Plugin\Context\ContextRepositoryInterface $contextRepository
   */
  public function __construct(StrawberryfieldUtilityServiceInterface $strawberryfield_utility_service, ConfigFactoryInterface $config_factory, ContextRepositoryInterface $contextRepository) {
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->configFactory = $config_factory;
    $this->ContextRepository = $contextRepository;
  }

  /**
   * {@inheritdoc}
   */
  public function getCandidates(ContentEntityInterface $entity) {
    if ($ado_types = $this->getAdoTypes($entity)) {
      $view_modes = array_filter($this->getSortedMappings(), function ($mapping) use ($ado_types) {
        return ($mapping['active'] == TRUE) && in_array($mapping['jsontype'], $ado_types);
      });
      return $view_modes;
    }

    return ['default'];
  }

  /**
   * {@inheritdoc}
   */
  public function get(ContentEntityInterface $entity) {
    $ado_types = $this->getAdoTypes($entity);
    if (!empty($ado_types)) {
      foreach ($this->getSortedMappings() as $mapping) {
        if (($mapping['active'] == TRUE) && in_array($mapping['jsontype'], $ado_types)) {
          return $mapping['view_mode'];
        }
      }
    }

    return 'default';
  }

  /**
   * Get a list of ADO types based on the SBF.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   *
   * @return array
   *   Array of ado types.
   *
   * @throws \Exception
   */
  protected function getAdoTypes(ContentEntityInterface $entity) {
    $cache_id = 'format_strawberry:view_mode_adotypes:' . $entity->id();
    $cached = $this->cacheGet($cache_id);
    if ($cached) {
      return $cached->data;
    }

    $ado_types = [];
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield($entity)) {
      foreach ($sbf_fields as $field_name) {
        /* @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $field */
        $field = $entity->get($field_name);
        if (!$field->isEmpty()) {
          foreach ($field->getIterator() as $delta => $itemfield) {
            /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $itemfield */
            $flat_values = (array) $itemfield->provideFlatten();
            if (isset($flat_values['type'])) {
              $ado_types = array_merge($ado_types, (array) $flat_values['type']);
            }
          }
        }
      }
    }

    // Cache tags need to depend on the entity itself, the new $cache_id but
    // also the ones from config.
    // @TODO: Change this for Drupal 9 as mergeTags will accept more arguments.
    // @see https://www.drupal.org/node/3125498
    $config = $this->configFactory->get('format_strawberryfield.viewmodemapping_settings');
    $this->cacheSet($cache_id, $ado_types, CacheBackendInterface::CACHE_PERMANENT, Cache::mergeTags(Cache::mergeTags($entity->getCacheTags(), $config->getCacheTags()), [$cache_id]));
    return $ado_types;
  }

  /**
   * Get mappings from config and sort them.
   *
   * @return array
   *   Array of mappings.
   */
  protected function getSortedMappings() {
    $config = $this->configFactory->get('format_strawberryfield.viewmodemapping_settings');
    $view_mode_mappings = $config->get('type_to_viewmode');
    $view_mode_mappings = !empty($view_mode_mappings) ? (array) $view_mode_mappings : [];
    usort($view_mode_mappings, ['\Drupal\format_strawberryfield\Form\ViewModeMappingSettingsForm', 'sortSettings']);
    return $view_mode_mappings;
  }

}
