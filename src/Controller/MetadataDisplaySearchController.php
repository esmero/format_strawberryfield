<?php
namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\RenderContext;
use Drupal\format_strawberryfield\Entity\MetadataExposeConfigEntity;
use Drupal\format_strawberryfield\Entity\MetadataDisplayEntity;
use Drupal\format_strawberryfield\Tools\IiifHelper;
use Drupal\strawberryfield\Controller\StrawberryfieldFlavorDatasourceSearchController;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MetadataDisplaySearchController extends
  StrawberryfieldFlavorDatasourceSearchController {

  /**
   * A JMESPATH to fetch Canvas IDs, Item Ids, Images and Service(Images) for IIIF Presentation 2.x
   */
  CONST IIIF_V2_JMESPATH = "sequences[].canvases[?\"@type\" == 'sc:Canvas'][].{\"canvas_id\":\"@id\", \"items\": images[?motivation == 'sc:painting'].{\"id\":\"@id\", \"image_ids\":[resource.\"@id\"], \"service_ids\":[resource.service.\"@id\"]}}";

  /**
   * A JMESPATH to fetch Canvas IDs, Item Ids, Images and Service(Images) for IIIF Presentation 3.x
   */
  CONST IIIF_V3_JMESPATH = "items[?type == 'Canvas'].{\"canvas_id\":id ,\"items\": items[?type == 'AnnotationPage'].{\"id\":id,\"image_ids\": items[?motivation == 'painting'].body.id, \"service_ids\": items[?motivation == 'painting'].body.service[].{type:({t: type, at: \"@type\"}).not_null(t, at), id:({id: id, atid: \"@id\"}).not_null(id, atid)}[?starts_with(type, 'ImageService')].id }}";

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
   * @var \Drupal\format_strawberryfield\EmbargoResolverInterface
   */
  protected $embargoResolver;

  /**
   * OCR Search Controller using Exposed Metadata Display. Can deal with multiple formats/requests types
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   * @param string $fileuuid
   * @param string $processor
   * @param string $format
   * @param string $page
   *
   * @return \Symfony\Component\HttpFoundation\Response
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function searchWithExposedMetadataDisplay(Request $request, ContentEntityInterface $node, MetadataExposeConfigEntity $metadataexposeconfigentity, string $fileuuid = 'all', string $processor = 'ocr', string $format = 'json', string $page = 'all') {
    $callback = $request->query->get('callback');
    // remove the call back so we can get a clean response
    $request->query->remove('callback');
    $result = $this->search(
      $request, $node, $fileuuid, $processor, $format, $page
    );
    // No need to process if the original query failed.
    if ($result->isSuccessful()) {
      $resultjson_string = $result->getContent();
      $resultjson = json_decode($resultjson_string, TRUE);
      // Now restore the callback if it was present.
      if (!empty($callback)) {
        $result->setCallback($callback);
      }
      // Only process if any matches.
      if (isset($resultjson['matches']) && is_array($resultjson['matches'])
        && count($resultjson['matches'])
      ) {
        $entity = $metadataexposeconfigentity->getMetadataDisplayEntity();
        if ($entity) {
          $responsetypefield = $entity->get('mimetype');
          $responsetype = $responsetypefield->first()->getValue();

          $responsetype = $responsetype['value'] ?? 'text/html';

          if (!in_array(
            $responsetype, ['application/ld+json', 'application/json']
          )
          ) {
            throw new BadRequestHttpException(
              "Sorry, it is not possible to return JSON from this endpoint"
            );
          }
          else {
            $extension = $this->mimeTypeGuesser->inverseguess($responsetype);
          }

          $filename = !empty($extension) ? 'default.' . $extension
            : 'default.json';

          /** @var \Drupal\format_strawberryfield\Controller\MetadataExposeDisplayController $controller */
          $controller = $this->classResolver->getInstanceFromDefinition(
            '\Drupal\format_strawberryfield\Controller\MetadataExposeDisplayController'
          );
          $response = $controller->castViaTwig(
            $node, $metadataexposeconfigentity, $filename
          );

          if ($response->isSuccessful()) {
            $json_string = $response->getContent();
            $jsonArray = json_decode($json_string, TRUE);
            if (json_last_error() == JSON_ERROR_NONE) {
              // V2 or V3? hate to hardcode this here, but that is where we are
              // @TODO, eventually find "the commonalities" basically the "id" v/s "@id"
              if (isset($jsonArray['@context'])
                && is_array($jsonArray['@context'])
                && in_array(
                  "http://iiif.io/api/presentation/3/context.json",
                  $jsonArray['@context']
                )
              ) {
                $iiif_structures = $jsonArray['structures'][0]['items'] ?? [];
                $order = [];
                foreach ($iiif_structures as $range_item) {
                  // This will be the order of the pages
                  if ($range_item['type'] == 'Canvas') {
                    $order[] = $range_item['id'];
                  }
                }
                // Flip gives me now the order keyed by Canvas ID.
                $order = array_flip($order);

                // V3 need to be able to also work on @id/@type for legacy reasons (service part)
                // Also https://iiif.io/api/cookbook/recipe/0033-choice/ good test candidate
                // https://iiif.io/api/cookbook/recipe/0036-composition-from-multiple-images/
                $searchresult = StrawberryfieldJsonHelper::searchJson(
                  static::IIIF_V3_JMESPATH, $jsonArray
                );
                // Canvas will come in order or appearance
                foreach ($searchresult as $canvas_order => $canvas) {
                  foreach ($canvas['items'] as $item) {
                    $images = $item['service_ids'] ?? ($item['image_ids'] ?? []);
                    $images[] = "http://localhost:8183/iiif/2/a5e%2FPERRITO.pdf/full/max/0/default.jpg?page=1";
                    $images[] = "http://localhost:8183/iiif/2/a5e%2FPERRITO.pdf;1/full/max/0/default.jpg";
                    foreach ($images as $image_url) {
                      $image_id = IiifHelper::extract_iiif_id($image_url);
                      $hash_images[$image_id] = array_merge($hash_images[$image_id] ?? [], [$canvas['canvas_id']]);
                    }
                    if (count($images)) {
                      $hash[$canvas['canvas_id']] = array_merge($hash[$canvas['canvas_id']] ?? [], $images);
                    }
                    // We copunt even if there are no images because the order is what we want here
                    $canvas_natural_order[$canvas['canvas_id']] = $canvas_order;
                  }
                }
                if (empty($order)) {
                  $order = $canvas_natural_order;
                }
                //@TODO this result needs to be cached independently of the query.
                // WE will add node, the twig template and the endpoint as cache tags
                // Now check to what canvases those images belong to?
                $match_resolved = [];
                foreach ($resultjson['matches'] as &$match) {
                  if (isset($match['sbf_metadata']['sbf_file_uri']) && isset($match['sbf_metadata']['sequence_id'])) {
                    $match_image_id_parts = parse_url($match['sbf_metadata']['sbf_file_uri']);
                    if ($match_image_id_parts) {
                      $match_image_id = $match_image_id_parts['host'] . $match_image_id_parts['path'];
                      $match_image_id_with_sequence
                        = $match_image_id . ';'
                        . $match['sbf_metadata']['sequence_id'];
                      if (isset($hash_images[$match_image_id]) && is_array($hash_images[$match_image_id])) {
                        foreach ($hash_images[$match_image_id] as $canvas_id) {
                          // Here. So if the Same image appears in multiple canvases
                          // We need to duplicate each ['par'] with different page numbers
                          $real_page_id = $order[$canvas_id]
                            ?? 0;
                          foreach ($match['par'] ?? [] as $key => $snippet) {
                            $match['par'][$key]['page'] = $real_page_id;
                          }
                          $match_resolved[] = $match;
                        }
                      }
                      if (isset($hash_images[$match_image_id_with_sequence]) && is_array($hash_images[$match_image_id_with_sequence])) {
                        foreach ($hash_images[$match_image_id] as $canvas_id) {
                          // Here. So if the Same image appears in multiple canvases
                          // We need to duplicate each ['par']?
                          $real_page_id
                            = $order[$canvas_id]
                            ?? 0;
                          foreach ($match['par'] ?? [] as $key => $snippet) {
                            $match['par'][$key]['page'] = $real_page_id;
                          }
                          $match_resolved[] = $match;
                        }
                      }
                    }
                  }
                }
                $resultjson['matches'] = $match_resolved;
                // Restore the results after mangling with the Page Order.
                $result->setContent(json_encode($resultjson));
              }
            }
          }
        }
      }
    }
    return $result;
  }

  /**
   * OCR Search Controller using Exposed Metadata Display. Can deal with multiple formats/requests types
   *
   */
  public function searchWithMetadataDisplay(Request $request, ContentEntityInterface $node, MetadataDisplayEntity $metadatadisplayentity, string $fileuuid = 'all', string $processor = 'ocr', string $format = 'json', string $page = 'all') {
    $callback = $request->query->get('callback');
    // remove the call back so we can get a clean response
    $request->query->remove('callback');
    $result = $this->search(
      $request, $node, $fileuuid, $processor, $format, $page
    );
    // No need to process if the original query failed.
    if ($result->isSuccessful()) {
      $resultjson_string = $result->getContent();
      $resultjson = json_decode($resultjson_string, TRUE);
      // Now restore the callback if it was present.
      if (!empty($callback)) {
        $result->setCallback($callback);
      }
      // Only process if any matches.
      if (isset($resultjson['matches']) && is_array($resultjson['matches'])
        && count($resultjson['matches'])
      ) {

        if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
          $node
        )) {
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
                    '@metadatadisplay' => $metadatadisplayentity->label(),
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

            $embargo_info = $this->embargoResolver->embargoInfo($node->uuid(), $jsondata);
            // This one is for the Twig template
            // We do not need the IP here. No use of showing the IP at all?
            $context_embargo = ['data_embargo' => ['embargoed' => false, 'until' => NULL]];
            if (is_array($embargo_info)) {
              $embargoed = $embargo_info[0];
              $context_embargo['data_embargo']['embargoed'] = $embargoed;
              $embargo_tags[] = 'format_strawberryfield:all_embargo';
              if ($embargo_info[1]) {
                $embargo_tags[]= 'format_strawberryfield:embargo:'.$embargo_info[1];
                $context_embargo['data_embargo']['until'] = $embargo_info[1];
              }
              if ($embargo_info[2]) {
                $embargo_context[] = 'ip';
              }
            }
            else {
              $embargoed = $embargo_info;
            }

            $context['node'] = $node;
            $context['iiif_server'] = $this->config(
              'format_strawberryfield.iiif_settings'
            )->get('pub_server_url');
            $original_context = $context + $context_embargo;
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
              function () use ($context, $metadatadisplayentity) {
                return $metadatadisplayentity->renderNative($context);
              }
            );
          }
        }
        if ($cacheabledata) {
          $json_string = $cacheabledata;
          $jsonArray = json_decode($json_string, TRUE);
          if (json_last_error() == JSON_ERROR_NONE) {
            // V2 or V3? hate to hardcode this here, but that is where we are
            // @TODO, eventually find "the commonalities" basically the "id" v/s "@id"
            if (isset($jsonArray['@context'])
              && is_array($jsonArray['@context'])
              && in_array(
                "http://iiif.io/api/presentation/3/context.json",
                $jsonArray['@context']
              )
            ) {
              $iiif_structures = $jsonArray['structures'][0]['items'] ?? [];
              $order = [];
              foreach ($iiif_structures as $range_item) {
                // This will be the order of the pages
                if ($range_item['type'] == 'Canvas') {
                  $order[] = $range_item['id'];
                }
              }
              // Flip gives me now the order keyed by Canvas ID.
              $order = array_flip($order);

              // V3 need to be able to also work on @id/@type for legacy reasons (service part)
              // Also https://iiif.io/api/cookbook/recipe/0033-choice/ good test candidate
              // https://iiif.io/api/cookbook/recipe/0036-composition-from-multiple-images/
              $searchresult = StrawberryfieldJsonHelper::searchJson(
                static::IIIF_V3_JMESPATH, $jsonArray
              );
              // Canvas will come in order or appearance
              foreach ($searchresult as $canvas_order => $canvas) {
                foreach ($canvas['items'] as $item) {
                  $images = $item['service_ids'] ?? ($item['image_ids'] ?? []);
                  $images[] = "http://localhost:8183/iiif/2/a5e%2FPERRITO.pdf/full/max/0/default.jpg?page=1";
                  $images[] = "http://localhost:8183/iiif/2/a5e%2FPERRITO.pdf;1/full/max/0/default.jpg";
                  foreach ($images as $image_url) {
                    $image_id = IiifHelper::extract_iiif_id($image_url);
                    $hash_images[$image_id] = array_merge($hash_images[$image_id] ?? [], [$canvas['canvas_id']]);
                  }
                  if (count($images)) {
                    $hash[$canvas['canvas_id']] = array_merge($hash[$canvas['canvas_id']] ?? [], $images);
                  }
                  // We copunt even if there are no images because the order is what we want here
                  $canvas_natural_order[$canvas['canvas_id']] = $canvas_order;
                }
              }
              if (empty($order)) {
                $order = $canvas_natural_order;
              }
              //@TODO this result needs to be cached independently of the query.
              // WE will add node, the twig template and the endpoint as cache tags
              // Now check to what canvases those images belong to?
              foreach ($resultjson['matches'] as &$match) {
                if (isset($match['sbf_metadata']['sbf_file_uri']) && isset($match['sbf_metadata']['sequence_id'])) {
                  $match_image_id_parts = parse_url($match['sbf_metadata']['sbf_file_uri']);
                  if ($match_image_id_parts) {
                    $match_image_id = $match_image_id_parts['host'] . $match_image_id_parts['path'];
                    $match_image_id_with_sequence
                      = $match_image_id . ';'
                      . $match['sbf_metadata']['sequence_id'];
                    if (isset($hash_images[$match_image_id]) && is_array($hash_images[$match_image_id])) {
                      foreach ($hash_images[$match_image_id] as $canvas_id) {
                        // Here. So if the Same image appears in multiple canvases
                        // We need to duplicate each ['par']?
                        $real_page_id = $order[$canvas_id]
                          ?? 0;
                        foreach ($match['par'] ?? [] as $key => $snippet) {
                          $match['par'][$key]['page'] = $real_page_id;
                        }
                      }
                    }
                    if (isset($hash_images[$match_image_id_with_sequence]) && is_array($hash_images[$match_image_id_with_sequence])) {
                      foreach ($hash_images[$match_image_id] as $canvas_id) {
                        // Here. So if the Same image appears in multiple canvases
                        // We need to duplicate each ['par']?
                        $real_page_id
                          = $order[$canvas_id]
                          ?? 0;
                        foreach ($match['par'] ?? [] as $key => $snippet) {
                          $match['par'][$key]['page'] = $real_page_id;
                        }
                      }
                    }
                  }
                }
              }
              // Restore the results after mangling with the Page Order.
              $result->setContent(json_encode($resultjson));
            }
          }
        }
      }
    }
    return $result;
  }

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->classResolver = $container->get('class_resolver');
    $instance->mimeTypeGuesser = $container->get('strawberryfield.mime_type.guesser.mime');
    $instance->embargoResolver = $container->get('format_strawberryfield.embargo_resolver');
    return $instance;
  }

}
