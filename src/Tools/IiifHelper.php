<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/27/18
 * Time: 1:06 PM
 */

namespace Drupal\format_strawberryfield\Tools;

use Drupal\Core\StringTranslation\StringTranslationTrait;

class IiifHelper {

  use StringTranslationTrait;

  protected $publicUrl;
  protected $identifier;
  protected $internalUrl;

  /**
   * @var array;
   */
  protected $remoteInfoJsonData;

  /**
   * @var \GuzzleHttp\Client;
   */
  protected $httpClient;


  public function __construct($publicUrl, $internalUrl, $identifier) {
    //@TODO verify that remoteURL is a valid, actual IIIF info json URL.
    $this->httpClient = \Drupal::httpClient();
    $this->remoteInfoJsonData = $this->getRemoteInfoJsonData();
    $this->publicUrl = $publicUrl;
    $this->internalUrl = $internalUrl;
    $this->identifier = $identifier;
  }

  public function getRemoteInfoJsonData() {
    $infojsonurl = $this->getInternalInfoJson();

    // This is expensive, reason why we process and store in cache
    $jsondata = [];

    if (empty($infojsonurl)){
      // No need to alarm. all good. If not URL just return.
      return [];
    }
    $options['headers']=['Accept' => 'application/ld+json'];
    try {
      $request = $this->httpClient->get($infojsonurl, $options);
    }
    catch(\Exception $exception) {
      $responseMessage = $exception->getMessage();
      $message= $this->t('We tried to contact IIIF @url but we could not. <br> The WEB says: @response. <br> Check that URL!',
        [
          '@url' => $infojsonurl,
          '@response' => $responseMessage
        ]);
      \Drupal::logger('format_strawberryfield')->warning($message);
      return $jsondata;
    }
    $body = $request->getBody()->getContents();

    $jsondata = json_decode($body, TRUE);
    $json_error = json_last_error();
    if ($json_error == JSON_ERROR_NONE) {
      return $jsondata;
    }
    $message= $this->t('Looks like data fetched from @url is not in IIIF or JSON format.<br> JSON says: @$jsonerror <br>Please check your URL!',
      [
        '@url' =>  $infojsonurl,
        '@$jsonerror' => $json_error
      ]);

    \Drupal::logger('format_strawberryfield')->warning($message);
    return $jsondata;
  }

  public function getImageSizes() {
    if (empty($this->remoteInfoJsonData)) {
      return [];
    }
    if (isset($this->remoteInfoJsonData['sizes'])) {
      return $this->remoteInfoJsonData['sizes'];
    }
    return [];
  }

  public function getInternalInfoJson() {
      return "{$this->internalUrl}/{$this->identifier}/info.json";
  }

  public function getPublicInfoJson() {
    return "{$this->publicUrl}/{$this->identifier}/info.json";
  }

}