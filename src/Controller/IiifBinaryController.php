<?php

namespace Drupal\format_strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\format_strawberryfield\Component\HttpFoundation\RangedRemoteFileRespone;

/**
 * A IIIF Wrapper Controller for non public files.
 */
class IiifBinaryController extends ControllerBase {

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
   * IiifBinaryController constructor.
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
   * @param Request $request
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   * @param string $uuid
   * @param string $format
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Symfony\Component\HttpFoundation\StreamedResponse|\Symfony\Component\HttpFoundation\Response | RangedRemoteFileRespone
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function servefile(Request $request, ContentEntityInterface $node, string $uuid, string $format = 'default.jpg') {
    //@TODO check if format passed matches file type. If not abort.
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

      foreach ($sbf_fields as $field_name) {
        /* @var $field StrawberryFieldItem[] */
        $field = $node->get($field_name);
        $found = NULL;
        $fileentityarray = $this->entityTypeManager
          ->getStorage('file')
          ->loadByProperties([
            'uuid' => $uuid,
          ]);

        // This type has no source field configuration.
        if (!$field) {
          throw new \Exception(
            "No Strawberry field found for {$node} ."
          );
        }
        if (!$fileentityarray) {
          throw new NotFoundHttpException(
            "The Requested Resource does not exist."
          );
        } else {
          $fileentity = current($fileentityarray);
        }
        // now that we know the file actually exists, lets check if this
        // SBF has that file in its definition

        $files = $node->get('field_file_drop')->getValue();
        foreach($files as $offset => $fileinfo) {
          if ($fileinfo['target_id'] == $fileentity->id()) {
            /* @var $found \Drupal\file\Entity\File */
            $found = $fileentity;
            break;
          }
        }
      }

      // If media has no file item.
      if (!$found) {
        throw new NotFoundHttpException(
          "The Requested Resource does not exist in this Digital Object"
        );
      }

      $stream = $request->query->get('stream');
      $uri = $found->getFileUri(); // The source URL
      $filename = $found->getFilename(); // The filename...

      $mime = $found->getMimeType(); // We may want the actual mime from metadata? Not really.

      // For weirdly migrated/pre 1.1.1 mimetypes we remap a few here
      if ($mime == 'image/jpg') {
        $mime = 'image/jpeg';
      }
      if ($mime == 'image/tif') {
        $mime = 'image/tiff';
      }
      if ($mime == "audio/vnd.wave") {
        $mime = "audio/x-wave";
      }

      // Let's check!
      // Note: we are not touching the metadata here.
      $etag = md5($found->uuid());
      /** @var $field \Drupal\Core\Field\FieldItemList */
      foreach ($field->getIterator() as $delta => $itemfield) {
        /** @var $itemfield \Drupal\strawberryfield\Plugin\Field\FieldType\StrawberryFieldItem */
        $flatvalues = (array) $itemfield->provideFlatten();

        if (isset($flatvalues['urn:uuid:' . $found->uuid()]["checksum"])) {
          $etag = $flatvalues['urn:uuid:' . $found->uuid()]["checksum"];
          break;
        }
      }

      $size = $found->getSize(); // Bytes
      $createdtime = $found->getCreatedTime(); // last modified makes little sense?
      if ($request->getMethod() == 'HEAD') {
        $response = new \Symfony\Component\HttpFoundation\Response;
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Last-Modified', gmdate("D, d M Y H:i:s", $createdtime)." GMT");
        $response->headers->set('Content-Length', $size);
        $response->setETag($etag, TRUE);
        return $response;
      }
      /** @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $stream_wrapper_manager */
      $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager');
      /** @var \Drupal\Core\StreamWrapper\StreamWrapperInterface|bool $wrapper */
      $wrapper = $stream_wrapper_manager->getViaUri($uri);

      // If client is asking for a range streaming won't help.
      // We should be able to decide when to stream, always based on Size
      // And constraints.

      if ($stream && !$request->headers->has('Range') || !$request->headers->has('Range') && $size > (memory_get_usage(TRUE)/2)) {
        $response = new StreamedResponse();
        $response->headers->set("Content-Type", $mime);
        $response->headers->set("Last-Modified", gmdate("D, d M Y H:i:s", $createdtime)." GMT");
        // Let's set this as a weak ETAG. Why? If e.g NGINX is delivering via GZIP
        // Firefox and others will assume its weak and will in response request
        // it as weak not matching afterwards.
        $response->setETag($etag, TRUE);
        if(in_array($etag, $request->getETags()) || $request->headers->get('If-Modified-Since') === gmdate("D, d M Y H:i:s", $createdtime)." GMT" ){
          $response->setPublic();
          $response->setStatusCode(304);
          return $response;
        }

        $response->headers->set("Content-Length", $size);
        $response->prepare($request);
        $response->setCallback(function () use ($uri) {
          // We may want to force Garbage Collection here
          // Even if globally available...
          $i = 0;
          gc_enable();
          $stream = fopen($uri, 'r');
          // While the stream is still open
          while (!feof($stream)) {
            $i++;
            if ($i == 10000) {
              gc_collect_cycles();
              $i = 0;
            }
            // Read 1,024 bytes from the stream
            echo fread($stream, 8192);
          }
          // Be sure to close the stream resource when you're done with it
          fclose($stream);
        });

        return $response;
      }
      else {
        // TODO we need to check if ranges are actually valid
        // Before attempting this? Client should know.

        // Work aroud. Private/Public served files
        // Won't be able to use my RangedRemoteFileRespone
        // So check if the wrapper serves locally
        $scheme = $stream_wrapper_manager->getScheme($uri);
        if (isset($stream_wrapper_manager->getWrappers(
            StreamWrapperInterface::LOCAL)[$scheme]
        )) {
          $is_local = TRUE;
        }
        else {
          $is_local = FALSE;
        }

        if ($request->headers->has('Range') && !$is_local) {
          // S3 or remote storage needs this. Common Archipelago
          // Deployment use case.
          $response = new RangedRemoteFileRespone($uri);
          $response->setETag($etag, TRUE);
          $response->headers->set("Content-Type", $mime);
          $response->prepare($request);
        } else {
          // Should be able to handle ranged?
          // see https://github.com/symfony/symfony/pull/38516/files
          $response = new BinaryFileResponse($uri);
          $response->setETag($etag, TRUE);
          $response->headers->set("Content-Type", $mime);
          $response->prepare($request);
        }
        if ($request->headers->has('Range')) {
          // Again..
          if (!in_array($response->getStatusCode(), [206,406])) {
            $range = $request->headers->get('Range') ?? '';
            // Work around for Safari: the range request for Video/Audio might be 0 - filesize-1 so the whole thing.
            // But that will bypass a ranged response header from & status from the ::prepare method and return a 200.
            [$start, $end] = explode('-', substr($range, 6), 2) + [0];
            if ($start == 0 && $end == $size-1) {
              $response->headers->set('Content-Range', sprintf('bytes %s-%s/%s', $start, $end, $size));
            }
          }
        }

        $response->setContentDisposition(
          ResponseHeaderBag::DISPOSITION_INLINE,
          $filename
        );
        return $response;
      }
    }
    else {
      throw new NotFoundHttpException(
        "This Content does not bear files we can serve via IIIF "
      );
    }
  }

  /**
   * Serves a temp File to its owner.
   *
   * @param string $uuid
   * @param string $format
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws  \Symfony\Component\HttpFoundation\AccessDeniedHttpException
   */
  public function servetempfile(string $uuid, string $format = 'default.jpg') {
    /* @var $fileentityarray \Drupal\file\Entity\File[] */
    $fileentityarray = $this->entityTypeManager
      ->getStorage('file')
      ->loadByProperties([
        'uuid' => $uuid,
      ]);
    if (!$fileentityarray) {
      throw new NotFoundHttpException(
        "The Requested Resource does not exist."
      );
    } else {
      $fileentity = current($fileentityarray);
    }

    if (($fileentity->getOwnerId() ==  $this->currentUser()->id()) &&  $fileentity->isTemporary()){
      $uri = $fileentity->getFileUri();
      $filename = $fileentity->getFilename();

      $response = new BinaryFileResponse($uri);
      $response->setContentDisposition(
        ResponseHeaderBag::DISPOSITION_INLINE,
        $filename
      );
      return $response;
    }
    else {
      throw new AccessDeniedHttpException(
        "You are not allowed to access this resource via IIIF "
      );
    }
  }

}
