<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\format_strawberryfield\MetadataDisplayInterface;

/**
 * A IIIF Wrapper Controller for non public files.
 */
class MetadataDisplayController extends ControllerBase {

  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;


  /**
   * The Strawberry Field Utility Service
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * IiifAVController constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request object.
   *
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   *   The SBF utility Service
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager
  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('strawberryfield.utility'),
      $container->get('entity_type.manager')
    );
  }


  /**
   * Serves the AV File.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param string $uuid
   * @param string $format
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function castViaTwig(
    ContentEntityInterface $node,
    MetadataDisplayInterface $metadatadisplay_entity,
    string $format = 'default.json'
  ) {
    //@TODO check if format passed matches file type. If not abort.
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

      foreach ($sbf_fields as $field_name) {
        /* @var $field StrawberryFieldItem */
        $field = $node->get($field_name);
        $found = NULL;

      }
      throw new NotFoundHttpException(
        "Sorry, we could not cast this metadata format"
      );
    }
  }
}