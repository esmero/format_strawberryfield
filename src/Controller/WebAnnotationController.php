<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Url;

class WebAnnotationController extends ControllerBase {

  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;


  /**
   * The Drupal Renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The MIME type guesser.
   *
   * @var \Symfony\Component\Mime\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * The tempstore.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStore;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * WebAnnotationController constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   *   The SBF Utility Service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entitytype_manager
   *   The Entity Type Manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The Drupal Renderer Service.
   * @param \Symfony\Component\Mime\MimeTypeGuesserInterface $mime_type_guesser
   *   The Drupal Mime type guesser Service.
   * @param \Drupal\Core\TempStore\PrivateTempStoreFactory $temp_store_factory
   *   The tempstore factory.
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager,
    RendererInterface $renderer,
    MimeTypeGuesserInterface $mime_type_guesser,
    PrivateTempStoreFactory $temp_store_factory,
    ParseModePluginManager $parse_mode_manager
  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->renderer = $renderer;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->tempStore = $temp_store_factory->get('webannotation');
    $this->parseModeManager = $parse_mode_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('strawberryfield.utility'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('file.mime_type.guesser'),
      $container->get('tempstore.private'),
      $container->get('plugin.manager.search_api.parse_mode'),
      $container->get('config.factory')
    );
  }

  /**
   * Persist in temp Storage Webannotation Controller (POST).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTPD Resquest
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A simple JSON response.
   */
  public function persist(Request $request,
    ContentEntityInterface $node
  ) {
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {
      foreach ($sbf_fields as $field_name) {
        // by default only persist in the primary SBF
        // @TODO make which SBF will carry the load configurable?
        // Also. This is setting the whole list everytime
        // Should we deal with add/remove/update/edit independently?
        // This means more server logic
        // but less traffic?
        /* @var $field StrawberryFieldItem */
        $field = $node->get($field_name);
        // Symfony6 deprecated getting arrays via get()... like c'mom
        // we have to use all()[
        $everything = $this->requestStack->getCurrentRequest()->request->all();
        $annotations = $everything['data'] ?? NULL;
        $target =  $everything['target_resource'] ?? NULL;
        $keystoreid = $everything['keystoreid'] ?? NULL;
        $data = [
          'success' => true
        ];
        try {
          $existingannotations = $this->tempStore->get($keystoreid);
          $existingannotations = is_array($existingannotations) ? $existingannotations : [];
          $existingannotations[$target] = $annotations;
          $this->tempStore->set($keystoreid, $existingannotations);
        }
        catch (\Drupal\Core\TempStore\TempStoreException $exception) {
          $data = [
            'success' => false
          ];
        }
        break;
      }
    }
    else {
      throw new BadRequestHttpException(
        "This Content can not bear Web Annotations!"
      );
    }

    return new JsonResponse($data);
  }

  /**
   * Updates an existing WebAnnotation Method (PUSH).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTPD Resquest
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A simple JSON response.
   */
  public function putTemp(Request $request,
    ContentEntityInterface $node
  ) {
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

      // We are getting which field originate the annotations from AJAX.
      // Symfony6 deprecated getting arrays via get()... like c'mom
      // we have to use all()[
      $everything = $this->requestStack->getCurrentRequest()->request->all();
      $annotation = $everything['data'] ?? NULL;
      $target =  $everything['target_resource'] ?? NULL;
      $keystoreid = $everything['keystoreid'] ?? NULL;
      $data = [
        'success' => true
      ];

      try {
        $persisted = FALSE;
        if (isset($annotation['id'])) {
          $existingannotations = $this->tempStore->get($keystoreid);
          $existingannotations = is_array($existingannotations) ? $existingannotations : [];
          if (isset($existingannotations[$target])) {
            foreach ($existingannotations[$target] as $key => $existingannotation) {
              if (($existingannotation['id'] == $annotation['id'])) {
                $existingannotations[$target][$key] = $annotation;
                $persisted = TRUE;
                break;
              }
            }
            if ($persisted == FALSE) {
              throw new MethodNotAllowedHttpException(['PUT'],
                "The Annotation has no unique id!"
              );
            } // means it was new
          }
          $this->tempStore->set($keystoreid, $existingannotations);
        }
        else {
          throw new BadRequestHttpException(
            "The Annotation has no unique id!"
          );
        }
      }
      catch (\Drupal\Core\TempStore\TempStoreException $exception) {
        $data = [
          'success' => false
        ];
      }
    }
    else {
      throw new BadRequestHttpException(
        "This Content can not bear Web Annotations!"
      );
    }

    return new JsonResponse($data);
  }



  /**
   * Persist temp Controller Method (POST).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTPD Resquest
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A simple JSON response.
   */
  public function postTemp(Request $request,
    ContentEntityInterface $node
  ) {
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

      // We are getting which field originate the annotations from AJAX.
      // Symfony6 deprecated getting arrays via get()... like c'mom
      // we have to use all()[
      $everything = $this->requestStack->getCurrentRequest()->request->all();
      $annotation = $everything['data'] ?? NULL;
      $target =  $everything['target_resource'] ?? NULL;
      $keystoreid = $everything['keystoreid'] ?? NULL;
      $data = [
        'success' => true
      ];
      try {
        $persisted = FALSE;
        if (isset($annotation['id'])) {
          $existingannotations = $this->tempStore->get($keystoreid);
          $existingannotations = is_array($existingannotations) ? $existingannotations : [];
          if (isset($existingannotations[$target])) {
            foreach ($existingannotations[$target] as $key => &$existingannotation) {
              if (($existingannotation['id'] == $annotation['id'])) {
                throw new MethodNotAllowedHttpException(['POST'],
                  "The ID is already present, to update use PUT method"
                );
              }
            }
          }

          $existingannotations[$target][] = $annotation;
          $this->tempStore->set($keystoreid, $existingannotations);
        }
        else {
          throw new BadRequestHttpException(
            "The Annotation has no unique id!"
          );
        }
      }
      catch (\Drupal\Core\TempStore\TempStoreException $exception) {
        $data = [
          'success' => false
        ];
      }
    }
    else {
      throw new BadRequestHttpException(
        "This Content can not bear Web Annotations!"
      );
    }

    return new JsonResponse($data);
  }

  /**
   * Delete temp Controller Method (POST).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTPD Resquest
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   * @param string $keystoreid
   *  The keystore id to delete
   *
   * @return AjaxResponse
   *   A cacheable response.
   */
  public function deleteKeyStore(Request $request, ContentEntityInterface $node) {
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {
      $response = new AjaxResponse();
      foreach ($sbf_fields as $field_name) {
        /* @var $field \Drupal\Core\Field\FieldItemInterface */
        $field = $node->get($field_name);
        /** @var $field \Drupal\Core\Field\FieldItemList */
        foreach ($field->getIterator() as $delta => $itemfield) {
          $keystoreid = static::getTempStoreKeyName(
            $field_name,
            $delta,
            $node->uuid()
          );
          try {
            $this->tempStore->delete(trim($keystoreid));
          } catch (\Drupal\Core\TempStore\TempStoreException $exception) {
            $response->addCommand(
              new ReplaceCommand(
                '#edit-webannotations > div',
                'Something went awfully wrong and we could not discard your Annotation. Please try again.'
              )
            );
            return $response;
          }
        }
      }
    }
    else {
      throw new BadRequestHttpException(
        "This Content can not bear Web Annotations!"
      );
    }

    $response->addCommand(new RemoveCommand('#edit-webannotations'));
    return $response;
  }

  /**
   * Read existing WebAnnotations Controller Method (GET).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTPD Resquest
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   */
  public function read(Request $request,
    ContentEntityInterface $node
  ) {

    // WE do not want cache here
    // But starting to think Anonymous users should not use the tempStore at all.
    $build = [
      '#cache' => [
        'max-age' => 0,
      ],
    ];


    $return = [];
    // GET Argument (
    $target = $this->requestStack->getCurrentRequest()->query->get('target_resource');

    if (($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
        $node
      )) && !empty(trim($target))) {

      // We are getting which field originate the annotations from AJAX.
      // This time Ajax
      $keystoreid = $this->requestStack->getCurrentRequest()->query->get('keystoreid');

      $data = [
        'success' => true
      ];

      try {
        // See \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMediaFormatter::viewElements
        // It would have set initial values, so we do not need to read/iterate everytime
        $existingannotations = $this->tempStore->get($keystoreid);
        if ($existingannotations == null) {
          foreach ($sbf_fields as $field_name) {
            /* @var $field \Drupal\Core\Field\FieldItemInterface */
            $field = $node->get($field_name);
            /** @var $field \Drupal\Core\Field\FieldItemList */
            foreach ($field->getIterator() as $delta => $itemfield) {
              $potentialkeystoreid = static::getTempStoreKeyName(
                $field_name,
                $delta,
                $node->uuid()
              );
              if ($potentialkeystoreid == $keystoreid) {
                $existingannotations = static::primeKeyStore($itemfield, $keystoreid);
                break 2;
              }
            }
          }
        }
        $return = isset($existingannotations[$target]) && is_array($existingannotations[$target]) ? $existingannotations[$target] : [];
      }
      catch (\Drupal\Core\TempStore\TempStoreException $exception) {
        throw new ServiceUnavailableHttpException(
          "Temporary Storage for WebAnnotations is not working. Contact your admin."
        );
      }
    }
    else {
      throw new BadRequestHttpException(
        "Wrong request"
      );
    }
    $response = new CacheableJsonResponse($return);
    $response->addCacheableDependency($build);
    $response->addCacheableDependency($node);

    return $response;
  }

  /**
   * Read existing WebAnnotations From Solr/Flavors Controller Method (GET).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full http Request
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   */
  public function readFromFlavors(Request $request,
    ContentEntityInterface $node
  ) {
    if (!$node->access('view', $this->currentUser())) {
      throw new AccessDeniedHttpException(
        "Permission Denied."
      );
    }
    // WE do not want cache here
    // But starting to think Anonymous users should not use the tempStore at all.
    $build = [
      '#cache' => [
        'max-age' => -1,
      ],
    ];

    $existingannotations = [];
    $target = $this->requestStack->getCurrentRequest()->query->get('target_resource_uuid', '');
    if (!Uuid::isValid($target)) {
      throw new BadRequestHttpException(
        "Wrong request"
      );
    }
    // Processors or a $flavor_id are required. The latter wins and on a mistmatch will really just give you 0 results.
    // We might want to (eventually) decide if we want OCR (normal page level) to be fetched
    // as individual annotations or not at all.
    $processors = $this->requestStack->getCurrentRequest()->query->get('processors', NULL);
    $flavor_id = $this->requestStack->getCurrentRequest()->query->get('flavor_id', NULL);

    if ($processors || $flavor_id) {
      if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
        $node
      )) {
      }
      $data = [
        'success' => true
      ];
      try {
        // See \Drupal\format_strawberryfield\Plugin\Field\FieldFormatter\StrawberryMediaFormatter::viewElements
        // It would have set initial values, so we do not need to read/iterate everytime
        $targets = [];
        $file_uris = [];

        $file = $this->entityTypeManager()
          ->getStorage('file')
          ->loadByProperties(['uuid' => $target]);
        if (count($file) == 0 ) {
          throw new BadRequestHttpException(
            "Wrong request"
          );
        }

        $file = reset($file);
        $file_uris = [$file->getFileUri()];
        $targets = [$target];
        if ($flavor_id) {
          $flavor_ids = [$flavor_id];
        }
        else {
          $flavor_ids = [];
        }
        if ($processors) {
          $processors_list = [$processors];
        }
        else {
          $processors_list = [];
        }

        // This allows really for multiple targets. Also, we need more caching here.
        $existingannotations[$target] = $this->flavorfromSolrIndex($processors_list, $file_uris, $targets , [$node->uuid()],  $flavor_ids);
        $return = isset($existingannotations[$target]) && is_array($existingannotations[$target]) ? $existingannotations[$target] : [];
      }
      catch (\Exception $exception) {
        throw new ServiceUnavailableHttpException(
          "Annotation from StrawberryFlavor fetching failed. Contact your admin."
        );
      }
    }
    else {
      throw new BadRequestHttpException(
        "Wrong request"
      );
    }
    $response = new CacheableJsonResponse($return);
    $response->addCacheableDependency($build);
    $response->addCacheableDependency($node);

    return $response;
  }




  /**
   * Persist temp Controller Method (POST).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTPD Resquest
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse;
   *   A cacheable response.
   */
  public function deleteTemp(Request $request,
    ContentEntityInterface $node
  ) {
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

      // We are getting which field originate the annotations from AJAX.
      $everything = $this->requestStack->getCurrentRequest()->request->all();
      $annotation = $everything['data'] ?? NULL;
      $target =  $everything['target_resource'] ?? NULL;
      $keystoreid = $everything['keystoreid'] ?? NULL;

      $data = [
        'success' => true
      ];
      try {
        if (isset($annotation['id'])) {
          $existingannotations = $this->tempStore->get($keystoreid);
          $existingannotations = is_array($existingannotations) ? $existingannotations : [];
          if (isset($existingannotations[$target])) {
            foreach ($existingannotations[$target] as $key => $existingannotation) {
              if (($existingannotation['id'] == $annotation['id'])) {
                unset($existingannotations[$target][$key]);
                break;
              }
            }
            // Make sure we reorder them so they stay as indexed arrays
            if (empty(!$existingannotations[$target])) {
              $existingannotations[$target] = array_values($existingannotations[$target]);
            } else {
              //If empty totally remove
              unset($existingannotations[$target]);
            }
          }
          $this->tempStore->set($keystoreid, $existingannotations);
        }
        else {
          throw new BadRequestHttpException(
            "The Annotation has no unique id!"
          );
        }
      }
      catch (\Drupal\Core\TempStore\TempStoreException $exception) {
        $data = [
          'success' => false
        ];
      }
    }
    else {
      throw new BadRequestHttpException(
        "This Content can not bear Web Annotations!"
      );
    }

    return new JsonResponse($data);
  }

  /*
   * Delete temp Controller Method (POST).
   *

   */
  public static function deleteKeyStoreAjaxCallback(array &$form, FormStateInterface $form_state) {
    // Ok, this has a lot of Static loading of services instead of Injected dependency
    // But AJAX callbacks are sadly static! Gosh, sorry good coding practices avatars
    $response = new AjaxResponse();
    if ($form_state->get('hadAnnotations')) {
      $node = $form_state->getFormObject()->getEntity();
      $tempstore = \Drupal::service('tempstore.private')->get(
        'webannotation'
      );
      if ($sbf_fields = \Drupal::service('strawberryfield.utility')
        ->bearsStrawberryfield($node)) {

        foreach ($sbf_fields as $field_name) {
          /* @var $field \Drupal\Core\Field\FieldItemInterface */
          $field = $node->get($field_name);
          /** @var $field \Drupal\Core\Field\FieldItemList */
          foreach ($field->getIterator() as $delta => $itemfield) {
            $keystoreid = static::getTempStoreKeyName(
              $field_name,
              $delta,
              $node->uuid()
            );
            try {
              $tempstore->delete($keystoreid);
              // Do NOT SET stored settings back.
              // BECAUSE WE HAVE NOT RELOADED OUR NODE from storage yet OK?
            } catch (\Drupal\Core\TempStore\TempStoreException $exception) {
              $response->addCommand(
                new ReplaceCommand(
                  '#edit-webannotations > div',
                  'So Sorry Something went awfully wrong and we could not discard your Annotation. Please try again or reload.'
                )
              );
              return $response;
            }
          }
        }
      }
      else {
        return $response;
      }
      // Needed so all is restored from storage
      $node = node::load($node->id());
      $destination_url = Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]);
      $redirect_command = new RedirectCommand($destination_url->toString());
      $response->addCommand(new RemoveCommand('#edit-annotations'));
      $response->addCommand($redirect_command);
    }
    return $response;
  }





  /**
   * Gives us a key name used by the webforms and widgets.
   *
   * @param $fieldname
   * @param int $delta
   * @param string $entity_uuid
   *
   * @return string
   */
  public static function getTempStoreKeyName($fieldname, $delta = 0, $entity_uuid = '0') {
    $unique_seed = array_merge(
      [$fieldname],
      [$delta],
      [$entity_uuid]
    );
    return sha1(implode('-', $unique_seed));
  }

  /**
   * Primes the Web Annotation KeyStore with saved values.
   *
   * @param \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem $itemfield
   * @param null $keystoreid
   */
  public static function primeKeyStore(StrawberryFieldItem $itemfield,  $keystoreid = NULL) {
    if ($keystoreid == NULL && strlen(trim($keystoreid)) == 0) {
      return NULL;
    }
    $jsondata = $itemfield->provideDecoded(TRUE);
    $tempstore = \Drupal::service('tempstore.private')->get(
      'webannotation'
    );
    if (!empty($jsondata['ap:annotationCollection']) && is_array($jsondata['ap:annotationCollection'])) {
      $tempstore->set($keystoreid, $jsondata['ap:annotationCollection']);
      return $jsondata['ap:annotationCollection'];
    }
    else {
      $tempstore->set($keystoreid, []);
      return [];
    }
  }

  /**
   * Fetchs OCR from the backend and converts them to Annotations
   *
   * Very similar to \Drupal\strawberryfield\Controller\StrawberryfieldFlavorDatasourceSearchController::originalocrfromSolrIndex
   * but with more moving parts (and checks)
   *
   * @param array $processors
   * @param array $file_uris
   * @param array $file_uuids
   * @param array $node_ids
   * @param array $flavor_ids
   * @param int $offset
   * @param int $limit
   * @param bool $ocr
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function flavorfromSolrIndex(array $processors, array $file_uris, array $file_uuids, array $node_ids = [], array $flavor_ids = [], $offset = 0, $limit = 100, $ocr = FALSE): array {

    $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();

    /* @var \Drupal\search_api\IndexInterface[] $indexes */

    $result_snippets = [];
    $search_result = [];
    $annotations = [];

    foreach ($indexes as $search_api_index) {

      // Create the query.
      $query = $search_api_index->query([
        'limit' => $limit,
        'offset' => $offset,
      ]);

      $parse_mode = $this->parseModeManager->createInstance('direct');
      $query->setParseMode($parse_mode);
      // No key set here, this is a filters query only
      $allfields_translated_to_solr = $search_api_index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());
      // @TODO research if we can do a single Query instead of multiple ones?
      if ($ocr) {
        if (isset($allfields_translated_to_solr['ocr_text'])) {
          $query->setFulltextFields(['ocr_text']);
        }
        else {
          $this->getLogger('format_strawberryfield')->error('We can not execute a Content Search API query against XML OCR without a field named <em>ocr_text</em> of type Full Text Ocr Highlight');
          $search_result['annotations'] = [];
          $search_result['total'] = 0;
          return $search_result;
        }
      }
      else {
        if (isset($allfields_translated_to_solr['sbf_plaintext'])) {
          $query->setFulltextFields(['sbf_plaintext']);
        }
        else {
          $this->getLogger('format_strawberryfield')->error('We can not execute a Content Search API query against Plain Extracted Text without a field named <em>sbf_plaintext</em> of type Full Text');
          $search_result['annotations'] = [];
          $search_result['total'] = 0;
          return $search_result;
        }
      }
      $flavor_id_field = 'search_api_id';

      //@TODO: Should this also be a config as `iiif_content_search_api_parent_node_fields` is for example?
      $uuid_uri_field = 'file_uuid';

      $parent_conditions = $query->createConditionGroup('OR');
      $uri_conditions = $query->createConditionGroup('OR');
      $uuid_conditions = $query->createConditionGroup('OR');


      if (count($node_ids)) {
        if (count($parent_conditions->getConditions())) {
          $query->addConditionGroup($parent_conditions);
        }
      }

      $query->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource');
      if (count($flavor_ids)) {
        $query->addCondition('search_api_id', $flavor_ids, 'IN');
      }
      if (count($processors)) {
        $query->addCondition('processor_id', $processors, 'IN');
      }

      if (isset($allfields_translated_to_solr['ocr_text']) && $ocr) {
        // Will be used by \Drupal\strawberryfield\EventSubscriber\SearchApiSolrEventSubscriber::preQuery
        $query->setOption('ocr_highlight', 'off');
        // We are already checking if the Node can be viewed. Custom Data Sources can not depend on Solr node access policies.
        $query->setOption('search_api_bypass_access', TRUE);
      }
      if (isset($allfields_translated_to_solr['sbf_plaintext']) && !$ocr) {
        // Will be used by  \Drupal\strawberryfield\EventSubscriber\SearchApiSolrEventSubscriber::preQuery
        $query->setOption('sbf_highlight_fields', 'off');
        // We are already checking if the Node can be viewed. Custom Datasources can not depend on Solr node access policies.
        $query->setOption('search_api_bypass_access', TRUE);
      }

      $fields_to_retrieve['id'] = 'id';
      if (isset($allfields_translated_to_solr['parent_sequence_id'])) {
        $fields_to_retrieve['parent_sequence_id'] = $allfields_translated_to_solr['parent_sequence_id'];
      }
      if (isset($allfields_translated_to_solr['uuid'])) {
        $fields_to_retrieve['uuid'] = $allfields_translated_to_solr['uuid'];
      }
      if (isset($allfields_translated_to_solr['sequence_id'])) {
        $fields_to_retrieve['sequence_id'] = $allfields_translated_to_solr['sequence_id'];
        $query->sort('sequence_id', QueryInterface::SORT_ASC);
      }
      if (isset($allfields_translated_to_solr[$uuid_uri_field])) {
        $fields_to_retrieve[$uuid_uri_field] = $allfields_translated_to_solr[$uuid_uri_field];
        // Sadly we have to add the condition here, what if file_uuid is not defined?
      }
      else {
        $this->getLogger('format_strawberryfield')->warning('For Content Search API queries/WebAnnotations from Strawberryflavors, please add a search api field named <em>file_uuid</em> containing the UUID of the file entity that generated the extraction you want to search');
      }
      if (isset($allfields_translated_to_solr['fulltext'])) {
        $fields_to_retrieve['fulltext'] = $allfields_translated_to_solr['fulltext'];
      }
      else {
        $this->getLogger('format_strawberryfield')->warning('For WebAnnotations from Strawberryflavors using OCR, please add a search api field named <em>fulltext</em> containing the complete OCR as XML');
        $search_result['annotations'] = [];
        $search_result['total'] = 0;
        return $search_result;
      }

      $have_file_condition = FALSE;
      if (count($file_uris)) {
        //Note here. If we don't have any fields configured the response will contain basically ANYTHING
        // in the repo. So option 1 is make `iiif_content_search_api_file_uri_fields` required
        // bail out if empty? Or, we can add a short limit... that works too for now
        // April 2024, to enable in the future postprocessor that generate SBF but not from files (e.g WARC)>
        $iiifConfig = $this->config('format_strawberryfield.iiif_settings');
        foreach ($iiifConfig->get('iiif_content_search_api_file_uri_fields') ?? [] as $uri_field) {
          if (isset($allfields_translated_to_solr[$uri_field])) {
            $uri_conditions->addCondition($uri_field, $file_uris, 'IN');
            $fields_to_retrieve[$uri_field]
              = $allfields_translated_to_solr[$uri_field];
          }
          if (count($uri_conditions->getConditions())) {
            $have_file_condition = TRUE;
            $query->addConditionGroup($uri_conditions);
          }
        }
      }
      if (count($file_uuids)) {
        if (isset($allfields_translated_to_solr[$uuid_uri_field])) {
          $uuid_conditions->addCondition($uuid_uri_field, $file_uuids, 'IN');
        }
        if (count($uuid_conditions->getConditions())) {
          $have_file_condition = TRUE;
          $query->addConditionGroup($uuid_conditions);
        }
      }
      if (!$have_file_condition) {
        // in case no files are passed to filter, simply limit all to less?
        $query->setOption('limit', 10);
      }
      // This might/not/be/respected. (API v/s reality)
      $query->setOption('search_api_retrieved_field_values', array_values($fields_to_retrieve));
      $query->setProcessingLevel(QueryInterface::PROCESSING_FULL);
      $results = $query->execute();
      unset($fields_to_retrieve['id']);
      unset($fields_to_retrieve['parent_sequence_id']);
      if ($results->getResultCount() >= 1) {
        foreach ($results as $result) {
          $real_id = $result->getId();
          $real_sequence = 1;
          $real_id_part = explode(":", $real_id);
          if (isset($real_id_part[1]) && is_scalar($real_id_part[1])) {
            $real_sequence = $real_id_part[1];
            [$real_sequence] = explode("-", $real_sequence);
            $real_sequence = (int) $real_sequence;
          }
          $extradata_from_item = $result->getAllExtraData() ?? [];
          if (isset($extradata_from_item['search_api_solr_document'][$allfields_translated_to_solr['fulltext']])) {
            $annotations = array_merge($annotations, $this->miniOCRtoAnnon($extradata_from_item['search_api_solr_document'][$allfields_translated_to_solr['fulltext']][0], $real_id_part[3] , $real_sequence));
          }
        }
      }
    }
    return $annotations;
  }

  protected function miniOCRtoAnnon(string $miniocr, $file_uuid, $sequence_id):array {
    // To avoid memory crazy ness should we set a limit here?
    // As today, archipelago generates OCR per page, so should not be too large.
    $internalErrors = libxml_use_internal_errors(TRUE);
    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);
    $annotations = [];
    $miniocr_xml = simplexml_load_string($miniocr);
    if (!$miniocr_xml) {
      return [];
    }
    $i = 0;
    foreach ($miniocr_xml->children() as $p) {
      foreach ($p->children() as $b) {
        foreach ($b->children() as $l) {
          foreach ($l->children() as $word) {
            $text = (string)$word;
            if (strlen(trim($text)) > 0) {
              $i++;
              $wcoos = explode(" ", $word['x']);
              $left = (float)$wcoos[0] * 100;
              $top = (float)$wcoos[1] * 100;
              $width = (float)$wcoos[2] * 100;
              $height = (float)$wcoos[3] * 100;
              $text = (string)$word;
              $annotations[] = [
                "@context" => "http://www.w3.org/ns/anno.jsonld",
                "id" => $file_uuid . '_' . $sequence_id .'_' .$i,
                "type" => "Annotation",
                "body" => [
                  "type" => "TextualBody",
                  "value" => $text
                ],
                "target" => [
                  "selector" => [
                    "type" => "FragmentSelector",
                    "conformsTo" => "http://www.w3.org/TR/media-frags/",
                    "value" => "xywh=percent:{$left},{$top},{$width},{$height}"
                  ]
                ]
              ];
            }
          }
        }
      }
    }
    // If not miniOCR then bail. @TODO. In the future generate also for AltoXML
    return $annotations;
  }
}
