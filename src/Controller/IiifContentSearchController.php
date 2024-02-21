<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\format_strawberryfield\Entity\MetadataDisplayEntity;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpFoundation\JsonResponse;


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
  CONST IIIF_V3_JMESPATH = "items[?not_null(type, \"@type\") == 'Canvas'].[{width:width,height:height,img_canvas_pairs:items[?type == 'AnnotationPage'][].items[?motivation == 'painting'][body.not_null(id, \"@id\"), not_null(target)][]}][]";

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
          NULL, NULL, NULL, NULL, NULL,
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
        $subrequest->attributes->set('_raw_variables', $raw_inputbag);
        $this->requestStack->push($subrequest);


        /* This call is right but will never ever be cached. But i can cache at least the result of the processing */
        /* @var $controller \Drupal\format_strawberryfield\Controller\MetadataExposeDisplayController */
        $controller = $this->classResolver->getInstanceFromDefinition(
          '\Drupal\format_strawberryfield\Controller\MetadataExposeDisplayController'
        );

        $response = $controller->castViaTwig(
          $node, $metadataexposeconfig_entity, $format
        );
        // Restore the original request. We need it to return the right response for this search.
        $this->requestStack->pop();
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

            $jmespath_searchresult = StrawberryfieldJsonHelper::searchJson(
              static::IIIF_V3_JMESPATH, $jsonArray
            );

            $image_hash = $this->cleanJmesPathResult($jmespath_searchresult);
            unset($jmespath_searchresult);

            $results = $this->flavorfromSolrIndex($the_query_string, ['ocr'], array_keys($image_hash), [], ($page * $per_page), $per_page);

            /* Expected structure independent if V2 or V3.
            result = {array[345]}
              0 = {array[3]}
                width = {int} 464
                height = {int} 782
                img_canvas_pairs = {array[1]}
                  0 = {array[2]}
                    0 = "http://localhost:8183/iiif/2/bf0%2Fapplication-87758-0ad78298-d921-4f87-b0d8-104c1caf6cb1.pdf;1/full/full/0/default.jpg"
                    1 = "http://localhost:8001/do/975c85ef-4eb2-4e37-a044-078207a8e0dd/iiif/0ad78298-d921-4f87-b0d8-104c1caf6cb1/canvas/p1"
            */
            $entries = [];
            $paging_structure = [];
            if (count($results['annotations'])) {
              $i = 0;
              foreach ($results['annotations'] as $hit => $hits_per_file_and_sequence) {
                foreach (
                ($hits_per_file_and_sequence['boxes'] ?? []) as $annotation
                ) {
                  $i++;
                  // Calculate Canvas and its offset
                  // PDFs Sequence is correctly detected, but on images it should always be "1"
                  // For that we will change the response from the main Solr search using our expected ID (splitting)

                  $canvas = $image_hash[$hits_per_file_and_sequence['sbf_metadata']['uri']][$hits_per_file_and_sequence['sbf_metadata']['sequence_id']] ?? [];
                  foreach ($canvas as $canvas_id => $canvas_data) {
                    if ($canvas_id) {
                      $canvas_parts = explode("#xywh=", $canvas_id);
                      if (count($canvas_parts) == 2) {
                        $canvas_offset = explode(',' , $canvas_parts[1]);
                        $canvas_position = [
                          round($annotation['l'] * ($canvas_offset[2] ?? $canvas_data[0]) + $canvas_offset[0]),
                          round($annotation['t'] * ($canvas_offset[3] ?? $canvas_data[1]) + $canvas_offset[1]),
                          round(($annotation['r'] - $annotation['l']) * $canvas_offset[2]),
                          round(($annotation['b'] - $annotation['t']) * $canvas_offset[3]),
                        ];
                      }
                      else {
                        $canvas_position = [
                          round($annotation['l'] * $canvas_data[0]),
                          round($annotation['t'] * $canvas_data[1]),
                          round(($annotation['r'] - $annotation['l']) * $canvas_data[0]),
                          round(($annotation['b'] - $annotation['t']) * $canvas_data[1]),
                        ];
                      }

                      /*$canvas_position = [
                        $annotation['l'] * 100,
                        $annotation['t'] * 100,
                        $annotation['r'] * 100,
                        $annotation['b'] * 100,
                      ];*/

                      $canvas_position = "#xywh=" . implode(
                          ",", $canvas_position
                        );

                      // V1
                      // Generate the entry
                      if ($version == "v1") {
                        $entries[] = [
                          "@id"        => $current_url_clean
                            . "/annotation/anno-result/$i",
                          "@type"      => "oa:Annotation",
                          "motivation" => "painting",
                          "resource"   => [
                            "@type" => "cnt:ContentAsText",
                            "chars" => $annotation['snippet'],
                          ],
                          "on"         => ($canvas_parts[0] ?? $canvas_id) . $canvas_position
                        ];
                      }
                      elseif ($version == "v2") {
                        $entries[] = [
                          "id"        => $current_url_clean
                            . "/annotation/anno-result/$i",
                          "type"      => "Annotation",
                          "motivation" => "painting",
                          "body"   => [
                            "type" => "TextualBody",
                            "value" => $annotation['snippet'],
                            "format" => "text/plain",
                          ],
                          "target"         => $canvas_id . $canvas_position
                        ];
                      }
                    }
                  }
                }
              }
            }

            if ($results['total'] > $this->iiifConfig->get('iiif_content_search_api_results_per_page')) {
              $max_page = ceil($results['total']/$this->iiifConfig->get('iiif_content_search_api_results_per_page')) - 1;
              if ($version == "v1") {
                $paging_structure = [
                  "within" => [
                    "@type" => "sc:Layer",
                    "total" => $results['total'],
                    "first" => $current_url_clean_no_page.'/0?='.urlencode($the_query_string),
                    "last" => $current_url_clean_no_page.'/'.$max_page .'?='.urlencode($the_query_string),
                  ]
                ];
                if ($results['total'] > (($page+1) * $this->iiifConfig->get('iiif_content_search_api_results_per_page'))) {
                  $paging_structure["next"] = $current_url_clean_no_page.'/'.($page + 1).'?='.urlencode($the_query_string);
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
                        "id" => $current_url_clean_no_page.'/0?='.urlencode($the_query_string),
                        "type" => "AnnotationPage",
                      ],

                    "last" =>
                      [
                        "id" => $current_url_clean_no_page.'/'.$max_page .'?='.urlencode($the_query_string),
                        "type" => "AnnotationPage",
                      ]
                  ]
                ];
                if ($results['total'] >  (($page+1) * $this->iiifConfig->get('iiif_content_search_api_results_per_page'))) {
                  $paging_structure["next"] = [
                    "id" => $current_url_clean_no_page.'/'.($page + 1).'?='.urlencode($the_query_string),
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
   * Cleans the over complex original JMESPATH result to a reversed array.
   *
   * @param array $jmespath_searchresult
   *
   * @return array
   */
  protected function cleanJmesPathResult(array $jmespath_searchresult): array {
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
   * OCR Search Controller specific to IIIF Content Seaach Needs
   *
   * @param string $term
   * @param array  $processors
   * @param array  $image_uris
   * @param array  $node_ids
   * @param int    $offset
   * @param int    $limit
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  protected function flavorfromSolrIndex(string $term, array $processors, array $image_uris, array $node_ids = [], $offset = 0, $limit = 100) {

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

      $query->setFulltextFields(['ocr_text']);

      $allfields_translated_to_solr = $search_api_index->getServerInstance()
        ->getBackend()
        ->getSolrFieldNames($query->getIndex());

      $parent_conditions = $query->createConditionGroup('OR');
      $uri_conditions = $query->createConditionGroup('OR');

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

      if (isset($allfields_translated_to_solr['ocr_text'])) {
        // Will be used by \strawberryfield_search_api_solr_query_alter
        $query->setOption('ocr_highlight', 'on');
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
      if (isset($allfields_translated_to_solr['file_uuid'])) {
        $fields_to_retrieve['file_uuid'] = $allfields_translated_to_solr['file_uuid'];
      }

      if (count($image_uris)) {
        foreach ($this->iiifConfig->get('iiif_content_search_api_file_uri_fields') ?? [] as $uri_field) {
          if (isset($allfields_translated_to_solr[$uri_field])) {
            $uri_conditions->addCondition($uri_field, $image_uris, 'IN');
            $fields_to_retrieve[$uri_field]
              = $allfields_translated_to_solr[$uri_field];
          }
          if (count($uri_conditions->getConditions())) {
            $query->addConditionGroup($uri_conditions);
          }
        }
      }
      // This is documented at the API level but maybe our processing level
      // Does not trigger it?
      // Still keeping it because maybe/someday it will work out!
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
      $query->setProcessingLevel(QueryInterface::PROCESSING_FULL);
      $results = $query->execute();
      $extradata = $results->getAllExtraData() ?? [];
      // remove the ID and the parent, not needed for file matching
      unset($fields_to_retrieve['id']);
      unset($fields_to_retrieve['parent_sequence_id']);
      // Just in case something goes wrong with the returning region text
      $region_text = $term;
      $page_number_by_id = [];
      if ($results->getResultCount() >= 1) {
        if (isset($extradata['search_api_solr_response']['ocrHighlighting']) && count(
            $extradata['search_api_solr_response']['ocrHighlighting']
          ) > 0) {
          foreach ($results as $result) {
            $real_id = $result->getId();
            $real_sequence = NULL;
            $real_id_part = explode(":", $real_id);
            if (isset($real_id_part[1]) && is_scalar($real_id_part[1])) {
              $real_sequence = $real_id_part[1];
            }
            $extradata_from_item = $result->getAllExtraData() ?? [];

            foreach($fields_to_retrieve as $machine_name => $field) {
              $filedata_by_id[$extradata_from_item['search_api_solr_document']['id']][$machine_name] = $extradata_from_item['search_api_solr_document'][$field] ?? NULL;
            }
            if ($real_sequence) {
              $filedata_by_id[$extradata_from_item['search_api_solr_document']['id']]['sequence_id'] = $real_sequence;
            }
          }

          foreach ($extradata['search_api_solr_response']['ocrHighlighting'] as $sol_doc_id => $field) {
            $result_snippets_base = [];
            if (isset($field[$allfields_translated_to_solr['ocr_text']]['snippets']) &&
              is_array($field[$allfields_translated_to_solr['ocr_text']]['snippets'])) {
              foreach ($field[$allfields_translated_to_solr['ocr_text']]['snippets'] as $snippet) {
                $page_width = (float) $snippet['pages'][0]['width'];
                $page_height = (float) $snippet['pages'][0]['height'];

                $result_snippets_base = [
                  'boxes' => $result_snippets_base['boxes'] ?? [],
                ];
                $shared_parent_region = array_fill_keys(array_keys($snippet['regions']), 0);

                foreach ($snippet['highlights'] as $key => $highlight) {
                  $parent_region = $highlight[0]['parentRegionIdx'];
                  $shared_parent_region[$parent_region]++;
                  // This allows us to offset the before and after when we are re-using a snippet for multiple hits
                  $region_text = $snippet['regions'][$parent_region]['text'] ?? $term;
                  $hit = $highlight[0]['text'] ?? $term;

                  $before_and_after =  explode("<em>{$hit}</em>", $region_text ?? $term);
                  // Check if (int) coordinates >=1 (ALTO)
                  // else between 0 and < 1 (MINIOCR)
                  $before_index = $shared_parent_region[$parent_region] -1;
                  $before_index = $before_index > 0 ? $before_index : 0;
                  $after_index = $shared_parent_region[$parent_region];
                  $after_index = ($after_index < count($before_and_after)) ? $after_index : 1;

                  if ( ((int) $highlight[0]['lrx']) > 0  ){
                    //ALTO so coords need to be relative
                    $left = sprintf('%.3f',((float) $highlight[0]['ulx'] / $page_width));
                    $top = sprintf('%.3f',((float) $highlight[0]['uly'] / $page_height));
                    $right = sprintf('%.3f',((float) $highlight[0]['lrx'] / $page_width));
                    $bottom = sprintf('%.3f',((float) $highlight[0]['lry'] / $page_height));
                    $result_snippets_base['boxes'][] = [
                      'l' => $left,
                      't' => $top,
                      'r' => $right,
                      'b' => $bottom,
                      'snippet' => $region_text,
                      'before' =>  $before_and_after[$before_index] ?? '',
                      'after' =>  $before_and_after[$after_index] ?? '',
                      'hit' => $hit,
                    ];
                  }
                  else {
                    //MINIOCR coords already relative
                    $result_snippets_base['boxes'][] = [
                      'l' => $highlight[0]['ulx'],
                      't' => $highlight[0]['uly'],
                      'r' => $highlight[0]['lrx'],
                      'b' => $highlight[0]['lry'],
                      'snippet' => $region_text,
                      'before' =>  $before_and_after[$before_index] ?? '',
                      'after' =>  $before_and_after[$after_index] ?? '',
                      'hit' => $hit,
                    ];
                  }
                }
              }

              foreach($fields_to_retrieve as $machine_name => $machine_name_field) {
                $result_snippets_base['sbf_metadata'][$machine_name] = $filedata_by_id[$sol_doc_id][$machine_name];
              }
            }
            $result_snippets[] = $result_snippets_base;
          }
        }
      }
    }
    $search_result['annotations'] = $result_snippets;
    $search_result['total'] = $results->getResultCount();
    return $search_result;
  }
}
