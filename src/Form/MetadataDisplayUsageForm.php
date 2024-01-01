<?php
namespace Drupal\format_strawberryfield\Form;
use Drupal\ami\Entity\amiSetEntity;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\format_strawberryfield\MetadataDisplayInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Form controller for the MetadataDisplayEntity entity Usage forms.
 *
 * @ingroup format_strawberryfield
 */
class MetadataDisplayUsageForm extends FormBase {

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
   * @param EntityRepositoryInterface $entity_repository
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param ConfigFactoryInterface $config_factory
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeManagerInterface $entity_type_manager, ConfigFactoryInterface $config_factory, ) {
    $this->entityRepository = $entity_repository;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }
  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'format_strawberryfield_metadatadisplay_usage';
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Empty implementation of the abstract submit class.
  }


  /**
   * Define the form used for MetadataDisplayEntity settings.
   * @return array
   *   Form definition array.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state, MetadataDisplayInterface $metadatadisplay_entity = NULL) {

    $used_metadataexpose_entity = [];
    $used_entity_view_display = [];
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
            $this->t('Replace'),
          ],
          '#empty' => $this->t('No usage.'),
        ]
      ];
      $metadataexpose_entities = $this->entityTypeManager->getStorage('metadataexpose_entity')->loadMultiple();
      foreach ($metadataexpose_entities as $metadataexpose_entity) {
        $metadatadisplayentity_uuid = $metadataexpose_entity->getMetadatadisplayentityUuid();
        if ($metadatadisplayentity_uuid && $metadatadisplay_entity->uuid() == $metadatadisplayentity_uuid) {
          $used_metadataexpose_entity[] = $metadataexpose_entity->id();
          $form['metadatadisplay_usage']['metadataexpose_entity']['table'][$metadataexpose_entity->id()]['label'] = $metadataexpose_entity->toLink($this->t('Edit @label', ['@label' => $metadataexpose_entity->label()]), 'edit-form')->toRenderable();
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
              $this->t('Replace'),
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
            $this->t('Replace'),
          ],
          '#empty' => $this->t('No usage.'),
        ]
      ];

      // We need to get first all view modes
      $view_modes = \Drupal::service('entity_display.repository')->getViewModes('node');
      foreach ($this->configFactory()->listAll('core.entity_view_display.node.') as $entity_view_display_config) {
        $entity_view = $this->configFactory()->get($entity_view_display_config);
        $in_use = FALSE;
        // metadataexposeentity_source & metadataexposeentity_overlaysource
        if ($entity_view) {
          $data = $entity_view->getRawData();
          if (isset($data['third_party_settings']['ds']['fields'])) {
            foreach ($data['third_party_settings']['ds']['fields'] as $field) {
              if (($field['settings']['formatter']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                $in_use = TRUE;
              }
              if (isset($field['settings']['formatter']['metadataexposeentity_source']) && in_array($field['settings']['formatter']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                $in_use = TRUE;
              }
              if (isset($field['settings']['formatter']['metadataexposeentity_overlaysource']) && in_array($field['settings']['formatter']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                $in_use = TRUE;
              }
            }
          }
          if (isset($data['content'])) {
            foreach ($data['content'] as $realfield) {
              if (($realfield['settings']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                $in_use = TRUE;
              }
              if (isset($realfield['settings']['metadataexposeentity_source']) && in_array($realfield['settings']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                $in_use = TRUE;
              }
              if (isset($realfield['settings']['metadataexposeentity_overlaysource']) && in_array($realfield['settings']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                $in_use = TRUE;
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
            $used_entity_view_display[$bundle][] = $entity_view_display->getMode();
            // Special parameter used to easily recognize all Field UI routes.
            $form['metadatadisplay_usage']['entity_view']['table'][$entity_view_display->id()]['label'] = $entity_view_display_link->toRenderable();
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
            $this->t('Replace'),
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
          $display = $display;
          if (($display['display_options']['row'] ?? '') == "fields") {
            if (isset($display['display_options']['fields'])) {
              foreach ($display['display_options']['fields'] as $field) {
                if (($field['settings']['metadatadisplayentity_uuid'] ?? NULL) == $metadatadisplay_entity->uuid()) {
                  $in_use = TRUE;
                }
                if (isset($field['settings']['metadataexposeentity_source']) && in_array($field['settings']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                  $in_use = TRUE;
                }
                if (isset($field['settings']['metadataexposeentity_overlaysource']) && in_array($field['settings']['metadataexposeentity_source'] ?? '', $used_metadataexpose_entity)) {
                  $in_use = TRUE;
                }
              }
            }
          }
          elseif (isset($display['display_options']['row']['options']['view_modes']['entity:node'])) {
            // GETTING SOME FALSE POSITIVES HERE. SEEMS LIKE $displays does not save the "overrides?"
            // @TODO before 2024, inspect the structure. There must be some logic
            foreach (($display['display_options']['row']['options']['view_modes']['entity:node'] ?? []) as $bundle => $view_mode ) {
              if (isset($used_entity_view_display[$bundle]) && in_array($view_mode, $used_entity_view_display[$bundle])) {
                $in_use = TRUE;
              }
            }
          }
          if ($in_use) {
            if (\Drupal::moduleHandler()->moduleExists('views_ui')) {
              $view_display_link = Link::createFromRoute($this->t('Edit @view_display_label - @display_name', ['@view_display_label' => $view->get('label'), '@display_name' => $display_name]), 'entity.view.edit_display_form', ['view' => $view->get('id'), 'display_id' => $display_name]);
              $form['metadatadisplay_usage']['view']['table'][$view->get('id').'.'.$display_name]['label'] = $view_display_link->toRenderable();
            }
          }
        }
      }
    }
    $form['metadatadisplay_usage']['#markup'] = 'Settings form for Metadata Display Entity. Manage field settings here.';
    return $form;
  }
}
