<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\Request;
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
use Symfony\Component\Mime\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\format_strawberryfield\EmbargoResolverInterface;

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
   * @var \Symfony\Component\Mime\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * @var \Drupal\format_strawberryfield\EmbargoResolverInterface
   */
  protected $embargoResolver;

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
   * @param \Symfony\Component\Mime\MimeTypeGuesserInterface $mime_type_guesser
   *   The Drupal Mime type guesser Service.
   * @param \Drupal\format_strawberryfield\EmbargoResolverInterface $embargo_resolver
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager,
    RendererInterface $renderer,
    MimeTypeGuesserInterface $mime_type_guesser,
    EmbargoResolverInterface $embargo_resolver

  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->renderer = $renderer;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->embargoResolver = $embargo_resolver;

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
      $container->get('format_strawberryfield.embargo_resolver')
    );
  }

  /**
   * Main Controller Method. Casts JSON via Twig.
   *
   * @param \Symfony\Component\HttpFoundation\Request                        $request
   * @param \Drupal\Core\Entity\ContentEntityInterface                       $node
   *   A Node as argument.
   * @param \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity $metadataexposeconfig_entity
   *   The Metadata Exposed Config Entity that carries the settings.
   * @param string                                                           $format
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
    // Check if Config entity is actually enabled.
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
    $embargo_context = [];
    $embargo_tags = [];
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
        $mimetype = $this->mimeTypeGuesser->guessMimeType($format);
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
              StrawberryfieldJsonHelper::orderSequence(
                $jsondata, $key, $ordersubkey
              );
            }

            if ($offset == 0) {
              $context['data'] = $jsondata;
            }
            else {
              $context['data'][$offset] = $jsondata;
            }
          }

          $embargo_info = $this->embargoResolver->embargoInfo(
            $node->uuid(), $jsondata
          );
          // This one is for the Twig template
          // We do not need the IP here. No use of showing the IP at all?
          $context_embargo = [
            'data_embargo' => [
              'embargoed' => FALSE,
              'until' => NULL
            ]
          ];
          $embargoed = FALSE;

          if (is_array($embargo_info)) {
            $embargoed = $embargo_info[0];
            $context_embargo['data_embargo']['embargoed'] = $embargoed;
            $embargo_tags[] = 'format_strawberryfield:all_embargo';
            if ($embargo_info[1]) {
              $embargo_tags[] = 'format_strawberryfield:embargo:'
                . $embargo_info[1];
              $context_embargo['data_embargo']['until'] = $embargo_info[1];
            }
            if ($embargo_info[2]) {
              $embargo_context[] = 'ip';
            }
          }

          if ($metadataexposeconfig_entity->getHideOnEmbargo() && $embargoed) {
            // If embargoed and hide on embargo TRUE,
            // we ignore  content negotiation
            // set a JSON response and cache this one.
            $cacheabledata = [
              'error' => [
                'errors' => [
                  'message' => 'Authentication Required'
                  ]
                ],
              'code' => 401,
              ];
            $cacheabledata = json_encode($cacheabledata);
            // Force Response type to JSON.
            $responsetype = 'application/json';
            $status = 401;
          }
          else {
            // Only process if getHideOnEmbargo returns FALSE, embargoed or not.
            $status = 200;
            $context['node'] = $node;
            $context['iiif_server'] = $this->config(
              'format_strawberryfield.iiif_settings'
            )->get('pub_server_url');
            $original_context = $context + $context_embargo;
            $cacheabledata = [];
            // @see https://www.drupal.org/node/2638686 to understand
            // What cacheable, Bubbleable metadata and early rendering means.
            $cacheabledata = $this->renderer->executeInRenderContext(
              new RenderContext(),
              function () use ($context, $original_context, $metadatadisplay_entity) {
                // Allow other modules to provide extra Context!
                // Call modules that implement the hook, and let them add items.
                \Drupal::moduleHandler()->alter(
                  'format_strawberryfield_twigcontext', $context
                );
                // In case someone decided to wipe the original context?
                // We bring it back!
                $context = $context + $original_context;
                return $metadatadisplay_entity->renderNative($context);
              }
            );
          }
        }
        switch ($responsetype) {
          case 'application/json':
          case 'application/ld+json':
            $response = new CacheableJsonResponse(
              $cacheabledata,
              $status,
              ['content-type' => $responsetype],
              TRUE
            );
            break;

          case 'application/xml':
          case 'text/plain':
          case 'text/turtle':
          case 'text/html':
          case 'text/csv':
            $response = new CacheableResponse(
              $cacheabledata,
              $status,
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
          $response->headers->set('Access-Control-Allow-Origin','*');
          $response->addCacheableDependency($node);
          $response->addCacheableDependency($metadatadisplay_entity);
          $response->addCacheableDependency($metadataexposeconfig_entity);
          $metadata_cache_tag = 'node_metadatadisplay:'. $node->id();
          $response->getCacheableMetadata()->addCacheTags([$metadata_cache_tag]);
          $response->getCacheableMetadata()->addCacheTags($embargo_tags);
          $response->getCacheableMetadata()->addCacheContexts(['user.roles']);
          $response->getCacheableMetadata()->addCacheContexts($embargo_context);
          if (isset($embargo_info[3]) && $embargo_info[3] === FALSE) {
            $response->getCacheableMetadata()->setCacheMaxAge(0);
          }
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
