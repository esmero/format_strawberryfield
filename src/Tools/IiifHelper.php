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
  protected $remoteInfoJson;

  /**
   * @var \GuzzleHttp\Client;
   */
  protected $httpClient;


  public function __construct($publicUrl, $internalUrl) {
    //@TODO verify that remoteURL is a valid, actual IIIF info json URL.
    $this->httpClient = \Drupal::httpClient();
    $this->publicUrl = $publicUrl;
    $this->internalUrl = $internalUrl;
  }


  /**
   * Fetches info.json when passed an asset identifier, using the internal IIIF url of the formatter.
   *
   * @param $id string
   * @return array|mixed
   */
  public function getRemoteInfoJson($id) {
    $infojsonurl = $this->getInternalInfoJson($id);

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
        '@url' => $infojsonurl,
        '@$jsonerror' => $json_error
      ]);

    \Drupal::logger('format_strawberryfield')->warning($message);
    return $jsondata;
  }


  /**
   * Based on the asset identifier, returns the value of the 'sizes' key from a remote info.json url.
   *
   * @param $id string
   * @return array|mixed
   */
  public function getImageSizes($id) {
    $remoteInfoJson = $this->getRemoteInfoJson($id);
    if (empty($remoteInfoJson)) {
      return [];
    }
    if (isset($remoteInfoJson['sizes'])) {
      return $remoteInfoJson['sizes'];
    }
    return [];
  }


  /**
   * Constructs info.json URL based on formatter's Internal IIIF Url and asset identifier.
   *
   * @param $id string
   * @return string
   */
  public function getInternalInfoJson($id) {
      return "{$this->internalUrl}/{$id}/info.json";
  }

  /**
   * Constructs info.json URL based on formatter's Public IIIF Url and asset identifier.
   *
   * @param $id string
   * @return string
   */
  public function getPublicInfoJson($id) {
    return "{$this->publicUrl}/{$id}/info.json";
  }

}