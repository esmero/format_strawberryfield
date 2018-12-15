<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/19/18
 * Time: 4:38 PM
 */

namespace Drupal\format_strawberryfield\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Metadata Display overview administration form.
 *
 * @internal
 */

class MetadataDisplayAdminOverview extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStoreFactory;

  /**
   * Creates a MetadataDisplayAdminOverview form.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, ModuleHandlerInterface $module_handler, PrivateTempStoreFactory $temp_store_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->moduleHandler = $module_handler;
    $this->tempStoreFactory = $temp_store_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('module_handler'),
      $container->get('tempstore.private')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'metadatadisplay_admin_overview';
  }

  /**
   * Form constructor for the metadatadisplay overview administration form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   * @param string $type
   *   The type of the overview form ('approval' or 'new').
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $type = 'new') {

    // Build an 'Update options' form.
    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Update options'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['container-inline']],
    ];

    if ($type == 'approval') {
      $options['publish'] = $this->t('Publish the selected Metadata Displays');
    }
    else {
      $options['unpublish'] = $this->t('Unpublish the selected Metadata Displays');
    }
    $options['delete'] = $this->t('Delete the selected Metadata Displays');

    $form['options']['operation'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#title_display' => 'invisible',
      '#options' => $options,
      '#default_value' => 'publish',
    ];
    $form['options']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
    ];

    // Load the comments that need to be displayed.
    $header['id'] = $this->t('Metadata Display ID');
    $header['name'] = $this->t('Name');
    $header['last update'] = $this->t('Last update');
    $header['operations'] = $this->t('Operations');

    $cids = $this->entityTypeManager->getStorage('metadatadisplay_entity')->getQuery()
      ->tableSort($header)
      ->pager(50)
      ->execute();

    /** @var $metadatadisplays \Drupal\comment\CommentInterface[] */
    $metadatadisplays = $this->entityTypeManager->getStorage('metadatadisplay_entity')->loadMultiple($cids);

    // Build a table listing the appropriate comments.
    $options = [];


    foreach ($metadatadisplays as $metadatadisplay) {
      /** @var $commented_entity \Drupal\Core\Entity\EntityInterface */

      $options[$metadatadisplay->id()] = [
        'title' => ['data' => ['#title' => $metadatadisplay->id()]],
        'name' => [
          'data' => [
            '#type' => 'link',
            '#title' => $metadatadisplay->name->value,
            '#url' => $metadatadisplay->toUrl('edit-form'),
          ],
        ],
        'last update' => [
          'data' => [
            '#theme' => 'username',
            '#account' => \Drupal::service('date.formatter')->format($metadatadisplay->changed->value, 'custom', 'd/m/Y'),
          ],
        ],

      ];
      $links = [];
      $links['edit'] = [
        'title' => $this->t('Edit'),
        'url' => $metadatadisplay->toUrl('edit-form'),
      ];
      if ($this->moduleHandler->moduleExists('content_translation') && $this->moduleHandler->invoke('content_translation', 'translate_access', [$metadatadisplay])->isAllowed()) {
        $links['translate'] = [
          'title' => $this->t('Translate'),
          'url' => $metadatadisplay->toUrl('drupal:content-translation-overview'),
        ];
      }
      $options[$metadatadisplay->id()]['operations']['data'] = [
        '#type' => 'operations',
        '#links' => $links,
      ];
    }

    $form['comments'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#empty' => $this->t('No metadata display templates available.'),
    ];

    $form['pager'] = ['#type' => 'pager'];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // TODO: Implement submitForm() method.
  }


}
