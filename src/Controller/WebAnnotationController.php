<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Cache\CacheableJsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
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
    PrivateTempStoreFactory $temp_store_factory

  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->renderer = $renderer;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->tempStore = $temp_store_factory->get('webannotation');

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
      $container->get('tempstore.private')
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
        // It would have set initial values so we do not need to read/iterate everytime
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

}
