<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\format_strawberryfield\Entity\MetadataDisplayEntity;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Route;

/**
 * A Wrapper Controller to access Twig processed JSON on a URL.
 */
class IiifContentSearchController extends ControllerBase {


  /**
   * A JMESPATH to fetch Canvas IDs, Item Ids, Images and Service(Images) for IIIF Presentation 2.x
   */
  CONST IIIF_V2_JMESPATH = "sequences[].canvases[?\"@type\" == 'sc:Canvas'][].{\"canvas_id\":\"@id\", \"items\": images[?motivation == 'sc:painting'].{\"id\":\"@id\", \"image_ids\":[resource.\"@id\"], \"service_ids\":[resource.service][?starts_with(\"@context\", 'http://iiif.io/api/image/')].\"@id\"}}";

  /**
   * A JMESPATH to fetch Canvas IDs, Item Ids, Images and Service(Images) for IIIF Presentation 3.x
   */
  CONST IIIF_V3_JMESPATH = "items[?type == 'Canvas'].{\"canvas_id\":id ,\"items\": items[?type == 'AnnotationPage'].{\"id\":id,\"image_ids\": items[?motivation == 'painting'].body.id, \"service_ids\": items[?motivation == 'painting'].body.service[].{type: not_null(type, \"@type\"), id: not_null(id, \"@id\")}[?starts_with(type, 'ImageService')].id }}";

  /**
   * Mime type guesser service.
   *
   * @var \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface
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
    return $instance;
  }

  /**
   * Wrapper Controller Method. Returns a IIIF Content Search response.
   *
   * @param \Symfony\Component\HttpFoundation\Request                   $request
   * @param \Drupal\Core\Entity\ContentEntityInterface                  $node
   *   A Node as argument.
   * @param \Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity $metadataexposeconfigentity
   *   The Metadata Config Entity that rendered the calling IIIF Manifest.
   * @param string                                                      $page
   *   The page according to the IIIF Content Search API 1.0/2.0 Specs.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   *
   * @throw \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *
   */
  public function searchWithExposedMetadataDisplay(
    Request $request,
    string $mode,
    ContentEntityInterface $node,
    MetadataExposeConfigEntity $metadataexposeconfig_entity,
    $page = '0'
  ) {

    $entity = $metadataexposeconfig_entity->getMetadataDisplayEntity();

      // IDEA. What if i make a query first using all different Image Ids found. NO Q
     // I pass also as facet? the Node uuid. Maybe i can then save the facet so consequent Queryies can be done
     // USING the facet as query filter?
    // Also idea. 3 modes. Simple. Get all related ADOs. Complex process all Image IDs from the manifest.
    // Why? Bc that gives the user writing the Manifest the chance to help us, making the query faster
    // 2 Modes as Path arguments? Good idea.

    if ($entity) {
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
        $exposed_metadata_route = $this->routeProvider->getRouteByName('format_strawberryfield.metadatadisplay_caster');
        $subrequest->attributes->set(RouteObjectInterface::ROUTE_NAME, 'format_strawberryfield.metadatadisplay_caster');
        $subrequest->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $exposed_metadata_route);
        $subrequest->attributes->set('format', $format);
        $subrequest->attributes->set('_route_params', array_replace($subrequest->attributes->get('_route_params', []), ['format' => $format]));

        /** @var $raw_inputbag \Symfony\Component\HttpFoundation\InputBag  */
        $raw_inputbag = $subrequest->attributes->all()['_raw_variables'];
        $raw_inputbag->add(['format' => $format]);
        $subrequest->attributes->set('_raw_variables', $raw_inputbag);
        $this->requestStack->push($subrequest);
        // TESTING
        //error_log($this->requestStack->getCurrentRequest()->getPathInfo());


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
        /// NOOOOOOO on localhost:8001 i can't do this ... because it is internal to
        /// ALSO still the URL used for <current> won't work, would be http://esmero and stuff
        /// What to do? what to do?
        /*$response_metadataexposed_endpoint = \Drupal::httpClient()->get($canonical_url);
        //['headers' => ['Accept' => 'text/plain']]);
        // This is absurd. The cache bin of the rendered page contains the scheme
        // So there is no gain in calling the endpoint via the client if the URL is not going to match
        // See \Drupal\page_cache\StackMiddleware\PageCache::getCacheId
        // Why not only store in the cache bin the relative URL? why Drupal?
        $data = (string) $response_metadataexposed_endpoint->getBody();
        if ($data) {
          $json_string = $data;
          $jsonArray = json_decode($json_string, TRUE);
          $cacheabledata = json_encode($cacheabledata);
          $responsetype = 'application/json';
          $status = 200;

          $response = new CacheableJsonResponse(
            $cacheabledata,
            $status,
            ['content-type' => $responsetype],
            TRUE
          );*/
          return $response;
        }
        //return $this->search($subrequest, $node, $entity, $page);
    }
    throw new BadRequestHttpException(
      "Invalid Argument(s)"
    );
  }


  /**
   * Main Controller Method. Returns a IIIF Content Search response.
   *
   * @param \Symfony\Component\HttpFoundation\Request                   $request
   * @param \Drupal\Core\Entity\ContentEntityInterface                  $node
   *   A Node as argument.
   * @param \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity $metadatadisplay_entity
   *   The Metadata Entity that rendered the calling IIIF Manifest.
   * @param string                                                      $page
   *   The page according to the IIIF Content Search API 2.0 Specs.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse|\Drupal\Core\Cache\CacheableResponse
   *   A cacheable response.
   */
  public function search(
    Request $request,
    ContentEntityInterface $node,
    MetadataDisplayEntity $metadatadisplay_entity,
    $page = '0'
  ) {


    /*
    {
  "@context": "http://iiif.io/api/search/2/context.json",
  "id": "https://example.org/service/manifest/search?q=bird&motivation=painting",
  "type": "AnnotationPage",

  "items": [
    {
      "id": "https://example.org/identifier/annotation/anno-line",
      "type": "Annotation",
      "motivation": "painting",
      "body": {
        "type": "TextualBody",
        "value": "A bird in the hand is worth two in the bush",
        "format": "text/plain"
      },
      "target": "https://example.org/identifier/canvas1#xywh=100,100,250,20"
    }
    // Further matching annotations here ...
  ]
}

v2

{
  "@context":"http://iiif.io/api/presentation/2/context.json",
  "@id":"http://example.org/service/manifest/search?q=bird&motivation=painting",
  "@type":"sc:AnnotationList",

  "resources": [
    {
      "@id": "http://example.org/identifier/annotation/anno-line",
      "@type": "oa:Annotation",
      "motivation": "sc:painting",
      "resource": {
        "@type": "cnt:ContentAsText",
        "chars": "A bird in the hand is worth two in the bush"
      },
      "on": "http://example.org/identifier/canvas1#xywh=100,100,250,20"
    }
    // Further matching annotations here ...
  ]
}
*/
    /* This is going to be the search response, we will have to JSON encode it before passing to the
    $response object
    To test
    http://localhost:8001/iiifcontentsearch/do/11aa2644-b4ec-11eb-81a8-b74746cb79fe/metadatadisplay/3/page/0
    /iiifcontentsearch/do/{node}/metadatadisplay/{metadatadisplay_entity}/page/{page}
    */

    $current_url = $request->getRequestUri();
    $the_query_string = $request->get('q','');
    $the_api = $request->get('api',1);

    // Note, since the URL/base urls between Metadata Display entity rendered directly
    // Vs an endpoint might vary (in unexpected ways depending on the template syntax)
    // BUT, the order or appearance (ordinal) of IDs won't
    // We don't need really, like really the IDs cached. Just the order.
    // With the order we actually fetch the ID once we got the image and process from there.
    // OR we should only allow exposed ones to be processed.

    $node_uuid = $node->uuid();
    if ($the_api == 2) {
      $cacheabledata = [
        "@context" => "http://iiif.io/api/search/2/context.json",
        "id" => $current_url,
        "type" => "AnnotationPage",
        "items" => [
          "id" => "https://example.org/identifier/annotation/anno-line",
          "type" => "Annotation",
          "motivation" => "painting",
          "body" => [
            "type" => "TextualBody",
            "value" => "you searched for {$the_query_string}, well done",
            "format" => "text/plain",
          ],
          "target" => "http://localhost:8001/do/22cea396-b4ec-11eb-8b96-9fa490fdda0b/iiif/canvas/p1#xywh=100,100,350,40",
        ],
      ];
    }
    else {
      $cacheabledata = [
        "@context" => "http://iiif.io/api/presentation/2/context.json",
        "@id" => $current_url,
        "@type" => "sc:AnnotationList",
        "resources" => [
          "@id" => "https://example.org/identifier/annotation/anno-line",
          "@type" => "oa:Annotation",
          "motivation" => "painting",
          "resource" => [
            "@type" => "cnt:ContentAsText",
            "chars" => "you searched for {$the_query_string}, well done",
          ],
          "on" => "http://localhost:8001/do/22cea396-b4ec-11eb-8b96-9fa490fdda0b/iiif/canvas/p1#xywh=100,100,350,40",
        ],
      ];
    }

    $cacheabledata = json_encode($cacheabledata);
    $responsetype = 'application/json';
    $status = 200;

    $response = new CacheableJsonResponse(
      $cacheabledata,
      $status,
      ['content-type' => $responsetype],
      TRUE
    );
    return $response;

  }

  public function parseManifest(string $json_string) {
    $jsonArray = json_decode($json_string, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return [];
    }

    $searchresult = StrawberryfieldJsonHelper::searchJson(
      static::IIIF_V3_JMESPATH, $jsonArray
    );
    // Canvas will come in order or appearance
    foreach ($searchresult as $canvas_order => $canvas) {
      foreach ($canvas['items'] as $item) {
        $images = $item['service_ids'] ?? ($item['image_ids'] ?? []);
        /*
         Needs to match for now either an argument at the end of in the ID.
        $images[] = "http://localhost:8183/iiif/2/a5e%2FPERRITO.pdf/full/max/0/default.jpg?page=1";
        $images[] = "http://localhost:8183/iiif/2/a5e%2FPERRITO.pdf;1/full/max/0/default.jpg";
        */
        foreach ($images as $image_url) {
          $image_id = IiifHelper::extract_iiif_id($image_url);
          $hash_images[$image_id] = array_merge($hash_images[$image_id] ?? [], [$canvas['canvas_id']]);
        }
        if (count($images)) {
          $hash[$canvas['canvas_id']] = array_merge($hash[$canvas['canvas_id']] ?? [], $images);
        }
        // We count even if there are no images because the order is what we want here
        $canvas_natural_order[$canvas['canvas_id']] = $canvas_order;
      }
    }
    if (empty($order)) {
      $order = $canvas_natural_order;
    }


  }




}
