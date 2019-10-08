<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/27/18
 * Time: 1:06 PM
 */

namespace Drupal\format_strawberryfield\Tools;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ConnectException;

class IiifUrlValidator {

  use StringTranslationTrait;

  /**
   * @var \GuzzleHttp\Client;
   */
  protected $httpClient;


  public function __construct () {
    $this->httpClient = $this->httpClient = \Drupal::httpClient();

  }

  public function checkPublicUrl($publicUrl) {
    // todo: figure out how to check public urls from docker. this works except for localhost, actually contacting the host machine. I tried using the 'host-net' bridge from the docker-compose.yml file? But I am not understanding apparently.
    // For now unless the field is empty, just return no errors.
    if (!trim($publicUrl)) {
      return 'Please enter a value for IIIF Public Url.';
    }
    return false;
//    if (trim($publicUrl)) {
//      try {
//        /* @var Client $this ->httpClient */
//        $response = $this->httpClient
//          ->head($publicUrl);
//      } catch (ConnectException $exception) {
//        error_log(var_export('IIIF Url Validate: Public 1', true));
//        $responseMessage = $exception->getMessage();
//        return $responseMessage;
//      } catch (ClientException $exception) {
//        error_log(var_export('IIIF Url Validate: Public 2', true));
//        $responseMessage = $exception->getMessage();
//        return $responseMessage;
//      } catch (ServerException $exception) {
//        error_log(var_export('IIIF Url Validate: Public 3', true));
//        $responseMessage = $exception->getMessage();
//        return $responseMessage;
//      }
//    } else {
//      return 'Please enter a value for IIIF Public Url.';
//    }
  }

  public function checkInternalUrl($internalUrl) {
    if (trim($internalUrl)) {
      try {
        /* @var Client $this ->httpClient */
        $response = $this->httpClient
          ->head($internalUrl);
      } catch (ConnectException $exception) {
        error_log(var_export('IIIF Url Validate: Internal 1', true));
        $responseMessage = $exception->getMessage();
        return $responseMessage;
      } catch (ClientException $exception) {
        error_log(var_export('IIIF Url Validate: Internal 2', true));
        $responseMessage = $exception->getMessage();
        return $responseMessage;
      } catch (ServerException $exception) {
        error_log(var_export('IIIF Url Validate: Internal 3', true));
        $responseMessage = $exception->getMessage();
        return $responseMessage;
      }
    } else {
      return 'Please enter a value for IIIF Internal Url.';
    }
  }
}