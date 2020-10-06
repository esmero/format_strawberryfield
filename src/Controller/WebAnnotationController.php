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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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
   * Main Controller Method.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument.
   * @param \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity $metadataexposeconfig_entity
   *   The Metadata Exposed Config Entity that carries the settings.
   * @param string $format
   *   A possible Filename used in the last part of the Route.
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
        error_log($target);
        error_log(var_export($annotations, true));
        $data = [
          'success' => true
        ];

        try {
          $existingannotations = $this->tempStore->get($node->uuid());
          $existingannotations = is_array($existingannotations) ? $existingannotations : [];
          $existingannotations[$target] = $annotations;
          $this->tempStore->set($node->uuid(), $existingannotations);
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
   * Persist temp Controller Method.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   *   A Node as argument.
   * @param \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity $metadataexposeconfig_entity
   *   The Metadata Exposed Config Entity that carries the settings.
   * @param string $format
   *   A possible Filename used in the last part of the Route.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   */
  public function persistTemp(Request $request,
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
        error_log($target);
        error_log(var_export($annotations, true));
        $data = [
          'success' => true
        ];

        try {
          $existingannotations = $this->tempStore->get($node->uuid());
          $existingannotations = is_array($existingannotations) ? $existingannotations : [];
          $existingannotations[$target] = $annotations;
          $this->tempStore->set($node->uuid(), $existingannotations);
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
}
