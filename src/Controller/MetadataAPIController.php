<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\UseCacheBackendTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Render\RenderContext;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\format_strawberryfield\EmbargoResolverInterface;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\PathItem;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use Drupal\views\Views;
use Drupal\views\ViewExecutable;

/**
 * A Wrapper Controller to access Twig processed JSON on a URL.
 */
class MetadataAPIController extends ControllerBase {

  use UseCacheBackendTrait;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

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
   * @var \Drupal\format_strawberryfield\EmbargoResolverInterface
   */
  protected $embargoResolver;


  /**
   * MetadataAPIcontroller constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack                           $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService                    $strawberryfield_utility_service
   *   The SBF Utility Service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface                           $entitytype_manager
   *   The Entity Type Manager.
   * @param \Drupal\Core\Render\RendererInterface                                    $renderer
   *   The Drupal Renderer Service.
   * @param \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface $mime_type_guesser
   *   The Drupal Mime type guesser Service.
   * @param \Drupal\format_strawberryfield\EmbargoResolverInterface                  $embargo_resolver
   * @param \Drupal\Component\Datetime\TimeInterface                                 $time
   * @param \Drupal\Core\Cache\CacheBackendInterface                                 $cache_backend
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager,
    RendererInterface $renderer,
    MimeTypeGuesserInterface $mime_type_guesser,
    EmbargoResolverInterface $embargo_resolver,
    TimeInterface $time,
    CacheBackendInterface $cache_backend
  ) {
    $this->requestStack = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->renderer = $renderer;
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->embargoResolver = $embargo_resolver;
    $this->time = $time;
    $this->cacheBackend = $cache_backend;
    $this->useCaches = TRUE;
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
      $container->get('strawberryfield.mime_type.guesser.mime'),
      $container->get('format_strawberryfield.embargo_resolver'),
      $container->get('datetime.time'),
      $container->get('cache.default')
    );
  }

  /**
   * Main Controller Method. Casts JSON via Twig.
   *
   * @param \Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity $metadataapiconfig_entity
   *   The Metadata Exposed Config Entity that carries the settings.
   * @param string                                                        $format
   *   A possible Filename used in the last part of the Route.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   */
  public function castViaView(
    MetadataAPIConfigEntity $metadataapiconfig_entity,
    $pathargument = 'some_parameter_argument'
  ) {
    // Check if Config entity is actually enablewd.
    if (!$metadataapiconfig_entity->isActive()) {
      throw new AccessDeniedHttpException(
        "Sorry, this API service is currently disabled."
      );
    }

    $openAPI = new OpenApi(
      [
        'openapi' => '3.0.2',
        'info'    => [
          'title'   => 'Test API',
          'version' => '1.0.0',
        ],
        'paths'   => [],
      ]
    );
    // manipulate description as needed

    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      $full_path = $request->getRequestUri();
    }
    // Now make the passed argument in the path one of the parameters if
    // any is Path (first only for now)
    $path = dirname($full_path, 1);
    $pathargument = '';
    $schema_parameters = [];
    $parameters = $metadataapiconfig_entity->getConfiguration()['openAPI'];
    foreach ($parameters as $param) {
      if ($param['param']['in'] ?? NULL === 'path') {
        $pathargument = '{' . $param['param']['name'] . '}';
      }
      $schema_parameters[] = $param['param'];
    }
    $path = $path . '/' . $pathargument;
    $PathItem = new PathItem(['get' => ['parameters' => $schema_parameters]]);

    $openAPI->paths->addPath($path, $PathItem);


    $openAPI->paths->getPath($path);
    $json = \cebe\openapi\Writer::writeToJson($openAPI);

    $validator = (new ValidatorBuilder)->fromSchema($openAPI)
      ->getRequestValidator();
    //@TODO inject as $psrHttpFactory
    $psrRequest = \Drupal::service('psr7.http_message_factory')->createRequest(
      $request
    );
    // Will hold all arguments and will be passsed to the twig templates.
    $context_parameters = [];
    try {
      $match = $validator->validate($psrRequest);
      if ($match) {
        $context_parameters['path']
          = $clean_path_parameter_with_values = $match->parseParams($full_path);
        $context_parameters['post'] = $request->request->all();
        $context_parameters['query'] = $request->query->all();
        $context_parameters['header'] = $request->headers->all();
        $context_parameters['cookie'] = $request->cookies->all();
      }
    } catch (\Exception $exception) {
      // @see https://github.com/thephpleague/openapi-psr7-validator#exceptions
      // To be seen if we do something different for SWORD here.
      throw new BadRequestHttpException(
        $exception->getMessage()
      );
    }

    $context = [];
    $embargo_context = [];
    $embargo_tags = [];
    // Now time to run the VIEWS. Should double check here or trust our stored entity?
    [$view_id, $display_id] = explode(
      ':', $metadataapiconfig_entity->getViewsSourceId() ?? []
    );

    // Now get the wrapper Twig template
    if (($metadatadisplay_wrapper_entity
        = $metadataapiconfig_entity->getWrapperMetadataDisplayEntity(0))
      && ($metadatadisplay_item_entity
        = $metadataapiconfig_entity->getItemMetadataDisplayEntity(0))
    ) {
      try {
        $responsetypefield = $metadatadisplay_wrapper_entity->get('mimetype');
        $responsetypefield_item = $metadatadisplay_item_entity->get('mimetype');
        $responsetype = $responsetypefield->first()->getValue();
        $responsetype_item = $responsetypefield_item->first()->getValue();
        if ($responsetype_item !== $responsetype) {
          throw new \Exception(
            'Output Format differs between Wrapper and Item level templates. They need to match.'
          );
        }
        $responsetype = reset($responsetype);
        // We can have a LogicException or a Data One, both extend different
        // classes, so better catch any.
      } catch (\Exception $exception) {
        $this->loggerFactory->get('format_strawberryfield')->error(
          'Metadata API using @metadatadisplay and/or @metadatadisplay_item have issues. Error message is @e',
          [
            '@metadatadisplay'      => $metadatadisplay_wrapper_entity->label(),
            '@metadatadisplay_item' => $metadatadisplay_item_entity->label(),
            '@e'                    => $exception->getMessage(),
          ]
        );
        throw new BadRequestHttpException(
          "Sorry, this Metadata API has configuration issues."
        );
      }


      // $view = $this->entityTypeManager->getStorage('view')->load($view_id);
      //$display = $view->getDisplay($view_id);
      //$executable = $view->getExecutable();

      /*
       *
       *   if ($view->current_display !== 'MY_VIEW_DISPLAY') {
      return;
    }
    $exposedFilterValues = $view->getExposedInput();
    if (!array_key_exists('MY_FIELD', $exposedFilterValues)) {
      $personalizedDefaultValue = $someUserEntity->getMyCustomDefaultFilterValue();
      $view->setExposedInput(array_merge($exposedFilterValues, ['MY_FIELD' => $personalizedDefaultValue] );
      $view->element['#cache']['tags'] = Cache::mergeTags($view->element['#cache']['tags'] ?? [], $someUserEntity->getCacheTags());

       $view->setExposedInput([
        'sort_by' => 'moderation_state',
        'sort_order' => $order,
      ]);

      For multiple args
       $term = Term::load($tid);
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
      ->loadTree($term->getVocabularyId(), $tid);

    foreach($terms as $child_term) {
      $args[0]  .= ',' . $child_term->tid;
    }

    }
       */
      /** @var \Drupal\views\ViewExecutable $executable */
      $view = $this->entityTypeManager->getStorage('view')->load($view_id);
      $display = $view->getDisplay($display_id);
      $executable = $view->getExecutable();
      if ($view) {
        /** @var \Drupal\views\ViewExecutable $executable */

        $executable->setDisplay($display_id);
        //$view->setArguments([$activeContestId]);
        // \Drupal\views\ViewExecutable::addCacheContext
        // \Drupal\views\ViewExecutable::setCurrentPage
        //
        // Contextual arguments are order dependant
        // But our mappings are not
        // So instead of just passing them
        // We will bring them first into the right order.
        // We will do this a bit more expensive
        // @TODO make this an entity method
        foreach ($executable->display_handler->options['filters'] as $filter) {
          if ($filter['exposed'] == TRUE) {
          }
        }
        $arguments = [];
        //@ TODO maybe allow to cast into ANY entity? well...
        foreach (
          $executable->display_handler->options['arguments'] as $argument_key =>
          $filter
        ) {
          // The order here matters
          foreach ($parameters as $param_name => $paramconfig_setting) {
            foreach (
              $paramconfig_setting['mapping'] ?? [] as $map_id => $mapped
            ) {
              if ($argument_key == $map_id && $mapped) {
                $arguments[$argument_key]
                  = $context_parameters[$paramconfig_setting['param']['in']][$param_name]
                  ?? NULL;
                // @TODO Ok kids, drupal is full of bugs
                // IT WILL TRY TO RENDER A FORM EVEN IF NOT NEEDED FOR REST! DAMN.
                // @see \Drupal\views\ViewExecutable::build it checks for if ($this->display_handler->usesExposed())
                // When it should check for $this->display_handler->displaysExposed() which returns false.
                // So if usesExposed == TRUE we will have to set the param to something if required by the route
                // SOLUTION: Check for the path.
                // check how many % we find. If for each one we find we will have to give
                // THIS stuff a 0 (YES a cero)
                // @TODO 2: Another option would be to enforce any argument that is mapped as required.

                // means its time to transform our mapped argument to a NODE.
                // If not sure, we will let this pass let the View deal with the exception.
                if ($filter['default_argument_type'] == 'node'
                  || (isset($filter['validate']['type'])
                    && $filter['validate']['type'] == "entity:node")
                ) {
                  // @TODO explore this more. Not sure if right?
                  $multiple = $filter['validate_options']['multiple'] ?? FALSE;
                  $multiple = $multiple && $filter['break_phrase'] ?? FALSE;
                  // Try to load it?
                  // This is validated yet, but validation depends on the user's definition
                  // Users might go rogue.
                  // We can not load an empty
                  if (!empty($arguments[$argument_key])
                    && isset($paramconfig_setting['param']['schema']['format'])
                    && $paramconfig_setting['param']['schema']['format']
                    == 'uuid'
                    && \Drupal\Component\Uuid\Uuid::isValid(
                      $arguments[$argument_key]
                    )
                  ) {
                    $nodes = $this->entityTypeManager->getStorage('node')
                      ->loadByProperties(
                        ['uuid' => $arguments[$argument_key]]
                      );
                    if (!empty($nodes)) {
                      $node = reset($nodes);
                      $arguments[$argument_key] = $node->id();
                    }
                  }
                  elseif (is_scalar($arguments[$argument_key])) {
                    $this->entityTypeManager->getStorage('node')
                      ->load($arguments[$argument_key]);
                  }
                }
              }
            }
          }
          $arguments[$argument_key] = $arguments[$argument_key] ?? NULL;
        }

        // We need to destroy the path here ...
        if ($executable->hasUrl()) {
          $executable->display_handler->overrideOption('path', '/node');
        }
        $executable->setArguments(array_values($arguments));
        //
        $views_validation = $executable->validate();
        if (empty($views_validation)) {
          try {
            $this->renderer->executeInRenderContext(
              new RenderContext(),
              function () use ($executable) {
                // Damn view renders forms and stuff. GOSH!
                $executable->execute();
              }
            );
          } catch (\InvalidArgumentException $exception) {
            $exception->getMessage();
            throw new BadRequestHttpException(
              "Sorry, this Metadata API has configuration issues."
            );
          }
          $processed_nodes_via_templates = [];

          // ONLY NOW HERE WE DO CACHING AND STUFF ʕっ•ᴥ•ʔっ
          $total = $executable->pager->getTotalItems() != 0
            ? $executable->pager->getTotalItems() : count($executable->result);
          $current_page = $executable->pager->getCurrentPage();
          $num_per_page = $executable->pager->getItemsPerPage();
          $offset = $executable->pager->getOffset();
          /** @var \Drupal\views\Plugin\views\cache\CachePluginBase $cache_plugin */
          $cache_plugin = $executable->display_handler->getPlugin('cache');
          $cache_id = 'format_strawberry:api:' . $metadataapiconfig_entity->id(
            );

          $cache_id_suffix = $this->generateCacheKey(
            $executable, $context_parameters
          );
          $cache_id = $cache_id . $cache_id_suffix;
          $cached = $this->cacheGet($cache_id);
          if ($cached) {
            $processed_nodes_via_templates = $cached->data ?? [];
          }
          else {
            // NOT CACHED, regenerate
            foreach ($executable->result as $resultRow) {
              if ($resultRow instanceof
                \Drupal\search_api\Plugin\views\ResultRow
              ) {
                //@TODO move to its own method\
                $node = $resultRow->_object->getValue() ?? NULL;
                if ($node
                  && $sbf_fields
                    = $this->strawberryfieldUtility->bearsStrawberryfield(
                    $node
                  )
                ) {
                  foreach ($sbf_fields as $field_name) {
                    /* @var $field StrawberryFieldItem[] */
                    $field = $node->get($field_name);
                    foreach ($field as $offset => $fielditem) {
                      $jsondata = json_decode($fielditem->value, TRUE);
                      $json_error = json_last_error();
                      if ($json_error != JSON_ERROR_NONE) {
                        $this->loggerFactory->get('format_strawberryfield')
                          ->error(
                            'We had an issue decoding as JSON your metadata for node @id, field @field while exposing API @api',
                            [
                              '@id'    => $node->id(),
                              '@field' => $field_name,
                              '@api'   => $metadataapiconfig_entity->label(),
                            ]
                          );
                        throw new UnprocessableEntityHttpException(
                          "Sorry, we could not process metadata for this API service"
                        );
                      }
                      // Preorder as:media by sequence
                      $ordersubkey = 'sequence';
                      foreach (StrawberryfieldJsonHelper::AS_FILE_TYPE as $key)
                      {
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
                    // @TODO make embargo its own method.
                    $embargo_info = $this->embargoResolver->embargoInfo(
                      $node->uuid(), $jsondata
                    );
                    // This one is for the Twig template
                    // We do not need the IP here. No use of showing the IP at all?
                    $context_embargo = [
                      'data_embargo' => [
                        'embargoed' => FALSE,
                        'until'     => NULL,
                      ],
                    ];
                    if (is_array($embargo_info)) {
                      $embargoed = $embargo_info[0];
                      $context_embargo['data_embargo']['embargoed']
                        = $embargoed;
                      $embargo_tags[] = 'format_strawberryfield:all_embargo';
                      if ($embargo_info[1]) {
                        $embargo_tags[] = 'format_strawberryfield:embargo:'
                          . $embargo_info[1];
                        $context_embargo['data_embargo']['until']
                          = $embargo_info[1];
                      }
                      if ($embargo_info[2]) {
                        $embargo_context[] = 'ip';
                      }
                    }
                    else {
                      $embargoed = $embargo_info;
                    }

                    $context['node'] = $node;
                    $context['data_api'] = $context_parameters;
                    $context['data_api_context'] = 'item';
                    $context['iiif_server'] = $this->config(
                      'format_strawberryfield.iiif_settings'
                    )->get('pub_server_url');
                    $original_context = $context + $context_embargo;
                    // Allow other modules to provide extra Context!
                    // Call modules that implement the hook, and let them add items.
                    \Drupal::moduleHandler()->alter(
                      'format_strawberryfield_twigcontext', $context
                    );
                    // In case someone decided to wipe the original context?
                    // We bring it back!
                    $context = $context + $original_context;

                    $cacheabledata = [];
                    // @see https://www.drupal.org/node/2638686 to understand
                    // What cacheable, Bubbleable metadata and early rendering means.
                    $cacheabledata = $this->renderer->executeInRenderContext(
                      new RenderContext(),
                      function () use ($context, $metadatadisplay_item_entity) {
                        return $metadatadisplay_item_entity->renderNative(
                          $context
                        );
                      }
                    );
                    if ($cacheabledata) {
                      $processed_nodes_via_templates[$node->id()]
                        = $cacheabledata;
                    }
                  }
                }
                /*$rendered[] = !empty($resultRow->_object->getValue())
                  ? $resultRow->_object->getValue()->id() : NULL;
                $rendered2[] = !empty($resultRow->_item->getFields(TRUE))
                  ? $resultRow->_item->getFields(TRUE) : NULL;
                $rendered2[] = !empty($resultRow->_item->getId())
                  ? $resultRow->_item->getId() : NULL;*/
              }
            }
            // Set the cache
            // EXPIRE?
            $cache_expire = $metadataapiconfig_entity->getConfiguration(
              )['cache']['expire'] ?? 120;
            if ($cache_expire !== Cache::PERMANENT) {
              $cache_expire += (int) $this->time->getRequestTime();
            }
            $tags = [];
            $tags = CacheableMetadata::createFromObject(
              $metadataapiconfig_entity
            )->getCacheTags();
            $tags += CacheableMetadata::createFromObject($view)->getCacheTags();
            $tags += CacheableMetadata::createFromObject(
              $metadatadisplay_wrapper_entity
            )->getCacheTags();
            $tags += CacheableMetadata::createFromObject(
              $metadatadisplay_item_entity
            )->getCacheTags();
            $this->cacheSet(
              $cache_id, $processed_nodes_via_templates, $cache_expire, $tags
            );
          }
          // Now Render the wrapper -- no caching here
          $context_wrapper['iiif_server'] = $this->config(
            'format_strawberryfield.iiif_settings'
          )->get('pub_server_url');
          $context_parameters['request_date'] = [
            '#markup' => date("H:i:s"),
            '#cache'  => [
              'disabled' => TRUE,
            ],
          ];
          $context_wrapper['data_api'] = $context_parameters;
          $context_wrapper['data_api_context'] = 'wrapper';
          $context_wrapper['data'] = $processed_nodes_via_templates;
          $original_context = $context_wrapper;
          // Allow other modules to provide extra Context!
          // Call modules that implement the hook, and let them add items.
          \Drupal::moduleHandler()->alter(
            'format_strawberryfield_twigcontext', $context_wrapper
          );
          // In case someone decided to wipe the original context?
          // We bring it back!
          $context_wrapper = $context_wrapper + $original_context;

          $cacheabledata_response = [];
          // @see https://www.drupal.org/node/2638686 to understand
          // What cacheable, Bubbleable metadata and early rendering means.

          $cacheabledata_response = $this->renderer->executeInRenderContext(
            new RenderContext(),
            function () use ($context_wrapper, $metadatadisplay_wrapper_entity
            ) {
              return $metadatadisplay_wrapper_entity->renderNative(
                $context_wrapper
              );
            }
          );
          // @TODO add option that allows the Admin to ask for a rendered VIEW too
          //$rendered = $executable->preview();
          $executable->destroy();
          if ($metadataapiconfig_entity->getConfiguration()['cache']['enabled']
            ?? FALSE == TRUE
          ) {
            switch ($responsetype) {
              case 'application/json':
              case 'application/ld+json':
                $response = new CacheableJsonResponse(
                  $cacheabledata_response,
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
                  $cacheabledata_response,
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
              $response->headers->set('access-control-allow-origin', '*');
              //$response->addCacheableDependency($node);
              //$response->addCacheableDependency($metadatadisplay_entity);
              $response->addCacheableDependency($metadataapiconfig_entity);
              $response->addCacheableDependency($metadatadisplay_item_entity);
              $response->addCacheableDependency(
                $metadatadisplay_wrapper_entity
              );
              //$metadata_cache_tag = 'node_metadatadisplay:'. $node->id();
              //$response->getCacheableMetadata()->addCacheTags([$metadata_cache_tag]);
              // $response->getCacheableMetadata()->addCacheTags($embargo_tags);
              $response->addCacheableDependency($view);
              $response->getCacheableMetadata()->addCacheContexts(
                ['user.roles']
              );
              $response->getCacheableMetadata()->addCacheTags(
                $view->getCacheTags()
              );
              $response->getCacheableMetadata()->addCacheContexts(
                ['url.path', 'url.query_args']
              );
              $max_age = 60;
              $response->getCacheableMetadata()->setCacheMaxAge($max_age);
              $response->setMaxAge($max_age);
              $date = new \DateTime(
                '@' . ($this->time->getRequestTime() + $max_age)
              );
              $response->setExpires($date);
              //$response->getCacheableMetadata()->addCacheContexts($embargo_context);
            }
          }
          // MEANS no caching
          else {
            switch ($responsetype) {
              case 'application/json':
              case 'application/ld+json':
                $response = new JsonResponse(
                  $cacheabledata_response,
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
                $response = new Response(
                  $cacheabledata_response,
                  200,
                  ['content-type' => $responsetype]
                );
                break;

              default:
                throw new BadRequestHttpException(
                  "Sorry, this Metadata endpoint has configuration issues."
                );
            }
          }
          return $response;
        }
        else {
          $this->loggerFactory->get('format_strawberryfield')->error(
            'Metadata API with View Source ID $source_id could not validate the configured View/Display. Check your configuration and arguments <pre>@args</pre>',
            [
              '@source_id' => $metadataapiconfig_entity->getViewsSourceId(),
              '@args'      => json_encode($arguments),
            ]
          );
          throw new BadRequestHttpException(
            "Sorry, this Metadata API has configuration issues."
          );
        }
      }
      else {
        $this->loggerFactory->get('format_strawberryfield')->error(
          'Metadata API with View Source ID $source_id could not load the configured View/Display. Check your configuration',
          [
            '@source_id' => $metadataapiconfig_entity->getViewsSourceId(),
          ]
        );
        throw new BadRequestHttpException(
          "Sorry, this Metadata API has configuration issues."
        );
      }
    }
  }

  /*

       if ($response) {
         // Set CORS. IIIF and others will assume this is true.
         $response->headers->set('access-control-allow-origin','*');
         $response->addCacheableDependency($node);
         $response->addCacheableDependency($metadatadisplay_entity);
         $response->addCacheableDependency($metadataapiconfig_entity);
         $metadata_cache_tag = 'node_metadatadisplay:'. $node->id();
         $response->getCacheableMetadata()->addCacheTags([$metadata_cache_tag]);
         $response->getCacheableMetadata()->addCacheTags($embargo_tags);
         $response->getCacheableMetadata()->addCacheContexts(['user.roles']);
         $response->getCacheableMetadata()->addCacheContexts($embargo_context);
       }
       return $response;

     }
   else {
     throw new UnprocessableEntityHttpException(
       "Sorry, this Content has no Metadata."
     );
   }
  }*/

  /**
   * @param \Drupal\views\ViewExecutable $view_executable
   *
   * @param array                        $api_arguments
   *
   *
   * @return mixed
   */
  public function generateCacheKey(ViewExecutable $view_executable,
    array $api_arguments
  ) {

    $build_info = $view_executable->build_info;
    $key_data = [
      'build_info' => $build_info,
      'pager'      => [
        'page'           => $view_executable->getCurrentPage(),
        'items_per_page' => $view_executable->getItemsPerPage(),
        'offset'         => $view_executable->getOffset(),
      ],
      'api'        => $api_arguments,
    ];

    $display_handler_cache_contexts = $view_executable->display_handler
      ->getCacheMetadata()
      ->getCacheContexts();
    // This will convert the Contexts and Cache metadata into values that include e.g the [url]=http://thisapi
    $key_data += \Drupal::service('cache_contexts_manager')
      ->convertTokensToKeys($display_handler_cache_contexts)
      ->getKeys();

    $cacheKey = ':rendered:' . Crypt::hashBase64(serialize($key_data));

    return $cacheKey;
  }


  /**
   * Retrieves the cache contexts manager.
   *
   * @return \Drupal\Core\Cache\Context\CacheContextsManager
   *   The cache contexts manager.
   */
  public function getCacheContextsManager() {
    return \Drupal::service('cache_contexts_manager');
  }

}
