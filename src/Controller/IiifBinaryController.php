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
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   * @param string $uuid
   * @param string $format
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function servefile(ContentEntityInterface $node, string $uuid, string $format = 'default.jpg') {
    //@TODO check if format passed matches file type. If not abort.
    if ($sbf_fields = $this->strawberryfieldUtility->bearsStrawberryfield(
      $node
    )) {

      foreach ($sbf_fields as $field_name) {
        /* @var $field StrawberryFieldItem */
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

      $uri = $found->getFileUri();
      $filename = $found->getFilename();

      $response = new BinaryFileResponse($uri);
      $response->setContentDisposition(
        ResponseHeaderBag::DISPOSITION_INLINE,
        $filename
      );
      // @TODO Download? New route or argument? Argument wins.
      /*$response->setContentDisposition(
        ResponseHeaderBag::DISPOSITION_ATTACHMENT,
        $filename
      );
      // Inline*/


      return $response;
    }
    else {
      throw new NotFoundHttpException(
        "This Content does not bear files we can serve via IIIF "
      );
    }
 /*//
        // Caching...
        $sLastModified = filemtime($sFileName);
        $sEtag = md5_file($sFileName);

        $sFileSize = filesize($sFileName);
        $aInfo = getimagesize($sFileName);

        if(in_array($sEtag, $oRequest->getETags()) || $oRequest->headers->get('If-Modified-Since') === gmdate("D, d M Y H:i:s", $sLastModified)." GMT" ){
            $oResponse->headers->set("Content-Type", $aInfo['mime']);
            $oResponse->headers->set("Last-Modified", gmdate("D, d M Y H:i:s", $sLastModified)." GMT");
            $oResponse->setETag($sEtag);
            $oResponse->setPublic();
            $oResponse->setStatusCode(304);

            return $oResponse;
        }

        $oStreamResponse = new StreamedResponse();
        $oStreamResponse->headers->set("Content-Type", $aInfo['mime']);
        $oStreamResponse->headers->set("Content-Length", $sFileSize);
        $oStreamResponse->headers->set("ETag", $sEtag);
        $oStreamResponse->headers->set("Last-Modified", gmdate("D, d M Y H:i:s", $sLastModified)." GMT");

        $oStreamResponse->setCallback(function() use ($sFileName) {
            readfile($sFileName);
        });

        return $oStreamResponse;
    }
 */
  }
}