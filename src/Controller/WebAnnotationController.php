<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

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
   * @var \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * MetadataExposeDisplayController constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   *   The SBF Utility Service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entitytype_manager
   *   The Entity Type Manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The Drupal Renderer Service.
   * @param \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface $mime_type_guesser
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
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
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
        $annotations = $this->requestStack->getCurrentRequest()->request->get('data');
        $target = $this->requestStack->getCurrentRequest()->request->get('target_resource');
        $keystoreid = $this->requestStack->getCurrentRequest()->request->get('keystoreid');
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
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   */
  public function putTemp(Request $request,
    ContentEntityInterface $node
  ) {
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

      // We are getting which field originate the annotations from AJAX.
      $annotation = $this->requestStack->getCurrentRequest()->request->get('data');
      $target = $this->requestStack->getCurrentRequest()->request->get('target_resource');
      $keystoreid = $this->requestStack->getCurrentRequest()->request->get('keystoreid');
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
                error_log('found!');
                error_log(var_export($existingannotation,true));
                error_log('to be replace with!');
                error_log(var_export($annotation,true));
                $existingannotations[$target][$key] = $annotation;
                $persisted = TRUE;
                break;
              }
            }
            if ($persisted == FALSE) {
              throw new MethodNotAllowedHttpException(
                "The Annotation has no unique id!"
              );
            } // means it was new
          }
          error_log('putting into '.$keystoreid);
          error_log('for into '.$target);
          error_log(var_export($existingannotations,true));
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
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   */
  public function postTemp(Request $request,
    ContentEntityInterface $node
  ) {
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

        // We are getting which field originate the annotations from AJAX.
        $annotation = $this->requestStack->getCurrentRequest()->request->get('data');
        $target = $this->requestStack->getCurrentRequest()->request->get('target_resource');
        $keystoreid = $this->requestStack->getCurrentRequest()->request->get('keystoreid');

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
                throw new MethodNotAllowedHttpException(
                  "The ID is already present, to update use PUT method"
                );
              }
            }
          }
          error_log('adding new into'.$keystoreid);
          error_log('for into '.$target);
          error_log(var_export($annotation, true));
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
   * Persist temp Controller Method (POST).
   *
   * @param \Symfony\Component\HttpFoundation\Request
   *   The Full HTTPD Resquest
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   */
  public function deleteTemp(Request $request,
    ContentEntityInterface $node
  ) {
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

      // We are getting which field originate the annotations from AJAX.

      $annotation = $this->requestStack->getCurrentRequest()->request->get('data');
      $target = $this->requestStack->getCurrentRequest()->request->get('target_resource');
      $keystoreid = $this->requestStack->getCurrentRequest()->request->get('keystoreid');

      $data = [
        'success' => true
      ];

      try {
        if (isset($annotation['id'])) {
          $existingannotations = $this->tempStore->get($keystoreid);
          $existingannotations = is_array($existingannotations) ? $existingannotations : [];
          if (isset($existingannotations[$target])) {
            foreach ($existingannotations[$target] as $key => &$existingannotation) {
              if (($existingannotation['id'] == $annotation['id'])) {
                unset($existingannotation[$key]);
                break;
              }
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

    return new JsonResponse($return);
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
}
