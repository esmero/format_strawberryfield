<?php

namespace Drupal\format_strawberryfield;

use Drupal\ami\Entity\amiSetEntity;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Metadata Display Usage Service.
 *
 * @ingroup format_strawberryfield
 */
class MetadataDisplayUsageService implements MetadataDisplayUsageServiceInterface {

  use StringTranslationTrait;

  /**
   * The entity repository service.
   *
   * @var EntityRepositoryInterface
   */
  private EntityRepositoryInterface $entityRepository;
  /**
   * @var EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  private $account;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  private $entityDisplayRepository;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * @param EntityRepositoryInterface $entity_repository
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param ConfigFactoryInterface $config_factory
   * @param EntityRepositoryInterface $entity_display_repository
   * @param AccountInterface $current_user
   * @param ModuleHandlerInterface $module_handler
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, EntityDisplayRepositoryInterface $entity_display_repository,  AccountInterface $current_user, ModuleHandlerInterface $module_handler ) {
    $this->entityRepository = $entity_repository;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_user;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->moduleHandler = $module_handler;
  }

  public function getRenderableUsage(MetadataDisplayInterface $metadatadisplay_entity):array {
    $used_metadataexpose_entity = [];
    $used_entity_view_display = [];
    $how_text = ['@direct' => 'Directly', '@view_mode' => 'Via Entity View Mode: @view_mode', '@metadataexposeentity' => 'Via Exposed Metadata Display Entity: @metadataexposeentity' ];
    if ($metadatadisplay_entity) {
      // Start with Exposed Metadata display entities
      $form['metadatadisplay_usage']['metadataexpose_entity'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Exposed Metadata Display Entities using @label', [
          '@label' => $metadatadisplay_entity->label()
        ]),
        'table' => ['#type' => 'table',
          '#prefix' => '<div id="table-fieldset-wrapper">',
          '#suffix' => '</div>',
          '#header' => [
            $this->t('Label'),
            $this->t('How'),
          ],
          '#empty' => $this->t('No usage.'),
        ]
      ];
      $metadataexpose_entities = $this->entityTypeManager->getStorage('metadataexpose_entity')->loadMultiple();
      foreach ($metadataexpose_entities as $metadataexpose_entity) {
        $metadatadisplayentity_uuid = $metadataexpose_entity->getMetadatadisplayentityUuid();
        if ($metadatadisplayentity_uuid && $metadatadisplay_entity->uuid() == $metadatadisplayentity_uuid) {
          $used_metadataexpose_entity[$metadataexpose_entity->id()] = $metadataexpose_entity->label();
          $form['metadatadisplay_usage']['metadataexpose_entity']['table'][$metadataexpose_entity->id()]['label'] = $metadataexpose_entity->toLink($this->t('Edit @label', ['@label' => $metadataexpose_entity->label()]), 'edit-form')->toRenderable();
          $form['metadatadisplay_usage']['metadataexpose_entity']['table'][$metadataexpose_entity->id()]['how']['#markup'] =  $this->t('Direct');
        }
      }
      try {
        // AMI sets
        $form['metadatadisplay_usage']['ami_set_entity'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('AMI sets using @label', [
            '@label' => $metadatadisplay_entity->label()
          ]),
          'table' => ['#type' => 'table',
            '#prefix' => '<div id="table-fieldset-wrapper">',
            '#suffix' => '</div>',
            '#header' => [
              $this->t('Label'),
              $this->t('How'),
            ],
            '#empty' => $this->t('No usage.'),
          ]
        ];
        $ami_entities_storage = $this->entityTypeManager->getStorage('ami_set_entity');
        $ami_entities = $ami_entities_storage->loadMultiple();
        foreach ($ami_entities as $ami_entity) {
          /** @var amiSetEntity $ami_entity */
          $in_use = FALSE;
          foreach ($ami_entity->get('set') as $item) {
            /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $item */
            $data = $item->provideDecoded(TRUE);
            switch ($data['mapping']['globalmapping'] ?? NULL) {
              case 'custom':
                foreach ($data['mapping']['custommapping_settings'] ?? [] as $type => $settings) {
                  if ((($settings['metadata'] ?? '') == 'template') && (($settings['metadata_config']['template'] ?? '') == $metadatadisplay_entity->id())) {
                    $in_use = TRUE;
                    break;
                  }
                }
                break;
              case  'template':
                if (($data['mapping']['globalmapping_settings']['template'] ?? '') == $metadatadisplay_entity->id()) {
                  $in_use = TRUE;
                  break;
                }
                break;
              default:
                $in_use = FALSE;
                break;
            }
            break; // We only want a single $data here.
          }
          if ($in_use) {
            $form['metadatadisplay_usage']['ami_set_entity']['table'][$ami_entity->id()]['label'] = $ami_entity->toLink($this->t('Edit @label', ['@label' => $ami_entity->label()]), 'edit-form')->toRenderable();
            $form['metadatadisplay_usage']['ami_set_entity']['table'][$ami_entity->id()]['how']['#markup']  = $this->t('Direct');
          }
        }
      } catch (PluginException) {
        // Means AMI module is not installed, the AMI type entity does not exist. That is Ok.
        // simply no output
      }


      // Now Entity view display configs for nodes.

      $form['metadatadisplay_usage']['entity_view'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Entity View Modes using @label', [
          '@label' => $metadatadisplay_entity->label()
        ]),
        'table' => ['#type' => 'table',
          '#prefix' => '<div id="table-fieldset-wrapper">',
          '#suffix' => '</div>',
          '#header' => [
            $this->t('Label'),
            $this->t('How'),
          ],
          '#empty' => $this->t('No usage.'),
        ]
      ];

      // We need to get first all view modes
      $view_modes = $this->entityDisplayRepository->getViewModes('node');
      foreach ($this->configFactory->listAll('core.entity_view_display.node.') as $entity_view_display_config) {
        $entity_view = $this->configFactory->get($entity_view_display_config);
        $in_use = FALSE;
        // metadataexposeentity_source & metadataexposeentity_overlaysource
        if ($entity_view) {
          $how = [];
          $data = $entity_view->getRawData();
          if (isset($data['third_party_settings']['ds']['fields'])) {
            foreach ($data['third_party_settings']['ds']['fields'] as $field) {
              if (($field['settings']['formatter']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                $in_use = TRUE;
                $how = $how + ['@direct' => 'direct'];
              }
              if (isset($field['settings']['formatter']['metadataexposeentity_source']) && array_key_exists($field['settings']['formatter']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                $in_use = TRUE;
                $how = $how + ['@metadataexposeentity' => $used_metadataexpose_entity[$field['settings']['formatter']['metadataexposeentity_source']]];
              }
              if (isset($field['settings']['formatter']['metadataexposeentity_overlaysource']) && array_key_exists($field['settings']['formatter']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                $in_use = TRUE;
                $how = $how + ['@metadataexposeentity' => $used_metadataexpose_entity[$field['settings']['formatter']['metadataexposeentity_source']]];
              }
            }
          }
          if (isset($data['content'])) {
            foreach ($data['content'] as $realfield) {
              if (($realfield['settings']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                $in_use = TRUE;
                $how = $how + ['@direct' => 'direct'];
              }
              if (!empty($realfield['settings']['metadataexposeentity_source']) && $realfield['settings']['metadataexposeentity_source']!= '' && array_key_exists($realfield['settings']['metadataexposeentity_source'] ?? NULL, $used_metadataexpose_entity)) {
                $in_use = TRUE;
                $how = $how + ['@metadataexposeentity' => $used_metadataexpose_entity[$realfield['settings']['metadataexposeentity_source']]];
              }
              if (!empty($realfield['settings']['metadataexposeentity_overlaysource']) && $realfield['settings']['metadataexposeentity_source']!= '' && array_key_exists($realfield['settings']['metadataexposeentity_source'] ?? NULL, $used_metadataexpose_entity)) {
                $in_use = TRUE;
                $how = $how + ['@metadataexposeentity' => $used_metadataexpose_entity[$realfield['settings']['metadataexposeentity_source']]];
              }
            }
          }
          if ($in_use) {
            $entity_view_display_storage = $this->entityTypeManager->getStorage('entity_view_display');
            /** @var EntityViewDisplay $entity_view_display */
            $entity_view_display = $entity_view_display_storage->load($entity_view->get('id'));

            $bundle = $entity_view_display->getTargetBundle();
            // Default has no label... just named default
            $label = $view_modes[$entity_view_display->getMode()]['label'] ?? 'Default';
            $entity_view_display_link = Link::createFromRoute($this->t('Edit @entity_view_display_label',['@entity_view_display_label' => $label]), "entity.entity_view_display.node.view_mode", [
              'entity_type_id' => 'node',
              'node_type' => $bundle,
              'view_mode_name' => $entity_view_display->getMode()
            ]);
            $used_entity_view_display[$bundle][$entity_view_display->getMode()] = $label;
            // Special parameter used to easily recognize all Field UI routes.
            $form['metadatadisplay_usage']['entity_view']['table'][$entity_view_display->id()]['label'] = $entity_view_display_link->toRenderable();
            $how_present_text = array_intersect_key($how_text, array_filter($how));
            $form['metadatadisplay_usage']['entity_view']['table'][$entity_view_display->id()]['how']['#markup'] =  $this->t(implode(' and ', $how_present_text), $how);
          }
        }
      }


      // Let's get Views now.

      $form['metadatadisplay_usage']['view'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Views using @label', [
          '@label' => $metadatadisplay_entity->label()
        ]),
        'table' => ['#type' => 'table',
          '#prefix' => '<div id="table-fieldset-wrapper">',
          '#suffix' => '</div>',
          '#header' => [
            $this->t('Label'),
            $this->t('How'),
          ],
          '#empty' => $this->t('No usage.'),
        ]
      ];

      // We want all of them , even if disabled.
      $entity_ids = $this->entityTypeManager->getStorage('view')->getQuery('AND')
        ->execute();

      foreach ($this->entityTypeManager->getStorage('view')->loadMultiple($entity_ids) as $view) {
        // Check each display to see if it meets the criteria and is enabled.
        foreach ($view->get('display') as $display_name => $display) {
          $in_use = FALSE;
          $how = [];
          $display = $display;
          if (in_array(($display['display_options']['row']['type'] ?? ''), ['fields','data_field'])) {
            if (isset($display['display_options']['fields'])) {
              foreach ($display['display_options']['fields'] as $field) {
                if (($field['settings']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                  $in_use = TRUE;
                  $how = $how + ['@direct' => 'direct'];
                }
                if (isset($field['settings']['metadataexposeentity_source']) && array_key_exists($field['settings']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                  $in_use = TRUE;
                  $how = $how + ['@metadataexposeentity' => $used_metadataexpose_entity[$field['settings']['metadataexposeentity_source']]];
                }
                if (isset($field['settings']['metadataexposeentity_overlaysource']) && array_key_exists($field['settings']['metadataexposeentity_overlaysource'] ?? '', $used_metadataexpose_entity)) {
                  $in_use = TRUE;
                  $how = $how + ['@metadataexposeentity' => $used_metadataexpose_entity[$field['settings']['metadataexposeentity_overlaysource']]];
                }
              }
            }
          }
          elseif (isset($display['display_options']['row']['options']['view_modes']['entity:node'])) {
            // GETTING SOME FALSE POSITIVES HERE. SEEMS LIKE $displays does not save the "overrides?"
            // @TODO before 2024, inspect the structure. There must be some logic
            foreach (($display['display_options']['row']['options']['view_modes']['entity:node'] ?? []) as $bundle => $view_mode ) {
              if (isset($used_entity_view_display[$bundle]) && array_key_exists($view_mode, $used_entity_view_display[$bundle])) {
                $in_use = TRUE;
                $how = $how + ['@view_mode' => $used_entity_view_display[$bundle][$view_mode]];
              }
            }
          }
          if ($in_use) {
            if ($this->moduleHandler->moduleExists('views_ui')) {
              $view_display_link = Link::createFromRoute($this->t('Edit @view_display_label - @display_name', ['@view_display_label' => $view->get('label'), '@display_name' => $display_name]), 'entity.view.edit_display_form', ['view' => $view->get('id'), 'display_id' => $display_name]);
              $form['metadatadisplay_usage']['view']['table'][$view->get('id').'.'.$display_name]['label'] = $view_display_link->toRenderable();
              $how_present_text = array_intersect_key($how_text, array_filter($how));
              $form['metadatadisplay_usage']['view']['table'][$view->get('id').'.'.$display_name]['how']['#markup'] =  $this->t(implode(' and ', $how_present_text), $how);
            }
          }
        }
      }
    }
    $form['metadatadisplay_usage']['#markup'] = $this->t('Direct and indirect usage of <em>@label</em> across your whole system.', [
      '@label' => $metadatadisplay_entity->label()
    ]);
    return $form;
  }


  public function getUsage(MetadataDisplayInterface $metadatadisplay_entity):bool {
    $used_metadataexpose_entity = [];
    $used_entity_view_display = [];
    if ($metadatadisplay_entity) {
      $metadataexpose_entities = $this->entityTypeManager->getStorage('metadataexpose_entity')->loadMultiple();
      foreach ($metadataexpose_entities as $metadataexpose_entity) {
        $metadatadisplayentity_uuid = $metadataexpose_entity->getMetadatadisplayentityUuid();
        if ($metadatadisplayentity_uuid && $metadatadisplay_entity->uuid() == $metadatadisplayentity_uuid) {
          $used_metadataexpose_entity[$metadataexpose_entity->id()] = $metadataexpose_entity->label();
          return TRUE;
        }
      }
      try {
        $ami_entities_storage = $this->entityTypeManager->getStorage('ami_set_entity');
        $ami_entities = $ami_entities_storage->loadMultiple();
        foreach ($ami_entities as $ami_entity) {
          /** @var amiSetEntity $ami_entity */
          foreach ($ami_entity->get('set') as $item) {
            /** @var \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $item */
            $data = $item->provideDecoded(TRUE);
            switch ($data['mapping']['globalmapping'] ?? NULL) {
              case 'custom':
                foreach ($data['mapping']['custommapping_settings'] ?? [] as $type => $settings) {
                  if ((($settings['metadata'] ?? '') == 'template') && (($settings['metadata_config']['template'] ?? '') == $metadatadisplay_entity->id())) {
                    return TRUE;
                  }
                }
                break;
              case  'template':
                if (($data['mapping']['globalmapping_settings']['template'] ?? '') == $metadatadisplay_entity->id()) {
                  return TRUE;
                }
                break;
              default:
                break;
            }
            break; // We only want a single $data here.
          }
        }
      } catch (PluginException) {
        // Means AMI module is not installed, the AMI type entity does not exist. That is Ok.
        // simply no output
      }

      foreach ($this->configFactory->listAll('core.entity_view_display.node.') as $entity_view_display_config) {
        $entity_view = $this->configFactory->get($entity_view_display_config);
        // metadataexposeentity_source & metadataexposeentity_overlaysource
        if ($entity_view) {
          $data = $entity_view->getRawData();
          if (isset($data['third_party_settings']['ds']['fields'])) {
            foreach ($data['third_party_settings']['ds']['fields'] as $field) {
              if (($field['settings']['formatter']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                return TRUE;
              }
              if (isset($field['settings']['formatter']['metadataexposeentity_source']) && array_key_exists($field['settings']['formatter']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                return TRUE;
              }
              if (isset($field['settings']['formatter']['metadataexposeentity_overlaysource']) && array_key_exists($field['settings']['formatter']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                return TRUE;
              }
            }
          }
          if (isset($data['content'])) {
            foreach ($data['content'] as $realfield) {
              if (($realfield['settings']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                return TRUE;
              }
              if (isset($realfield['settings']['metadataexposeentity_source']) && array_key_exists($realfield['settings']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                return TRUE;
              }
              if (isset($realfield['settings']['metadataexposeentity_overlaysource']) && array_key_exists($realfield['settings']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                return TRUE;
              }
            }
          }
        }
      }

      // We want all of them , even if disabled.
      $entity_ids = $this->entityTypeManager->getStorage('view')->getQuery('AND')
        ->execute();

      foreach ($this->entityTypeManager->getStorage('view')->loadMultiple($entity_ids) as $view) {
        // Check each display to see if it meets the criteria and is enabled.
        foreach ($view->get('display') as $display) {
          $display = $display;
          // We don't check for Rendered ones here bc that would had returned TRUE way sooner.
          if (in_array(($display['display_options']['row']['type'] ?? ''), ['fields','data_field'])) {
            if (isset($display['display_options']['fields'])) {
              foreach ($display['display_options']['fields'] as $field) {
                if (($field['settings']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                  return TRUE;
                }
                if (isset($field['settings']['metadataexposeentity_source']) && array_key_exists($field['settings']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                  return TRUE;
                }
                if (isset($field['settings']['metadataexposeentity_overlaysource']) && array_key_exists($field['settings']['metadataexposeentity_overlaysource'] ?? '', $used_metadataexpose_entity)) {
                  return TRUE;
                }
              }
            }
          }
        }
      }
    }
    return FALSE;
  }
}
