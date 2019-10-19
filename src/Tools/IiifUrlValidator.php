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

  /**
   * Checks user-entered URLs for errors. Skips local hosts. Returns errors, or false if clear.
   *
   * @param $url
   * @param $type
   * @return bool|\Drupal\Core\StringTranslation\TranslatableMarkup
   */
  public function checkUrl($url, $type) {
    if (!$url) { return t('Please enter a value for IIIF Public Url.'); };

    // check for localhost.
    $localUrls = ['localhost', '127.0.0.1', '0.0.0.0'];
    $host = trim(parse_url($url, PHP_URL_HOST));
    if (in_array($host, $localUrls)) {
      // it's local so just return with no errors.
      return false;
    }

    $title = $type === 'iiif_base_url_internal' ? 'Internal' : 'Public';
    return $this->isInvalid($url) === true ? t('You entered an invalid '.$title.' IIIF Url.') :  false;
  }

  /**
   * Queries URL over http; if no exceptions, returns false.
   *
   * @param $url
   * @return bool
   */
  private function isInvalid($url) {
    try {
      /* @var Client $this ->httpClient */
      $response = $this->httpClient
        ->head($url);
    } catch (\Exception $e) {

      \Drupal::logger('format_strawberryfield')->error($e);
      return true;
    }

    return false;
  }

}