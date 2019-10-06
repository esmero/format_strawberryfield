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

  public function checkInternalUrl($internalUrl) {
    try {
      /* @var Client $this->httpClient  */
      $response = $this->httpClient
        ->head($internalUrl);
    }
    catch(ConnectException $exception) {
      $responseMessage = $exception->getMessage();
      return $responseMessage;
    }
    catch(ClientException $exception) {
      $responseMessage = $exception->getMessage();
      return $responseMessage;
    }
    catch (ServerException $exception) {
      $responseMessage = $exception->getMessage();
      return $responseMessage;
    }
  }

  public function checkPublicUrl($publicUrl) {
    // todo: figure out how to check public urls from docker.
    // just return no errors for now.
    return false;
  }


}