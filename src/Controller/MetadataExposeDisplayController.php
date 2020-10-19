<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Render\RenderContext;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;

/**
 * A Wrapper Controller to access Twig processed JSON on a URL.
 */
class MetadataExposeDisplayController extends ControllerBase {

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
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager,
    RendererInterface $renderer,
    MimeTypeGuesserInterface $mime_type_guesser
  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->renderer = $renderer;
    $this->mimeTypeGuesser = $mime_type_guesser;

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
      $container->get('file.mime_type.guesser')
    );
  }

  /**
   * Main Controller Method. Casts JSON via Twig.
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
  public function castViaTwig(
    ContentEntityInterface $node,
    MetadataExposeConfigEntity $metadataexposeconfig_entity,
    $format = 'default.json'
  ) {
    // Check if Config entity is actually enablewd.
    if (!$metadataexposeconfig_entity->isActive()) {
      throw new AccessDeniedHttpException(
        "Sorry, this metadata service is currently disabled."
      );
    }

    $valid_bundles = (array) $metadataexposeconfig_entity->getTargetEntityTypes(
    );
    if (!in_array($node->bundle(), $valid_bundles)) {
      throw new BadRequestHttpException(
        "Sorry, this metadata service is not enabled for this Content Type"
      );
    }
    $context = [];
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {
      if ($metadatadisplay_entity = $metadataexposeconfig_entity->getMetadataDisplayEntity(
      )) {

        try {
          $responsetypefield = $metadatadisplay_entity->get('mimetype');
          $responsetype = $responsetypefield->first()->getValue();
          // We can have a LogicException or a Data One, both extend different
          // classes, so better catch any.
        }
        catch (\Exception $exception) {
          $this->loggerFactory->get('format_strawberryfield')->error(
            'Metadata endpoint using @metadatadisplay has no mimetype Drupal field setup or no value. Please check that your entity has that field and there is a default Output Format value for it. Error message is @e',
            [
              '@metadatadisplay' => $metadatadisplay_entity->label(),
              '@e' => $exception->getMessage(),
            ]
          );
          throw new BadRequestHttpException(
            "Sorry, this Metadata endpoint has configuration issues."
          );
        }

        // @TODO: ask around. Is HTML the most sensible default?
        $responsetype = !empty($responsetype['value']) ? $responsetype['value'] : 'text/html';

        // Gues mimetype using $format.
        $mimetype = $this->mimeTypeGuesser->guess($format);
        if ($mimetype != $responsetype) {
          $badresponse = new JsonResponse(['error' => 'Wrong Media type for this endpoint'], 415);
          return $badresponse;
        }

        foreach ($sbf_fields as $field_name) {
          /* @var $field StrawberryFieldItem[] */
          $field = $node->get($field_name);
          foreach ($field as $offset => $fielditem) {
            $jsondata = json_decode($fielditem->value, TRUE);
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
            // Preorder as:media by sequence
            $ordersubkey = 'sequence';
            foreach (StrawberryfieldJsonHelper::AS_FILE_TYPE as $key) {
              StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
            }

            if ($offset == 0) {
              $context['data'] = $jsondata;
            }
            else {
              $context['data'][$offset] = $jsondata;
            }
          }


          $context['node'] = $node;
          $context['iiif_server'] = $this->config(
            'format_strawberryfield.iiif_settings'
          )->get('pub_server_url');
          $original_context = $context;
          // Allow other modules to provide extra Context!
          // Call modules that implement the hook, and let them add items.
          \Drupal::moduleHandler()->alter('format_strawberryfield_twigcontext', $context);
          // In case someone decided to wipe the original context?
          // We bring it back!
          $context = $context + $original_context;


          $cacheabledata = [];
          // @see https://www.drupal.org/node/2638686 to understand
          // What cacheable, Bubbleable metadata and early rendering means.
          $cacheabledata = $this->renderer->executeInRenderContext(
            new RenderContext(),
            function () use ($context, $metadatadisplay_entity) {
              return $metadatadisplay_entity->renderNative($context);
            }
          );
        }
        switch ($responsetype) {
          case 'application/json':
          case 'application/ld+json':
            $response = new CacheableJsonResponse(
              $cacheabledata,
              200,
              ['content-type' => $responsetype],
              TRUE
            );
            break;

          case 'application/xml':
          case 'text/text':
          case 'text/turtle':
          case 'text/html':
          case 'text/csv':
            $response = new CacheableResponse(
              $cacheabledata,
              200,
              ['content-type' => $responsetype]
            );
            break;

          default:
            throw new BadRequestHttpException(
              "Sorry, this Metadata endpoint has configuration issues."
            );
        }

        if ($response) {
          // Set CORS. IIIF and others will assume this is true.
          $response->headers->set('access-control-allow-origin','*');
          $response->addCacheableDependency($node);
          $response->addCacheableDependency($metadatadisplay_entity);
          $response->addCacheableDependency($metadataexposeconfig_entity);
        }
        return $response;

      }
      else {
        throw new NotFoundHttpException(
          'Referenced Metadata Display Entity is missing'
        );
      }
    }
    else {
      throw new UnprocessableEntityHttpException(
        "Sorry, this Content has no Metadata."

      );
    }
  }

}
