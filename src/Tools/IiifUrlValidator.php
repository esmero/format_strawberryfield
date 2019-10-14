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
    // check if there's a value entered
    if (trim($publicUrl)) {
      $isLocalhost = strpos($publicUrl, 'localhost');
      if ($isLocalhost !== false) {
        // the url is localhost. so don't try to validate and just return no errors.
        return false;
      } else {
        // it's not localhost so actually test it
        if ($this->isInvalid($publicUrl)) {
          return "You entered an invalid IIIF Public Url.";
        }
      }
    } else {
      return 'Please enter a value for IIIF Public Url.';
    }
  }

  public function checkInternalUrl($internalUrl) {
    // check if there's a value entered
    if (trim($internalUrl)) {
      if ($this->isInvalid($internalUrl)) {
        return "You entered an invalid IIIF Internal Url.";
      }
    } else {
      return 'Please enter a value for IIIF Internal Url.';
    }
  }

  private function isInvalid($url) {
    try {
      /* @var Client $this ->httpClient */
      $response = $this->httpClient
        ->head($url);
    } catch (ConnectException $exception) {
      error_log(var_export($exception->getMessage(), true));
      return true;
    } catch (ClientException $exception) {
      error_log(var_export($exception->getMessage(), true));
      return true;
    } catch (ServerException $exception) {
      error_log(var_export($exception->getMessage(), true));
      return true;
    }
    return false;
  }

}