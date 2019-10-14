<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Zend\Diactoros\Response\XmlResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;
use Drupal\Core\Template\TwigEnvironment;

/**
 * A Wrapper Controller to access Twig processed JSON on a URL
 */
class MetadataExposeDisplayController extends ControllerBase {

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
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twigEnvironment;

  /**
   * MetadataExposeDisplayController constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   *  The SBF Utility Service
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entitytype_manager
   *  Drupal's Entity Type manager service
   * @param \Drupal\Core\Template\TwigEnvironment $twigEnvironment
   *  Drupal's Twig Environment
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager,
    TwigEnvironment $twigEnvironment
  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->twigEnvironment = $twigEnvironment;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('strawberryfield.utility'),
      $container->get('entity_type.manager'),
      $container->get('twig')

    );
  }


  /**
   * Serves the JSON via a Twig transform.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity $metadatadisplay_entity
   * @param string $format
   *
   * @return \Symfony\Component\HttpFoundation\Response
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function castViaTwig(
    ContentEntityInterface $node,
    MetadataExposeConfigEntity $metadataexposeconfig_entity,
    string $format = 'default.json'
  ) {
    $valid_bundles = (array) $metadataexposeconfig_entity->getTargetEntityTypes(
    );
    if (!in_array($node->bundle(), $valid_bundles)) {
      throw new BadRequestHttpException(
        "Sorry, this metadata service is not enabled for this Content Type"
      );
    }
    $metadatadisplay_entity = $metadataexposeconfig_entity->getMetadataDisplayEntity(
    );
    try {
      //@TODO future work. https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Render%21MainContent%21HtmlRenderer.php/class/HtmlRenderer/8.8.x
      // Maybe we could make a lot more by creating our own rendered + exploiding
      // Cache system better.
      $twigtemplate = $metadatadisplay_entity->get('twig')->getValue();
      $twigtemplate = !empty($twigtemplate) ? $twigtemplate[0]['value'] : "{{ field.label }}";

      $context = [];

      if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
        $node
      )) {

        foreach ($sbf_fields as $field_name) {
          /* @var $field StrawberryFieldItem[] */
          $field = $node->get($field_name);

          foreach ($field as $offset => $fielditem) {
            $data = json_decode($fielditem->value, TRUE);
            $json_error = json_last_error();
            if ($json_error != JSON_ERROR_NONE) {
              $this->loggerFactory->get('format_strawberryfield')->error(
                'We had an issue decoding as JSON your metadata for node @id, field @field while exposing a Metadata endpoint using @metadatadisplay',
                [
                  '@id' => $node->id(),
                  '@field' => $field_name,
                  '@metadatadisplay' => $metadatadisplay_entity->label(),
                ]
              );
              throw new UnprocessableEntityHttpException(
                "Sorry, we could not process metadata for this service"
              );
            }
            if ($offset == 0) {
              $context['data'] = $data;
            }
            else {
              $context['data_' . $offset] = $data;
            }
          }

        }
        $context['node'] = $node;

        $context['iiif_server'] = $this->config(
          'format_strawberryfield.iiif_settings'
        )->get('pub_server_url');

        $rendered = $this->twigEnvironment->renderInline(
          $twigtemplate,
          $context
        );
        //@TODO. Still wrong. If output is HTML/XML/etc we need to return different responses
        //@TODO the response body needs to be validated against the default format
        //@TODO

        $response = new JsonResponse(json_decode($rendered));

        return $response;

      }
      throw new UnprocessableEntityHttpException(
        "Sorry, this Content has no Metadata."
      );
    } catch (\Exception $exception) {
      //@TODO there are a lot of throwables here
      // Deal with each exception correctly and log them
      throw new UnprocessableEntityHttpException(
        "Sorry, this endpoint has configuration issues."
      );
    }
  }
}