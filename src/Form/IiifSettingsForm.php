<?php

namespace Drupal\format_strawberryfield\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Entity\Index;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\format_strawberryfield\Tools\IiifUrlValidator;


/**
 * Class IiifSettingsForm.
 */
class IiifSettingsForm extends ConfigFormBase {

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->setConfigFactory($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory')
    );
  }


  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'format_strawberryfield.iiif_settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'format_strawberryfield_iiif_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('format_strawberryfield.iiif_settings');
    $form['info'] = [
      '#markup' => $this->t(
        'This IIIF Server configuration URLs are used as defaults for field formatters using IIIF, but can be overridden on a one by one basis when setting up your formatters for each Display Mode.'
      ),
    ];

    $form['pub_server_url'] = [
      '#type'          => 'url',
      '#title'         => $this->t(
        'Base URL of your IIIF Media Server public accessible from the Outside World.'
      ),
      '#description'   => $this->t(
        'Please provide a publicly accessible IIIF server URL. This URL will be used for AJAX and JS calls. Trailing Slashes will be removed.'
      ),
      '#default_value' => !empty($config->get('pub_server_url')) ? $config->get(
        'pub_server_url'
      ) : 'http://localhost:8183/iiif/2',
      '#required'      => TRUE
    ];

    $form['int_server_url'] = [
      '#type'          => 'url',
      '#title'         => $this->t(
        'Base URL of your IIIF Media Server accessible from inside this Webserver.'
      ),
      '#description'   => $this->t(
        'Please provide Internal IIIF server URL. This URL will be used by Internal Server calls and needs to be locally accessible by your server, e.g 127.0.0.1 or an local Docker alias. Trailing Slashes will be removed.'
      ),
      '#default_value' => !empty($config->get('int_server_url')) ? $config->get(
        'int_server_url'
      ) : 'http://esmero-cantaloupe:8182/iiif/2',
      '#required'      => TRUE
    ];

    $form['iiif_content_search_api_active'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable IIIF Content Search API V1 and V2 endpoints'),
      '#default_value' => $config->get('iiif_content_search_api_active') ?? FALSE,
      '#description' => $this->t('APIs are available at the following path: "/iiifcontentsearch/{version}/do/{node_uuid}/metadatadisplayexposed/{metadataexposeconfig_entity}/mode/{mode}/page/{page}"
      <br> with:
        <ul>
        <li>{version} one of [v1,v2]</li>
        <li>{node_uuid} the UUID of the ADO whose Manifest you want to search inside</li>
        <li>{metadataexposeconfig_entity} the machine name of the exposed Metadata Display endpoint used to render the Manifest that is calling the API (e.g. iiifmanifest)</li>
        <li>{mode} one of [simple,advanced]. Advanced is the smartest choice. Simple is faster, but requires your Canvas ids to be exactly in this pattern <em>http(s)://domain.ext/do/{node_uuid}/{file_uuid}/canvas/{internal_to_the_file_sequence_order}</em></li>
        <li>{page} 0 to N depedening on the Number of results. By default, please use 0</li>
        </ul>
      ')
    ];

    $form['iiif_content_search_validate_exposed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only allow searches inside a Manifest If the Manifest itself (for an ADO) defines the Search Endpoints as a Service'),
      '#default_value' => $config->get('iiif_content_search_validate_exposed') ?? FALSE,
      '#description' => $this->t('If enabled we will double check if the calling IIIF Manifest defines the Endpoint(s) in the `service` key. If unchecked any Manifest will be searchable by calling an API URL directly.'),
    ];

    $field_options = $this->getSbfFields('node_id');
    $form['iiif_content_search_api_parent_node_fields'] = [
      '#type'          => 'select',
      '#title'         => $this->t(
        'IIIF  Content Search API: field(s) that holds Parent Nodes'
      ),
      '#options' => $field_options,
      '#description'   => $this->t(
        'Strawberry Flavor Data Source Search API Fields that can be used to connect a Strawberry Flavor to a Parent ADO.'
      ),
      '#default_value' => !empty($config->get('iiif_content_search_api_parent_node_fields')) ? $config->get(
        'iiif_content_search_api_parent_node_fields'
      ) : [],
      '#required' => FALSE,
      '#states' => [
        'required' => [':input[name="iiif_content_search_api_active"]' => ['checked' => true]],
      ],
      '#multiple' => TRUE
    ];

    $field_options = $this->getSbfFields('file_url');

    $form['iiif_content_search_api_visual_enabled_processors'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t(
        'Strawberry Runner processors that should be searched against for visual highlights.'
      ),
      '#description' => $this->t(
        'e.g Strawberry Flavor Data might have been generated by the "ocr" strawberry runners processor. A comma separated list of processors (machine names) that generated miniOCR.'
      ),
      '#default_value' => !empty($config->get('iiif_content_search_api_visual_enabled_processors')) ? $config->get(
        'iiif_content_search_api_visual_enabled_processors'
      ) : [],
      '#required' => FALSE,
      '#states' => [
        'required' => [':input[name="iiif_content_search_api_active"]' => ['checked' => true]],
      ],
    ];
    $form['iiif_content_search_api_time_enabled_processors'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t(
        'Strawberry Runner processors that should be searched against for time based media.'
      ),
      '#description' => $this->t(
        'e.g Strawberry Flavor Data might have been generated by the "subtitle" strawberry runners processor. These will have time based fragments and will match IIIF Annotations with motivation supplementing and target the time based media on the parent Canvas. A comma separated list of processors (machine names) that generated time based transcripts encoded as miniOCR.'
      ),
      '#default_value' => !empty($config->get('iiif_content_search_api_time_enabled_processors')) ? $config->get(
        'iiif_content_search_api_time_enabled_processors'
      ) : [],
      '#required' => FALSE,
    ];
    $form['iiif_content_search_time_targetannotations'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Target the VTT Supplementing Annotation'),
      '#default_value' => $config->get('iiif_content_search_time_targetannotations') ?? FALSE,
      '#description' => $this->t('If enabled (aligned with the specs) the target of a hit result will point to the supplementing Annotation containing in its body the VTT file. If not the Canvas containing in its body a Media Resource (less precise but more compatible with Viewers'),
    ];

    $form['iiif_content_search_api_text_enabled_processors'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t(
        'Strawberry Runner processors that should be searched against plain text extractions.'
      ),
      '#description' => $this->t(
        'e.g Strawberry Flavor Data might have been generated by the "text" strawberry runners processor. These will not have coordinates but will match IIIF Annotations with motivation supplementing and target the whole canvas. A comma separated list of processors (machine names) that generated pure text extractions without miniOCR.'
      ),
      '#default_value' => !empty($config->get('iiif_content_search_api_text_enabled_processors')) ? $config->get(
        'iiif_content_search_api_text_enabled_processors'
      ) : [],
      '#required' => FALSE,
    ];

    $form['iiif_content_search_api_file_uri_fields'] = [
      '#type'          => 'select',
      '#title'         => $this->t(
        'IIIF Content Search API: field(s) that hold the URI of the File that produced the Searchable content'
      ),
      '#options' => $field_options,
      '#description'   => $this->t(
        'Strawberry Flavor Data Source Search API Fields that hold the URI of the File that generated its content.'
      ),
      '#default_value' => !empty($config->get('iiif_content_search_api_file_uri_fields')) ? $config->get(
        'iiif_content_search_api_file_uri_fields'
      ) : [],
      '#required' => FALSE,
      '#states' => [
        'required' => [':input[name="iiif_content_search_api_active"]' => ['checked' => true]],
      ],
      '#multiple' => TRUE
    ];

    $form['iiif_content_search_api_metadata'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow search across ADO associated metadata in Solr'),
      '#default_value' => $config->get('iiif_content_search_api_metadata') ?? FALSE,
      '#description' => $this->t('If enabled, Content Search API results will also do a general search on ADO based Full Text Solr Fields. The returned areas, as with plain text extractions, will not have coordinates and will highlight any complete canvas associated to the matched ADO(s). '),
    ];

    $field_options = $this->getADOFields('node_id');
    $form['iiiif_content_search_api_metadata_parent_node_fields'] = [
      '#type'          => 'select',
      '#title'         => $this->t(
        'IIIF Content Search API: ADO field(s) that holds Parent Nodes'
      ),
      '#options' => $field_options,
      '#description'   => $this->t(
        'ADO (Node) Search API Fields that can be used to connect an ADO to a Parent ADO, if "Allow search across ADO associated metadata in Solr" is checked.'
      ),
      '#default_value' => !empty($config->get('iiiif_content_search_api_metadata_parent_node_fields')) ? $config->get(
        'iiiif_content_search_api_metadata_parent_node_fields'
      ) : [],
      '#required' => FALSE,
      '#states' => [
        'required' => [
          ':input[name="iiif_content_search_api_active"]' => ['checked' => true],
          'AND',
          ':input[name="iiif_content_search_api_metadata"]' => ['checked' => true],
          ],
      ],
      '#multiple' => TRUE
    ];
    $field_options = $this->getADOFields('node_uuid');
    $form['iiiif_content_search_api_metadata_node_uuid_fields'] = [
      '#type'          => 'select',
      '#title'         => $this->t(
        'IIIF Content Search API: ADO field that holds its own Node/UUID (Optional)'
      ),
      '#options' => $field_options,
      '#description'   => $this->t(
        'ADO (Node) Search API Field that holds an ADO\'s UUID (self) or an UUID of a related one. Optional. You might need to add this field to your Search index and select it here only IF you have IIIF Manifests where canvases are not generated (via Twig) through a normal  parent/children relationship. e.g A IIIF Manifests that shows Arbitrary or not associated via a predicate canvases from multiple ADOs for example using a View that selects based on 
        subjects'
      ),
      '#default_value' => !empty($config->get('iiiif_content_search_api_metadata_node_uuid_fields')) ? $config->get(
        'iiiif_content_search_api_metadata_node_uuid_fields'
      ) : [],
      '#required' => FALSE,
      '#multiple' => TRUE
    ];

    $field_options = $this->getADOorGLobalFulltextFields();
    $form['iiiif_content_search_api_metadata_node_fulltext_fields'] = [
      '#type'          => 'select',
      '#title'         => $this->t(
        'IIIF Content Search API: Full Text ADO field(s) to search against'
      ),
      '#options' => $field_options,
      '#description'   => $this->t(
        'ADO (Node) Search API Fields that will be used to search/return metadata matches, if "Allow search across ADO associated metadata in Solr" is checked.'
      ),
      '#default_value' => !empty($config->get('iiiif_content_search_api_metadata_node_fulltext_fields')) ? $config->get(
        'iiiif_content_search_api_metadata_node_fulltext_fields'
      ) : [],
      '#required' => FALSE,
      '#states' => [
        'required' => [
          ':input[name="iiif_content_search_api_active"]' => ['checked' => true],
          'AND',
          ':input[name="iiif_content_search_api_metadata"]' => ['checked' => true],
        ],
      ],
      '#multiple' => TRUE
    ];

    $form['iiif_content_search_api_results_per_page'] = [
      '#type' => 'number',
      '#title'  => $this->t(
        'IIIF Content Search API: Max Results per Page'
      ),
      '#min' => 0,
      '#max' => 100,
      '#default_value' => !empty($config->get('iiif_content_search_api_results_per_page')) ? $config->get(
        'iiif_content_search_api_results_per_page'
      ) : 25,
    ];

    $form['iiif_content_search_api_query_length'] = [
      '#type' => 'number',
      '#title'  => $this->t(
        'IIIF Content Search API: Max allowed characters/length for a Search term'
      ),
      '#min' => 1,
      '#max' => 256,
      '#default_value' => !empty($config->get('iiif_content_search_api_query_length')) ? $config->get(
        'iiif_content_search_api_query_length'
      ) : 64,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * @inheritDoc
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $validator = new IiifUrlValidator();

    $internalUrlValid = $validator->checkUrl(
      $form_state->getValue('int_server_url'),
      $validator::IIIF_INTERNAL_URL_TYPE
    );
    if (!$internalUrlValid) {
      $form_state->setErrorByName(
        'int_server_url',
        $this->t("We could not contact your Internal IIIF server")
      );
    }

    $publicUrlValid = $validator->checkUrl(
      $form_state->getValue('pub_server_url'),
      $validator::IIIF_EXTERNAL_URL_TYPE
    );
    if (!$publicUrlValid) {
      $form_state->setErrorByName(
        'pub_server_url',
        $this->t("We could not contact your Public IIIF server")
      );
    }

    parent::validateForm(
      $form,
      $form_state
    ); // TODO: Change the autogenerated stub
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('format_strawberryfield.iiif_settings')
      ->set(
        'pub_server_url', rtrim($form_state->getValue('pub_server_url'), "/")
      )
      ->set(
        'int_server_url', rtrim($form_state->getValue('int_server_url'), "/")
      )
      ->set('iiif_content_search_api_parent_node_fields',
        $form_state->getValue('iiif_content_search_api_parent_node_fields') ?? [])
      ->set('iiif_content_search_api_file_uri_fields',
        $form_state->getValue('iiif_content_search_api_file_uri_fields') ?? [])
      ->set('iiif_content_search_api_results_per_page',
        $form_state->getValue('iiif_content_search_api_results_per_page') ?? 25)
      ->set('iiif_content_search_api_query_length',
        $form_state->getValue('iiif_content_search_api_query_length') ?? 64)
      ->set('iiif_content_search_validate_exposed',
        $form_state->getValue('iiif_content_search_validate_exposed') ?? FALSE)
      ->set('iiif_content_search_api_active',
        $form_state->getValue('iiif_content_search_api_active') ?? FALSE)
      ->set('iiif_content_search_api_visual_enabled_processors',
        $form_state->getValue('iiif_content_search_api_visual_enabled_processors') ?? '')
      ->set('iiif_content_search_api_time_enabled_processors',
        $form_state->getValue('iiif_content_search_api_time_enabled_processors') ?? '')
      ->set('iiif_content_search_api_text_enabled_processors',
        $form_state->getValue('iiif_content_search_api_text_enabled_processors') ?? '')
      ->set('iiif_content_search_time_targetannotations',
        $form_state->getValue('iiif_content_search_time_targetannotations') ?? FALSE)
      ->set('iiif_content_search_api_metadata',
        $form_state->getValue('iiif_content_search_api_metadata') ?? FALSE)
      ->set('iiiif_content_search_api_metadata_parent_node_fields',
        $form_state->getValue('iiiif_content_search_api_metadata_parent_node_fields') ?? [])
      ->set('iiiif_content_search_api_metadata_node_fulltext_fields',
        $form_state->getValue('iiiif_content_search_api_metadata_node_fulltext_fields') ?? [])
      ->set('iiiif_content_search_api_metadata_node_uuid_fields',
        $form_state->getValue('iiiif_content_search_api_metadata_node_uuid_fields') ?? [])
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Retrieves a list of all fields for the Strawberry Flavor Data Source.
   *
   * @return string[]
   *   An options list of field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getSbfFields($type = NULL) {
    $fields = [];
    $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
    // Type can be here either the File URL or a Parent Node one (id)

    /* @var \Drupal\search_api\IndexInterface[] $indexes */
    $result_snippets = [];
    foreach ($indexes as $search_api_index) {


      $fields_info = $search_api_index->getFields();
      foreach ($fields_info as $field_id => $field) {
        if (($field->getDatasourceId() == 'strawberryfield_flavor_datasource')
          && (in_array($field->getType(), ['string','integer']))
        ) {
          // Anything except text, fulltext or any solr_text variations. Also skip direct node id and UUIDs which would
          // basically return the same ADO as input filtered, given that those are unique.
          $property_path = $field->getPropertyPath();
          if ($type == 'file_url') {
            $property_path_pieces = explode(':', $property_path);
            if (end($property_path_pieces) == 'uri') {
              $fields[$field_id] = $field->getPrefixedLabel();
            }
          }
          elseif ($type == 'node_id') {
            $property_path_pieces = explode(':', $property_path);
            if (in_array(end($property_path_pieces),  ['nid','parent_id'])) {
              $fields[$field_id] = $field->getPrefixedLabel();
            }
          }
          else {
            $field->getDataDefinition();
            $fields[$field_id] = $field->getPrefixedLabel();
          }
          //&& ($property_path !== "nid" || $property_path !== "uuid")


        }
      }
    }
    return $fields;
  }

  /**
   * Retrieves a list of all fields for the Strawberry Flavor Data Source.
   *
   * @return string[]
   *   An options list of field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getADOFields($type = NULL) {
    $fields = [];
    $indexes = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->loadMultiple();
    // Add the indexes with matching server to $indexes_by_server
    $indexes_enabled = [];
    foreach ($indexes as $index) {
      if ($index->isServerEnabled() && $index->isValidDatasource('entity:node')) {
        $indexes_enabled[] = $index;
      }
    }

    /* @var \Drupal\search_api\IndexInterface[] $indexes */
    foreach ($indexes_enabled as $search_api_index) {
      $fields_info = $search_api_index->getFields();
      foreach ($fields_info as $field_id => $field) {
        if (($field->getDatasourceId() == 'entity:node')
          && (in_array($field->getType(), ['string','integer']))
        ) {
          // Anything except text, fulltext or any solr_text variations. Also skip direct node id and UUIDs which would
          // basically return the same ADO as input filtered, given that those are unique.
          $property_path = $field->getPropertyPath();
        if ($type == 'node_id') {
            $property_path_pieces = explode(':', $property_path);
            if (in_array(end($property_path_pieces),  ['nid','parent_id'])) {
              $fields[$field_id] = $field->getPrefixedLabel();
            }
          }
          elseif ($type == 'node_uuid') {
            $property_path_pieces = explode(':', $property_path);
            if (in_array(end($property_path_pieces),  ['uuid'])) {
              $fields[$field_id] = $field->getPrefixedLabel();
            }
          }
          else {
            $field->getDataDefinition();
            $fields[$field_id] = $field->getPrefixedLabel();
          }
        }
      }
    }
    return $fields;
  }

  /**
   * Retrieves a list of all available ADO fulltext fields.
   *
   * @return string[]
   *   An options list of fulltext field identifiers mapped to their prefixed
   *   labels.
   */
  protected function getADOorGLobalFulltextFields() {
    $fields = [];
    $indexes = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->loadMultiple();
    // Add the indexes with matching server to $indexes_by_server
    $indexes_enabled = [];
    foreach ($indexes as $index) {
      if ($index->isServerEnabled() && $index->isValidDatasource('entity:node')) {
        $indexes_enabled[] = $index;
      }
    }
    /* @var \Drupal\search_api\IndexInterface[] $search_api_index */
    foreach ($indexes_enabled as $index) {
      /** @var \Drupal\search_api\IndexInterface $index */
      $fields_info = $index->getFields();
      foreach ($index->getFulltextFields() as $field_id) {
        // This includes also Aggregated and Global fields to allow "Rendered entity" to be used.
        if ($fields_info[$field_id]->getDatasourceId() == 'entity:node' ||  !$fields_info[$field_id]->getDatasourceId() ) {
          $fields[$field_id] = $fields_info[$field_id]->getPrefixedLabel() . '(' . $fields_info[$field_id]->getFieldIdentifier() . ')';
        }
      }
      return $fields;
    }
  }

}
