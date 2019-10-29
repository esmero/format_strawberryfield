<?php

namespace Drupal\format_strawberryfield\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;

/**
 * Form handler for metadataexpose_entity config entity add and edit.
 */
class MetadataExposeConfigEntityForm extends EntityForm {


  /* @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface */
  protected $entityTypeBundleInfo;

  /**
   * MetadataExposeConfigEntityForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /* @var MetadataExposeConfigEntity $metadataconfig */
    $metadataconfig = $this->entity;

    $entity = NULL;

    if ($metadataconfig->getTargetEntityTypes()) {
      $entity = $this->entityTypeManager->getStorage('metadatadisplay_entity')
        ->load($metadataconfig->getProcessorEntityId());
    }
    $bundles = $this->entityTypeBundleInfo->getBundleInfo('node');
    $nodebundleoptions = [];
    foreach ($bundles as $id => $definition) {
      $nodebundleoptions[$id] = $definition['label'];
    }
    $form = [
      'label' => [
        '#id' => 'label',
        '#type' => 'textfield',
        '#title' => $this->t('A label for this exposed Metadata endpoint'),
        '#default_value' => $metadataconfig->label(),
        '#required' => TRUE,
      ],
      'id' => [
        '#type' => 'machine_name',
        '#default_value' => $metadataconfig->id(),
        '#machine_name' => [
          'label' => '<br/>' . $this->t('Machine name used in the URL path to access your Metadata'),
          'exists' => [$this, 'exist'],
          'source' => ['label'],
        ],
        '#disabled' => !$metadataconfig->isNew(),
        '#description' => $this->t('Machine name used in the URL path to access your Metadata. E.g if "iiif" is chosen as value, access URL will be in the form of "/do/1/metadata/<b>iiif</b>/manifest.json"'),
      ],
      'processor_entity_id' => [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('The Metadata display Entity (Twig) to be used to generate data at this endpoint.'),
        '#target_type' => 'metadatadisplay_entity',
        '#selection_handler' => 'default:metadatadisplay',
        '#validate_reference' => TRUE,
        '#required' => TRUE,
        '#default_value' => (!$metadataconfig->isNew()) ? $metadataconfig->getMetadataDisplayEntity() : NULL,
      ],
      'target_entity_types' => [
        '#type' => 'checkboxes',
        '#options' => $nodebundleoptions,
        '#title' => $this->t('Which Content types will this metadata be allowed to be exposed?'),
        '#required'=> TRUE,
        '#default_value' => (!$metadataconfig->isNew()) ? $metadataconfig->getTargetEntityTypes(): [],
        ],
      'active' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Is this exposed Metadata Endpoint active?'),
        '#return_value' => TRUE,
        '#default_value' => TRUE
      ]
     ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $metadataconfig = $this->entity;

    $status = false;
    $status = $metadataconfig->save();

    if ($status) {
      $this->messenger()->addMessage(
        $this->t(
          'Saved the %label Metadata exposure endpoint.',
          [
            '%label' => $metadataconfig->label(),
          ]
        )
      );
    }
    else {
      $this->messenger()->addMessage(
        $this->t(
          'The %label Example was not saved.',
          [
            '%label' => $metadataconfig->label(),
          ]
        ),
        MessengerInterface::TYPE_ERROR
      );
    }

    $form_state->setRedirect('entity.metadataexpose_entity.collection');
  }

  /**
   * Helper function to check whether an configuration entity exists.
   */
  public function exist($id) {
    $entity = $this->entityTypeManager->getStorage('metadataexpose_entity')
      ->getQuery()
      ->condition('id', $id)
      ->execute();
    return (bool) $entity;
  }

}