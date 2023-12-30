<?php
namespace Drupal\format_strawberryfield\Form;
use Drupal\ami\Entity\amiSetEntity;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
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

    // Start with Exposed Metadata display entities
    if ($metadatadisplay_entity) {

      $form['metadatadisplay_usage']['metadataexpose_entity'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Exposed Metadata Display Entities using @label', [
          '@label' => $metadatadisplay_entity->label()
        ]),
        'table' => [ '#type' => 'table',
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
          $form['metadatadisplay_usage']['metadataexpose_entity']['table'][$metadataexpose_entity->id()]['label'] = $metadataexpose_entity->toLink($this->t('Edit @label', ['@label' => $metadataexpose_entity->label()]), 'edit-form')->toRenderable();
        }
      }
      try {
        $form['metadatadisplay_usage']['ami_set_entity'] = [
          '#type' => 'fieldset',
          '#title' => $this->t('AMI sets using @label', [
            '@label' => $metadatadisplay_entity->label()
          ]),
          'table' => [ '#type' => 'table',
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
      }
      catch (PluginException) {
        // Means AMI module is not installed, the AMI type entity does not exist. That is Ok.
        // simply no output
      }
    }

    $form['metadatadisplay_usage']['#markup'] = 'Settings form for Metadata Display Entity. Manage field settings here.';
    return $form;
  }
}
