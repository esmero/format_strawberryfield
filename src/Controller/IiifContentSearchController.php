<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\format_strawberryfield\Entity\MetadataDisplayEntity;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_solr\Utility\Utility as UtilityAlias;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;


/**
 * A Wrapper Controller to access Twig processed JSON on a URL.
 */
class IiifContentSearchController extends ControllerBase {

  /**
   * A JMESPATH to fetch Canvas Size, Images and their targets IIIF Presentation 2.x
   */
  CONST IIIF_V2_JMESPATH = "sequences[].canvases[?not_null(type, \"@type\") == 'sc:Canvas'].[{width:width,height:height,img_canvas_pairs:images[?motivation == 'sc:painting'][resource.\"@id\", not_null(on)]}][][]";
  /**
   * A JMESPATH to fetch Canvas Size, Images and their targets IIIF Presentation 3.x
   */
  CONST IIIF_V3_JMESPATH = "items[?not_null(type, \"@type\") == 'Canvas'].[{width:width,height:height,img_canvas_pairs:items[?type == 'AnnotationPage'][].items[?motivation == 'painting' && body.type == 'Image'][body.not_null(id, \"@id\"), not_null(target)][]}][]";

  CONST IIIF_V3_JMESPATH_VTT ="items[?not_null(type, \"@type\") == 'Canvas'].[{duration:duration, width:width, height:height, vtt_canvas_annotation_triad:annotations[].items[?motivation=='supplementing' && body.format == 'text/vtt'][body.not_null(id, \"@id\"), not_null(target),not_null(id, \"@id\")][]}][]";

  CONST IIIF_V3_JMESPATH_TEXT ="items[?not_null(type, \"@type\") == 'Canvas'].[{width:width, height:height, text_canvas_annotation_triad:annotations[].items[?motivation=='supplementing' && body.format == 'text/plain'][body.not_null(id, \"@id\"), not_null(target),not_null(id, \"@id\")][]}][]";


  /**
   * Mime type guesser service.
   *
   * @var \Symfony\Component\Mime\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * Class Resolver service.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  protected $classResolver;

  /**
   * The Drupal Renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The route match object.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;


  /**
   * The route provider.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;


  /** The Configured Permanent Storage for Files in Archipelago
   * @var string $destinationScheme
   */
  private $destinationScheme;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * The Global IIIF Settings.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig $iiifConfig
   */
  private $iiifConfig;

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *
   * @return \Drupal\format_strawberryfield\Controller\IiifContentSearchController
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->classResolver = $container->get('class_resolver');
    $instance->mimeTypeGuesser = $container->get('strawberryfield.mime_type.guesser.mime');
    $instance->embargoResolver = $container->get('format_strawberryfield.embargo_resolver');
    $instance->requestStack = $container->get('request_stack');
    $instance->renderer = $container->get('renderer');
    $instance->routeMatch = $container->get('current_route_match');
    $instance->routeProvider = $container->get('router.route_provider');
    $instance->parseModeManager = $container->get('plugin.manager.search_api.parse_mode');
    $instance->destinationScheme = $container->get('config.factory')->get(
      'strawberryfield.storage_settings'
    )->get('file_scheme');
    $instance->iiifConfig = $container->get('config.factory')->get('format_strawberryfield.iiif_settings');
    return $instance;
  }

  /**
   * Wrapper Controller Method. Returns a IIIF Content Search response.
   *
   * @param \Symfony\Component\HttpFoundation\Request                        $request
   * @param string                                                           $mode
   * @param \Drupal\Core\Entity\ContentEntityInterface                       $node
   *   A Node as argument.
   * @param \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity $metadataexposeconfig_entity
   * @param string                                                           $version
   * @param string                                                           $page
   *   The page according to the IIIF Content Search API 1.0/2.0 Specs.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse|JsonResponse
   *   A response. Cacheable when needed.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function searchWithExposedMetadataDisplay(
    Request $request,
    string $mode,
    ContentEntityInterface $node,
    MetadataExposeConfigEntity $metadataexposeconfig_entity,
    string $version,
    $page = '0'
  ) {

    if (!$this->iiifConfig->get('iiif_content_search_api_active')) {
      $data = [
        'error' => [
          'errors' => [
            'message' => 'IIIF Content Search API V1 and V2 are disabled on this Server'
          ]
        ],
        'code' => 503,
      ];
      return new JsonResponse($data, 503);
    }

    $entity = $metadataexposeconfig_entity->getMetadataDisplayEntity();
    $iiif_response = [];
    if ($entity) {
      $per_page = $this->iiifConfig->get('iiif_content_search_api_results_per_page');

      $current_url = $request->getRequestUri();
      $current_url_clean = strtok($current_url, '?');
      $current_url_clean = $request->getSchemeAndHttpHost() . $current_url_clean;
      $current_url_clean_no_page = $request->getSchemeAndHttpHost() . $request->getPathInfo();
      $current_url_clean_no_page = explode("/", $current_url_clean_no_page);
      array_pop($current_url_clean_no_page);
      $current_url_clean_no_page = implode("/", $current_url_clean_no_page);

      $the_query_string = $request->get('q','');
      /// I have to modify the request URL so the Template does not render
      /// with the Search API as <current> or worst, skips caches.
      $cacheabledata = $this->renderer->executeInRenderContext(
        new RenderContext(),
        function () use ($metadataexposeconfig_entity, $node) {
          return $metadataexposeconfig_entity->getUrlForItemFromNodeUUID($node->uuid(), TRUE);
        }
      );

      /* Coool beans, in a good way */
      $canonical_url = $cacheabledata;
      if ($canonical_url) {
        $format = pathinfo($canonical_url, PATHINFO_BASENAME);
        $server_arguments = $request->server->all();
        $server_arguments['REQUEST_URI'] = $canonical_url;

        $original_request = $this->requestStack->pop();
        $subrequest = $original_request->duplicate(
          [], [], NULL, NULL, [],
          $server_arguments
        );
        $exposed_metadata_route = $this->routeProvider->getRouteByName(
          'format_strawberryfield.metadatadisplay_caster'
        );
        $subrequest->attributes->set(
          RouteObjectInterface::ROUTE_NAME,
          'format_strawberryfield.metadatadisplay_caster'
        );
        $subrequest->attributes->set(
          RouteObjectInterface::ROUTE_OBJECT, $exposed_metadata_route
        );
        $subrequest->attributes->set('format', $format);
        $subrequest->attributes->set(
          '_route_params', array_replace(
            $subrequest->attributes->get('_route_params', []),
            ['format' => $format]
          )
        );

        /** @var $raw_inputbag \Symfony\Component\HttpFoundation\InputBag */
        $raw_inputbag = $subrequest->attributes->all()['_raw_variables'];
        $raw_inputbag->add(['format' => $format]);
        $raw_inputbag->remove('page');
        $raw_inputbag->remove('version');
        $raw_inputbag->remove('mode');
        $subrequest->attributes->set('_raw_variables', $raw_inputbag);
        $subrequest->attributes->set('_controller', '\Drupal\format_strawberryfield\Controller\MetadataExposeDisplayController::castViaTwig');


        // This is quite a trick. basically we get the current HTTP KERNEL
        // And invoque a call directly. This has the benefit of using the whole caching mechanic
        // The controller trick was nice. But not as nice as this.
        /* @TODO Inject the http kernel service */
        /** @var \Symfony\Component\HttpKernel\HttpKernelInterface $kernel */
        $kernel = \Drupal::getContainer()->get('http_kernel');
        $response = $kernel->handle($subrequest, HttpKernelInterface::SUB_REQUEST);
        /* This call is right was  never ever being cached. So keeping as a comment.*/
        /* @var $controller \Drupal\format_strawberryfield\Controller\MetadataExposeDisplayController */
        /*
        $controller = $this->classResolver->getInstanceFromDefinition(
          '\Drupal\format_strawberryfield\Controller\MetadataExposeDisplayController'
        );
        $response = $controller->castViaTwig(
          $node, $metadataexposeconfig_entity, $format
        );
         Restore the original request. We need it to return the right response for this search.
        $this->requestStack->pop();
        */

        $this->requestStack->push($original_request);

        if ($response->isSuccessful()) {
          $json_string = $response->getContent() ?? '{}';
          $jsonArray = json_decode($json_string, TRUE);
          if (json_last_error() == JSON_ERROR_NONE) {
            if ($this->iiifConfig->get('iiif_content_search_validate_exposed')) {
              $valid = FALSE;
              foreach($jsonArray['service'] ?? [] as $service) {
                if (isset($service['type']) && in_array($service['type'], ["SearchService2", "SearchService1"])) {
                  if (strtok($service['id'] ?? '', '?') == $current_url_clean_no_page.'/0') {
                    $valid = TRUE;
                  }
                }
                if (isset($service['profile']) && in_array($service['profile'], ["http://iiif.io/api/search/1/search", "https://iiif.io/api/search/1/search"])) {
                  if (strtok($service['@id'] ?? '', '?') == $current_url_clean_no_page.'/0') {
                    $valid = TRUE;
                  }
                }
              }
              if (!$valid) {
                $data = [
                  'error' => [
                    'errors' => [
                      'message' => 'IIIF Content Search API V1 and V2 are disabled on this particular Manifest/Digital Object'
                    ]
                  ],
                  'code' => 503,
                ];
                return new JsonResponse($data, 503);
              }
            }

            $image_hash = [];
            $vtt_hash = [];
            $results = [];
            $results_time = [];
            $results_text = [];

            // Get the Visual X/Y Processors, split, clean;
            $visual_processors =  $this->iiifConfig->get('iiif_content_search_api_visual_enabled_processors') ?? 'ocr';
            //@TODO we could do this also on saving? see \Drupal\format_strawberryfield\Form\IiifSettingsForm::submitForm
            $visual_processors = explode(",", $visual_processors);
            $visual_processors = array_map('trim', $visual_processors);
            $visual_processors = array_filter($visual_processors);

            $time_processors =  $this->iiifConfig->get('iiif_content_search_api_time_enabled_processors') ?? 'subtitle';
            //@TODO we could do this also on saving? see \Drupal\format_strawberryfield\Form\IiifSettingsForm::submitForm
            $time_processors = explode(",", $time_processors);
            $time_processors = array_map('trim', $time_processors);
            $time_processors = array_filter($time_processors);

            $text_processors = $this->iiifConfig->get('iiif_content_search_api_text_enabled_processors') ?? '';
            $text_processors = explode(",", $text_processors);
            $text_processors = array_map('trim', $text_processors);
            $text_processors = array_filter($text_processors);


            if (count($visual_processors)) {
              $jmespath_searchresult = StrawberryfieldJsonHelper::searchJson(
                static::IIIF_V3_JMESPATH, $jsonArray
              );
              $image_hash = $this->cleanImageJmesPathResult($jmespath_searchresult);
              unset($jmespath_searchresult);
              if (count($image_hash)) {
                // If images are too many we can hit maxClause limit of Solr. We will chunk and query multiple times
                foreach (array_chunk($image_hash, 100, true) as $image_hash_chunk) {
                  $results_chunk = $this->flavorfromSolrIndex($the_query_string, $visual_processors, array_keys($image_hash_chunk), [], [], ($page * $per_page), $per_page, TRUE);
                  $results['annotations'] = array_merge(($results['annotations'] ?? []), $results_chunk['annotations'] ?? []);
                  $results['total'] = ($results['total'] ?? 0) +  ($results_chunk['total'] ?? 0);
                }
              }
            }

            $target_annotation = FALSE;
            if (count($time_processors)) {
              $jmespath_searchresult = StrawberryfieldJsonHelper::searchJson(
                static::IIIF_V3_JMESPATH_VTT, $jsonArray
              );
              $target_annotation = $this->iiifConfig->get('iiif_content_search_time_targetannotations') ?? FALSE;
              $vtt_hash = $this->cleanVttJmesPathResult($jmespath_searchresult, $target_annotation);
              unset($jmespath_searchresult);
              // Here we use UUIDs instead
              if (count($vtt_hash)) {
                foreach (array_chunk($vtt_hash, 100, true) as $vtt_hash_chunk) {
                  $results_chunk = $this->flavorfromSolrIndex($the_query_string, $time_processors, [], array_keys($vtt_hash_chunk), [], ($page * $per_page), $per_page, TRUE);
                  $results_time['annotations'] = array_merge(($results_time['annotations'] ?? []), $results_chunk['annotations'] ?? []);
                  $results_time['total'] = ($results_time['total'] ?? 0) +  ($results_chunk['total'] ?? 0);
                }
              }
            }

            if (count($text_processors)) {
              $jmespath_searchresult = StrawberryfieldJsonHelper::searchJson(
                static::IIIF_V3_JMESPATH_TEXT, $jsonArray
              );
              // Mirador does not know how to target a Text Annotation that is Suplemental. So target the Canvas
              $text_hash = $this->cleanTextJmesPathResult($jmespath_searchresult, FALSE);
              unset($jmespath_searchresult);
              if (count($text_hash)) {
                foreach (array_chunk($text_hash, 100, true) as $text_hash_chunk) {
                  $results_chunk = $this->flavorfromSolrIndex($the_query_string, $text_processors, [], array_keys($text_hash_chunk), [], ($page * $per_page), $per_page, FALSE);
                  $results_text['annotations'] = array_merge(($results_text['annotations'] ?? []), $results_chunk['annotations'] ?? []);
                  $results_text['total'] = ($results_text['total'] ?? 0) +  ($results_chunk['total'] ?? 0);
                }
              }
            }

            $entries = [];
            $paging_structure = [];
            $uuid_uri_field = 'file_uuid';
            // Image/Visual based Annotations
            if (count($results['annotations'] ?? [])) {
              $i = 0;
              foreach ($results['annotations'] as $hit => $hits_per_file_and_sequence) {
                foreach (
                ($hits_per_file_and_sequence['boxes'] ?? []) as $annotation
                ) {
                  $i++;
                  // Calculate Canvas and its offset
                  // PDFs Sequence is correctly detected, but on images it should always be "1"
                  // EXCEPT if we are talking about individual Annotations (e.g from ML) all the same on a single Image
                  // For that we will change the response from the main Solr search using our expected ID (splitting)
                  // This gets even more complicated when we have to deal with PDF and ML (future)
                  // What if for ML the ID of SB Flavor is still 1-N Annotations BUT the $sequence_id == 1?
                  $uris = [];
                  foreach ($this->iiifConfig->get('iiif_content_search_api_file_uri_fields') ?? [] as $uri_field) {
                    $uris[] = $hits_per_file_and_sequence['sbf_metadata'][$uri_field] ?? NULL;
                  }
                  $sequence_id = $hits_per_file_and_sequence['sbf_metadata']['sequence_id'] ?? 1;
                  $uris = array_filter($uris);
                  $uri = reset($uris);
                  if ($uri) {
                    $canvas = $image_hash[$uri][$sequence_id] ?? [];
                    foreach ($canvas as $canvas_id => $canvas_data) {
                      if ($canvas_id) {
                        $canvas_parts = explode("#xywh=", $canvas_id);
                        if (count($canvas_parts) == 2) {
                          $canvas_offset = explode(',', $canvas_parts[1]);
                          $canvas_position = [
                            round($annotation['l'] * ($canvas_offset[2] ?? $canvas_data[0]) + $canvas_offset[0]),
                            round($annotation['t'] * ($canvas_offset[3] ?? $canvas_data[1]) + $canvas_offset[1]),
                            round(($annotation['r'] - $annotation['l']) * $canvas_offset[2]),
                            round(($annotation['b'] - $annotation['t']) * $canvas_offset[3]),
                          ];
                        } else {
                          $canvas_position = [
                            round($annotation['l'] * $canvas_data[0]),
                            round($annotation['t'] * $canvas_data[1]),
                            round(($annotation['r'] - $annotation['l']) * $canvas_data[0]),
                            round(($annotation['b'] - $annotation['t']) * $canvas_data[1]),
                          ];
                        }
                        $canvas_position = "#xywh=" . implode(
                            ",", $canvas_position
                          );
                        // V1
                        // Generate the entry
                        if ($version == "v1") {
                          $entries[] = [
                            "@id" => $current_url_clean
                              . "/{$page}/annotation/anno-result/$i",
                            "@type" => "oa:Annotation",
                            "motivation" => "painting",
                            "resource" => [
                              "@type" => "cnt:ContentAsText",
                              "chars" => $annotation['snippet'],
                            ],
                            "on" => ($canvas_parts[0] ?? $canvas_id) . $canvas_position
                          ];
                        } elseif ($version == "v2") {
                          $entries[] = [
                            "id" => $current_url_clean
                              . "/{$page}/annotation/anno-result/$i",
                            "type" => "Annotation",
                            "motivation" => "painting",
                            "body" => [
                              "type" => "TextualBody",
                              "value" => $annotation['snippet'],
                              "format" => "text/plain",
                            ],
                            "target" => $canvas_id . $canvas_position
                          ];
                        }
                      }
                    }
                  }
                }
              }
            }
            // Time based Annotations
            if (count($results_time['annotations'] ?? [])) {
              $i = 0;
              foreach ($results_time['annotations'] as $hit => $hits_per_file_and_sequence) {
                foreach (
                ($hits_per_file_and_sequence['timespans'] ?? []) as $annotation
                ) {
                  $i++;
                  // Calculate Canvas and its offset
                  // PDFs Sequence is correctly detected, but on images it should always be "1"
                  // For that we will change the response from the main Solr search using our expected ID (splitting)
                  // Different from normal OCR. Single UUID per file.
                  $uuid = $hits_per_file_and_sequence['sbf_metadata'][$uuid_uri_field] ?? NULL;
                  $sequence_id = $hits_per_file_and_sequence['sbf_metadata']['sequence_id'] ?? 1;
                  if ($uuid) {
                    $target = $vtt_hash[$uuid][$sequence_id] ?? [];
                    foreach ($target as $target_id => $target_data) {
                      if ($target_id) {
                        $target_time = [
                          round($annotation['s'],2),
                          round($annotation['e'],2)
                        ];
                        $target_fragment = "#t=" . implode(
                            ",", $target_time
                          );
                        // V1
                        // Generate the entry
                        if ($version == "v1") {
                          $entries[] = [
                            "@id" => $current_url_clean
                              . "/{$page}/annotation/anno-result-time/$i",
                            "@type" => "oa:Annotation",
                            "motivation" => $target_annotation ? "supplementing" : "painting",
                            "resource" => [
                              "@type" => "cnt:ContentAsText",
                              "chars" => $annotation['snippet'],
                            ],
                            "on" => ($target_id) . $target_fragment
                          ];
                        } elseif ($version == "v2") {
                          $entries[] = [
                            "id" => $current_url_clean
                              . "/{$page}/annotation/anno-result-time/$i",
                            "type" => "Annotation",
                            "motivation" => $target_annotation ? "supplementing" : "painting",
                            "body" => [
                              "type" => "TextualBody",
                              "value" => $annotation['snippet'],
                              "format" => "text/plain",
                            ],
                            "target" => $target_id  . $target_fragment
                          ];
                        }
                      }
                    }
                  }
                }
              }
            }
            // Plain Text Annotations
            if (count($results_text['annotations'] ?? [])) {
              $i = 0;
              foreach ($results_text['annotations'] as $hits_per_file_and_sequence) {
                $snippet = '';
                // All snippets will share a canvas. So we join them.
                if (is_array($hits_per_file_and_sequence['boxes'])) {
                  foreach ($hits_per_file_and_sequence['boxes'] as $box) {
                    if (!empty($box['snippet'] ?? NULL)) {
                      if (is_array($box['snippet'])) {
                        // This should never ever happen. Just in case.
                        $box['snippet'] = $box['snippet'][0];
                      }
                      $snippet =  $snippet !== '' ? $snippet . '...' . ($box['snippet'] ?? '') : ($box['snippet'] ?? '') ;
                    }
                  }
                }
                else {
                  continue;
                }
                if ($snippet == '') {
                  continue;
                }
                $i++;
                $file_uuid = $hits_per_file_and_sequence['sbf_metadata'][$uuid_uri_field] ?? NULL;
                $sequence_id = $hits_per_file_and_sequence['sbf_metadata']['sequence_id'] ?? 1;
                if ($file_uuid && isset($text_hash[$file_uuid][$sequence_id])) {
                  $target = $text_hash[$file_uuid][$sequence_id] ?? [];
                  foreach ($target as $target_id => $target_data) {
                    if ($target_id) {
                      // V1
                      // Generate the entry
                      if ($version == "v1") {
                        $entries[] = [
                          "@id" => $current_url_clean
                            . "/{$page}/annotation/anno-result-text/$i",
                          "@type" => "oa:Annotation",
                          "motivation" => $target_annotation ? "supplementing" : "painting",
                          "resource" => [
                            "@type" => "cnt:ContentAsHTML",
                            "chars" => $snippet,
                          ],
                          "on" => ($target_id).'#'
                        ];
                      } elseif ($version == "v2") {
                        $entries[] = [
                          "id" => $current_url_clean
                            . "/{$page}/annotation/anno-result-text/$i",
                          "type" => "Annotation",
                          "motivation" => $target_annotation ? "supplementing" : "painting",
                          "body" => [
                            "type" => "TextualBody",
                            "value" => $snippet,
                            "format" => "text/html",
                          ],
                          "target" => $target_id.'#'
                        ];
                      }
                    }
                  }
                }
              }
            }

            if (count($entries) == 0) {
              $total = 0;
            }
            else {
              $total = ($results['total'] ?? 0) + ($results_time['total'] ?? 0) + ($results_text['total'] ?? 0);
            }

            if ($total > $this->iiifConfig->get('iiif_content_search_api_results_per_page')) {
              $max_page = ceil($total/$this->iiifConfig->get('iiif_content_search_api_results_per_page')) - 1;
              if ($version == "v1") {
                $paging_structure = [
                  "within" => [
                    "@type" => "sc:Layer",
                    "total" => $total,
                    "first" => $current_url_clean_no_page.'/0?q='.urlencode($the_query_string),
                    "last" => $current_url_clean_no_page.'/'.$max_page .'?q='.urlencode($the_query_string),
                  ]
                ];
                if ($total > (($page+1) * $this->iiifConfig->get('iiif_content_search_api_results_per_page'))) {
                  $paging_structure["next"] = $current_url_clean_no_page.'/'.($page + 1).'?q='.urlencode($the_query_string);
                  $paging_structure["startIndex"] = $page * $this->iiifConfig->get('iiif_content_search_api_results_per_page');
                }
              }
              elseif ($version == "v2") {
                $paging_structure = [
                  "partOf" => [
                    "id" => $current_url_clean,
                    "type" => "AnnotationCollection",
                    "total" => $results['total'],
                    "first" =>
                      [
                        "id" => $current_url_clean_no_page.'/0?q='.urlencode($the_query_string),
                        "type" => "AnnotationPage",
                      ],

                    "last" =>
                      [
                        "id" => $current_url_clean_no_page.'/'.$max_page .'?='.urlencode($the_query_string),
                        "type" => "AnnotationPage",
                      ]
                  ]
                ];
                if ($total >  (($page+1) * $this->iiifConfig->get('iiif_content_search_api_results_per_page'))) {
                  $paging_structure["next"] = [
                    "id" => $current_url_clean_no_page.'/'.($page + 1).'?q='.urlencode($the_query_string),
                    "type" => "AnnotationPage",
                  ];
                  $paging_structure["startIndex"] = $page * $this->iiifConfig->get('iiif_content_search_api_results_per_page');
                }
              }
            }
            // Let's wrap up
            if ($version == "v2") {
              $iiif_response = [
                "@context" => "http://iiif.io/api/search/2/context.json",
                "id" => $current_url_clean,
                "type" => "AnnotationPage",
              ];
              $iiif_response = $iiif_response + $paging_structure;
              $iiif_response['items'] = $entries;
            }
            elseif ($version == "v1") {
              $iiif_response = [
                  "@context" => "http://iiif.io/api/presentation/2/context.json",
                  "@id" => $current_url_clean,
                  "@type" => "sc:AnnotationList",
                ] + $paging_structure;
              $iiif_response = $iiif_response + $paging_structure;
              $iiif_response['resources'] = $entries;
            }

            $response->setContent(json_encode($iiif_response, JSON_PRETTY_PRINT));
            return $response;
          }
        }
        else {
          // Pass the Template rendering Controller plain back?
          return $response;
        }
      }
    }
    throw new BadRequestHttpException(
      "Invalid Argument(s)"
    );
  }

  /**
   * Cleans the over complex original JMESPATH result for Images to a reversed array.
   *
   * @param array $jmespath_searchresult
   *
   * @return array
   */
  protected function cleanImageJmesPathResult(array $jmespath_searchresult): array {
    $image_hash = [];
    foreach($jmespath_searchresult as $canvas_order => $entries_percanvas) {
      foreach (($entries_percanvas['img_canvas_pairs'] ?? []) as $image_canvas_pair) {
        $image_id = $this->destinationScheme."://".IiifHelper::extract_iiif_id($image_canvas_pair[0]);
        // The $image_canvas_pair[1] is the Canvas targeted by the Image.
        $image_parts = explode(";",$image_id);
        $sequence = count($image_parts) > 1 ? end($image_parts) : 1 ;
        $image_hash[$image_parts[0]][$sequence][$image_canvas_pair[1]] = [$entries_percanvas["width"] ?? NULL, $entries_percanvas["height"] ?? NULL];
      }
    }
    unset($jmespath_searchresult);
    return $image_hash;
  }

  /**
   * Cleans the over complex original JMESPATH result for a VTT to a reversed array.
   *
   * @param array $jmespath_searchresult
   * @param bool $targetAnnotation
   *    If TRUE, we will return the VTT and the annotation itself as the target (allowing multiple VTTs per Canvas)
   *    If FALSE, we will return the VTT and the Canvas itself as the target (not caring which VTT matched)
   * @return array
   */
  protected function cleanVttJmesPathResult(array $jmespath_searchresult, $targetAnnotation = TRUE): array {
    $vtt_hash = [];
    foreach($jmespath_searchresult as $entries_percanvas) {
      foreach (($entries_percanvas['vtt_canvas_annotation_triad'] ?? []) as $vtt_canvas_annon_triad) {
        $vtt_uuid = NULL;
        // VTTs are not IIIF Image API URls... We could use the UUID to load the File entity, Load also the File, compare if it is there, etc
        // BUT, we also have the file_uuid in Solr already.
        // For http://localhost:8001/do/99161a75-43d8-42ee-8f18-e8d1855640b6/file/5ed0caca-49e8-48d2-9125-dedadaef5b31/download/Train_Departure.vtt

        $path = pathinfo($vtt_canvas_annon_triad[0] ?? '/');
        $parts = explode("/", $path['dirname']);
        $parts = array_reverse($parts);
        // Might be longer (normally 8), if a subdomain with paths, that is why we reverse that paths
        if (count($parts) >= 5 && $parts[0] == "download" && Uuid::isValid($parts[1]) && $parts[2] == "file" && Uuid::isValid($parts[3]) && $parts[4] == "do") {
          $vtt_uuid = $parts[1];
        }
        if (!$vtt_uuid) {
          // just skip if we have no File uuid.
          continue;
        }
        // The $vtt_canvas_annon_triad[1] is the Canvas targeted by the VTT.
        // The $vtt_canvas_annon_triad[2] is the AnnotationID containing the VTT.
        $sequence = 1 ;
        $target = $targetAnnotation ? ($vtt_canvas_annon_triad[2] ?? NULL) : ($vtt_canvas_annon_triad[1] ?? NULL);
        if (!$target) {
          // just skip if we have no Target.
          continue;
        }
        // We don't use the duration so if not present just give it a second to have a value in this array.
        $vtt_hash[$vtt_uuid][$sequence][$target] = [($entries_percanvas["duration"] ?? 1)];
      }
    }
    unset($jmespath_searchresult);
    return $vtt_hash;
  }

  /**
   * Cleans the over complex original JMESPATH result for a Text to a reversed array.
   *
   * @param array $jmespath_searchresult
   * @param bool $targetAnnotation
   *    If TRUE, we will return the Text and the annotation itself as the target (allowing multiple Texts per Canvas)
   *    If FALSE, we will return the Text and the Canvas itself as the target (not caring which Text matched)
   * @return array
   */
  protected function cleanTextJmesPathResult(array $jmespath_searchresult, $targetAnnotation = TRUE): array {
    $text_hash = [];
    foreach($jmespath_searchresult as $entries_percanvas) {
      foreach (($entries_percanvas['text_canvas_annotation_triad'] ?? []) as $text_canvas_annon_triad) {
        $text_uuid = NULL;
        $path = pathinfo($text_canvas_annon_triad[0] ?? '/');
        $parts = explode("/", $path['dirname']);
        $parts = array_reverse($parts);
        // Might be longer (normally 8), if a subdomain with paths, that is why we reverse that paths
        if (count($parts) >= 5 && $parts[0] == "download" && Uuid::isValid($parts[1]) && $parts[2] == "file" && Uuid::isValid($parts[3]) && $parts[4] == "do") {
          $text_uuid = $parts[1];
        }
        if (!$text_uuid) {
          // just skip if we have no File uuid.
          continue;
        }
        // The $text_canvas_annon_triad[1] is the Canvas targeted by the Text.
        // The $text_canvas_annon_triad[2] is the AnnotationID containing the Text.
        $sequence = 1 ;
        $target = $targetAnnotation ? ($text_canvas_annon_triad[2] ?? NULL) : ($text_canvas_annon_triad[1] ?? NULL);
        if (!$target) {
          // just skip if we have no Target.
          continue;
        }
        // We don't use the duration so if not present just give it a second to have a value in this array.
        $text_hash[$text_uuid][$sequence][$target] = [1];
      }
    }
    unset($jmespath_searchresult);
    return $text_hash;
  }


  /**
   * OCR/Annnotation Search Controller specific to IIIF Content Search Needs
   *
   * @param string $term
   * @param array $processors
   *  The list of processors. Matching processor to $ocr|true|false is done by the caller.
   * @param array $file_uris
   * @param array $file_uuids
   * @param array $node_ids
   * @param int $offset
   * @param int $limit
   * @param bool $ocr
   *  If we should use the OCRHighlight extension and the ocr_text field. If not, we will go for normal highlight and sbf_plaintext plaint text.
   * @return array
   * @throws PluginException
   * @throws SearchApiException
   */
  protected function flavorfromSolrIndex(string $term, array $processors, array $file_uris, array $file_uuids, array $node_ids = [], $offset = 0, $limit = 100, $ocr = TRUE): array
  {

    $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();

    /* @var \Drupal\search_api\IndexInterface[] $indexes */

    $result_snippets = [];
    $search_result = [];

    foreach ($indexes as $search_api_index) {

      // Create the query.
      $query = $search_api_index->query([
        'limit' => $limit,
        'offset' => $offset,
      ]);

      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      $query->keys($term);

      $allfields_translated_to_solr = $search_api_index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());
      // @TODO research if we can do a single Query instead of multiple ones?
      if ($ocr) {
        if (isset($allfields_translated_to_solr['ocr_text'])) {
          $query->setFulltextFields(['ocr_text']);
        } else {
          $this->getLogger('format_strawberryfield')->error('We can not execute a Content Search API query against XML OCR without a field named <em>ocr_text</em> of type Full Text Ocr Highlight');
          $search_result['annotations'] = [];
          $search_result['total'] = 0;
          return $search_result;
        }
      } else {
        if (isset($allfields_translated_to_solr['sbf_plaintext'])) {
          $query->setFulltextFields(['sbf_plaintext']);
        } else {
          $this->getLogger('format_strawberryfield')->error('We can not execute a Content Search API query against Plain Extracted Text without a field named <em>sbf_plaintext</em> of type Full Text');
          $search_result['annotations'] = [];
          $search_result['total'] = 0;
          return $search_result;
        }
      }
      //@TODO: Should this also be a config as `iiif_content_search_api_parent_node_fields` is for example?
      $uuid_uri_field = 'file_uuid';


      $parent_conditions = $query->createConditionGroup('OR');
      $uri_conditions = $query->createConditionGroup('OR');
      $uuid_conditions = $query->createConditionGroup('OR');

      // If Nodes are passed use them as conditionals
      if (count($node_ids)) {
        foreach ($this->iiifConfig->get('iiif_content_search_api_parent_node_fields') ?? [] as $node_field) {
          if (isset($allfields_translated_to_solr[$node_field])) {
            $parent_conditions->addCondition($node_field, $node_ids, 'IN');
          }
          $fields_to_retrieve[$node_field] = $allfields_translated_to_solr[$node_field];
        }
        if (count($parent_conditions->getConditions())) {
          $query->addConditionGroup($parent_conditions);
        }
      }


      $query->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource')
        ->addCondition('processor_id', $processors, 'IN');

      if (isset($allfields_translated_to_solr['ocr_text']) && $ocr) {
        // Will be used by \Drupal\strawberryfield\EventSubscriber\SearchApiSolrEventSubscriber::preQuery
        $query->setOption('ocr_highlight', 'on');
        // We are already checking if the Node can be viewed. Custom Datasources can not depend on Solr node access policies.
        $query->setOption('search_api_bypass_access', TRUE);
      }
      if (isset($allfields_translated_to_solr['sbf_plaintext']) && !$ocr) {
        // Will be used by  \Drupal\strawberryfield\EventSubscriber\SearchApiSolrEventSubscriber::preQuery

        $query->setOption('sbf_highlight_fields', 'on');
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
      } else {
        $this->getLogger('format_strawberryfield')->warning('For Content Search API queries, please add a search api field named <em>file_uuid</em> containing the UUID of the file entity that generated the extraction you want to search');
      }
      $have_file_condition = FALSE;
      // If $file_uris is too large, the "maxClauseCount is set to 1024" default will kick in. So we need to split this into chunks, make multiple queries.

      if (count($file_uris)) {
        //Note here. If we don't have any fields configured the response will contain basically ANYTHING
        // in the repo. So option 1 is make `iiif_content_search_api_file_uri_fields` required
        // bail out if empty? Or, we can add a short limit... that works too for now
        // April 2024, to enable in the future postprocessor that generate SBF but not from files (e.g WARC)
        foreach ($this->iiifConfig->get('iiif_content_search_api_file_uri_fields') ?? [] as $uri_field) {
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
      // If we allow Extra processing here Drupal adds Content Access Check
      // That does not match our Data Source \Drupal\search_api\Plugin\search_api\processor\ContentAccess
      // we get this filter (see 2nd)
      /*
       *   array (
        0 => 'ss_search_api_id:"strawberryfield_flavor_datasource/2006:1:en:3dccdb09-f79f-478e-81c5-0bb680c3984e:ocr"',
        1 => 'ss_search_api_datasource:"strawberryfield_flavor_datasource"',
        2 => '{!tag=content_access,content_access_enabled,content_access_grants}(ss_search_api_datasource:"entity:file" (+(bs_status:"true" bs_status_2:"true") +(sm_node_grants:"node_access_all:0" sm_node_grants:"node_access__all")))',
        3 => '+index_id:default_solr_index +hash:1evb7z',
        4 => 'ss_search_api_language:("en" "und" "zxx")',
      ),
       */
      $query->sort('search_api_relevance', 'DESC');
      $query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
      // $query->setProcessingLevel(QueryInterface::PROCESSING_FULL);
      $results = $query->execute();
      $extradata = $results->getAllExtraData() ?? [];
      // remove the ID and the parent, not needed for file matching
      unset($fields_to_retrieve['id']);
      unset($fields_to_retrieve['parent_sequence_id']);
      if ($results->getResultCount() >= 1) {
        // This applies to all searches with hits.
        foreach ($results as $result) {
          $real_id = $result->getId();
          $real_sequence = NULL;
          $real_id_part = explode(":", $real_id);
          if (isset($real_id_part[1]) && is_scalar($real_id_part[1])) {
            $real_sequence = $real_id_part[1];
            // New to 1.5 $real_sequence might have a dash
            [$real_sequence] = explode("-", $real_sequence);
            $real_sequence = (int) $real_sequence;
          }
          $extradata_from_item = $result->getAllExtraData() ?? [];

          foreach ($fields_to_retrieve as $machine_name => $field) {
            $filedata_by_id[$extradata_from_item['search_api_solr_document']['id']][$machine_name] = $extradata_from_item['search_api_solr_document'][$field] ?? NULL;
          }
          if ($real_sequence) {
            $filedata_by_id[$extradata_from_item['search_api_solr_document']['id']]['sequence_id'] = $real_sequence;
          }
        }
        if ((isset($extradata['search_api_solr_response']['ocrHighlighting']) && count(
              $extradata['search_api_solr_response']['ocrHighlighting']
            ) > 0) && $ocr) {
          foreach ($extradata['search_api_solr_response']['ocrHighlighting'] as $sol_doc_id => $field) {
            $result_snippets_base = [];
            if (isset($field[$allfields_translated_to_solr['ocr_text']]['snippets']) &&
              is_array($field[$allfields_translated_to_solr['ocr_text']]['snippets'])) {
              foreach ($field[$allfields_translated_to_solr['ocr_text']]['snippets'] as $snippet) {
                $page_width = (float)$snippet['pages'][0]['width'];
                $page_height = (float)$snippet['pages'][0]['height'];
                $is_time = str_starts_with($snippet['pages'][0]['id'], 'timesequence_');
                if ($is_time) {
                  $result_snippets_base = [
                    'timespans' => $result_snippets_base['timespans'] ?? [],
                  ];
                } else {
                  $result_snippets_base = [
                    'boxes' => $result_snippets_base['boxes'] ?? [],
                  ];
                }
                $shared_parent_region = array_fill_keys(array_keys($snippet['regions']), 0);

                foreach ($snippet['highlights'] as $key => $highlight) {
                  $parent_region = $highlight[0]['parentRegionIdx'];
                  $shared_parent_region[$parent_region]++;
                  // This allows us to offset the before and after when we are re-using a snippet for multiple hits
                  $region_text = $snippet['regions'][$parent_region]['text'] ?? $term;
                  $hit = $highlight[0]['text'] ?? $term;

                  $before_and_after = explode("{$hit}", strip_tags($region_text ?? $term));
                  // Check if (int) coordinates lrx >1 (ALTO) ... assuming nothing is at 1px to the right?
                  // else between 0 and < 1 (MINIOCR)
                  $before_index = $shared_parent_region[$parent_region] - 1;
                  $before_index = $before_index > 0 ? $before_index : 0;
                  $after_index = $shared_parent_region[$parent_region];
                  $after_index = ($after_index < count($before_and_after)) ? $after_index : 1;

                  if (((int)$highlight[0]['lrx']) > 1) {
                    //ALTO so coords need to be relative
                    $left = sprintf('%.3f', ((float)$highlight[0]['ulx'] / $page_width));
                    $top = sprintf('%.3f', ((float)$highlight[0]['uly'] / $page_height));
                    $right = sprintf('%.3f', ((float)$highlight[0]['lrx'] / $page_width));
                    $bottom = sprintf('%.3f', ((float)$highlight[0]['lry'] / $page_height));
                    $result_snippets_base['boxes'][] = [
                      'l' => $left,
                      't' => $top,
                      'r' => $right,
                      'b' => $bottom,
                      'snippet' => $region_text,
                      'before' => $before_and_after[$before_index] ?? '',
                      'after' => $before_and_after[$after_index] ?? '',
                      'hit' => $hit,
                      'time' => $is_time,
                    ];
                  } else {
                    //MINIOCR coords already relative
                    // Deal with time here
                    if (!$is_time) {
                      $result_snippets_base['boxes'][] = [
                        'l' => $highlight[0]['ulx'],
                        't' => $highlight[0]['uly'],
                        'r' => $highlight[0]['lrx'],
                        'b' => $highlight[0]['lry'],
                        'snippet' => $region_text,
                        'before' => $before_and_after[$before_index] ?? '',
                        'after' => $before_and_after[$after_index] ?? '',
                        'hit' => $hit,
                        'time' => $is_time
                      ];
                    } else {
                      // IN this case, because on now text spans into other regions, we use 'text' instead of
                      // $region_text like in a normal HOCR
                      // It is about time!
                      // Before and after. We will try to split the original text by the math
                      // If we end with more than 2 pieces, we can't be sure where it was found
                      // so we set them '' ?
                      $before_and_after = explode($highlight[0]['text'], strip_tags($region_text));
                      $result_snippets_base['timespans'][] = [
                        's' => ($highlight[0]['uly'] * $page_height) / StrawberryfieldFlavorDatasource::PIXELS_PER_SECOND,
                        'e' => ($highlight[0]['lry'] * $page_height) / StrawberryfieldFlavorDatasource::PIXELS_PER_SECOND,
                        'snippet' => $highlight[0]['text'],
                        'before' => $before_and_after[$before_index] ?? '',
                        'after' => $before_and_after[$after_index] ?? '',
                        'hit' => $hit,
                        'time' => $is_time
                      ];
                    }
                  }
                }
              }
              foreach ($fields_to_retrieve as $machine_name => $machine_name_field) {
                $result_snippets_base['sbf_metadata'][$machine_name] = $filedata_by_id[$sol_doc_id][$machine_name];
              }
            }
            $result_snippets[] = $result_snippets_base;
          }
        }
        elseif (isset($extradata['search_api_solr_response'])) {
          if ((isset($extradata['search_api_solr_response']['highlighting']) && count(
                $extradata['search_api_solr_response']['highlighting']
              ) > 0) && !$ocr) {
            foreach ($extradata['search_api_solr_response']['highlighting'] as $sol_doc_id => $field) {
              $result_snippets_base = [
                'boxes' =>  [],
              ];
              // We checked before if sbf_plaintext existed.
              foreach (($field[$allfields_translated_to_solr['sbf_plaintext']] ?? []) as $snippet) {
                $result_snippets_base['boxes'][] = [
                  'snippet' =>  UtilityAlias::formatHighlighting($snippet, '<b>', '</b>'),
                  'hit' => implode(' ', UtilityAlias::getHighlightedKeys($snippet)),
                  'time' => FALSE,
                ];
              }
              foreach ($fields_to_retrieve as $machine_name => $machine_name_field) {
                $result_snippets_base['sbf_metadata'][$machine_name] = $filedata_by_id[$sol_doc_id][$machine_name];
              }
              $result_snippets[] = $result_snippets_base;
            }
          }
        }
        // if no ocr hl was passed we won't have  $extradata['search_api_solr_response']['ocrHighlighting'], so we process
        // the other. These results won't have coordinates.
      }
    }
    $search_result['annotations'] = $result_snippets;
    $search_result['total'] = $results->getResultCount();
    return $search_result;
  }
}
