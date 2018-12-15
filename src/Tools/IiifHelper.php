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
  
  protected $remoteInfoJsonURL;


  /**
   * @var array;
   */
  protected $remoteInfoJson;

  /**
   * @var \GuzzleHttp\Client;
   */
  protected $httpClient;



  public function __construct($remoteUrl) {
    //@TODO verify that remoteURL is a valid, actual IIIF info json URL.
    $this->remoteInfoJsonURL = $remoteUrl;
    $this->httpClient = \Drupal::httpClient();
    $this->remoteInfoJson = $this->getRemoteIIIFImageInfo($remoteUrl);
  }

  public function getRemoteIIIFImageInfo($remoteUrl) {
    // This is expensive, reason why we process and store in cache
    $jsondata = [];

    if (empty($remoteUrl)){
      // No need to alarm. all good. If not URL just return.
      return [];
    }
    $options['headers']=['Accept' => 'application/ld+json'];
    try {
      $request = $this->httpClient->get($remoteUrl, $options);
    }
    catch(\Exception $exception) {
      $responseMessage = $exception->getMessage();
      $message= $this->t('We tried to contact IIIF @url but we could not. <br> The WEB says: @response. <br> Check that URL!',
        [
          '@url' => $remoteUrl,
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
        '@url' => $remoteUrl,
        '@$jsonerror' => $json_error
      ]);

    \Drupal::logger('format_strawberryfield')->warning($message);
    return $jsondata;
  }

  public function getImageSizes() {
    if (empty($this->remoteInfoJson)) {
      return [];
    }
    if (isset($this->remoteInfoJson['sizes'])) {
      return $this->remoteInfoJson['sizes'];
    }
    return [];
  }
}