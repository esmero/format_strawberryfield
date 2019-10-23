<?php

namespace Drupal\format_strawberryfield\Tools;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Class IiifUrlValidator.
 *
 * @package Drupal\format_strawberryfield\Tools
 */
class IiifUrlValidator {

  use StringTranslationTrait;

  /**
   * Const for Internal IIIF URL types.
   */
  const IIIF_INTERNAL_URL_TYPE = 'internal';

  /**
   * Const for EXTERNAL IIIF URL types.
   */
  const IIIF_EXTERNAL_URL_TYPE = 'external';

  /**
   * The HTTP Client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * IiifUrlValidator constructor.
   */
  public function __construct() {
    $this->httpClient = $this->httpClient = \Drupal::httpClient();

  }

  /**
   * Validates user-entered IIIF URLs.
   *
   * @param string $url
   *   A Cantaloupe IIIF URL endpoint.
   * @param string $type
   *   The Type of URL. Use CONST. defined in this class.
   *
   * @return bool
   *   FALSE if errored.
   */
  public function checkUrl(
    string $url,
    $type = IiifUrlValidator::IIIF_INTERNAL_URL_TYPE
  ) {

    if (!filter_var(trim($url), FILTER_VALIDATE_URL)) {
      return t('Please enter a value for IIIF Public Url.');
    };

    $localUrls = ['localhost', '127.0.0.1', '0.0.0.0'];

    // Really no need to add to the constructor.
    // @TODO maybe nothing should go into the constructor.
    // This is a helper class and i feel all should be simply static.
    $request_stack = \Drupal::requestStack();
    $current_drupal_request_url = $request_stack->getCurrentRequest()
      ->getSchemeAndHttpHost();

    // No need to check if valid, there will be always a URL here.
    $drupal_parsed = parse_url($current_drupal_request_url);
    $drupal_url = $drupal_parsed['scheme'] . '://' . $drupal_parsed['host'];

    $iiifparsedurl = parse_url(trim($url));
    $iiif_url = $iiifparsedurl['scheme'] . '://' . $iiifparsedurl['host'];

    if (($type == self::IIIF_EXTERNAL_URL_TYPE) &&
      ($drupal_url == $iiif_url) &&
      in_array($iiifparsedurl['host'], $localUrls)
    ) {
      // it's External, localhost, same as Drupal, return with no errors.
      return TRUE;
    }

    return $this->isValid($url);
  }

  /**
   * Queries URL via HEAD over http.
   *
   * @param string $url
   *   A Cantaloupe IIIF url.
   *
   * @return bool
   *   TRUE if we could access the URL. FALSE if exception or not a Cantaloupe.
   */
  private function isValid(string $url) {
    $valid = FALSE;
    try {
      $response = $this->httpClient->head($url);
    }
    catch (\Exception $e) {
      \Drupal::logger('format_strawberryfield')->error(
        "We could not contact the IIIF Server at @url with error: @e",
        [
          "@url" => $url,
          "@e" => $e->getMessage(),
        ]
          );
      return $valid;
    }
    $cantaloupe_header = $response->getHeader('X-Powered-By');
    if (isset($cantaloupe_header[0]) && substr(
        $cantaloupe_header[0],
        0,
        strlen('Cantaloupe')
      ) === 'Cantaloupe') {
      $valid = TRUE;
    }
    return $valid;
  }

}
