<?php

namespace Drupal\format_strawberryfield\CiteProc;

//use Seboettg\CiteProc\StyleSheet;
use Seboettg\CiteProc\Exception\CiteProcException;

class StyleSheet extends \Seboettg\CiteProc\StyleSheet {
  /**
   * Loads xml formatted CSL stylesheet of a given stylesheet name, e.g. "american-physiological-society" for
   * apa style.
   *
   * See in styles folder (which is included as git submodule) for all available style sheets
   *
   * @param string $styleName e.g. "american-physiological-society" for apa
   * @return string
   * @throws CiteProcException
   */
  public static function loadStyleSheet(string $styleName): string
  {
    $stylesPath = self::vendorPath() . "/citation-style-language/styles";
    return self::readFileContentsOrThrowException("$stylesPath/$styleName.csl");
  }

  /**
   * Loads xml formatted locales of given language key
   *
   * @param string $langKey e.g. "en-US", or "de-CH"
   * @return string
   * @throws CiteProcException
   */
  public static function loadLocales(string $langKey): string
  {
    $localesPath = self::vendorPath()."/citation-style-language/locales";
    $localeFile = "$localesPath/locales-${langKey}.xml";
    if (file_exists($localeFile)) {
      return self::readFileContentsOrThrowException($localeFile);
    } else {
      $metadata = self::loadLocalesMetadata();
      if (!empty($metadata->{'primary-dialects'}->{$langKey})) {
        return self::readFileContentsOrThrowException(
          sprintf("%s/locales-%s.xml", $localesPath, $metadata->{'primary-dialects'}->{$langKey})
        );
      }
    }
    throw new CiteProcException("No Locale file found for $langKey");
  }

  /**
   * @throws CiteProcException
   */
  private static function readFileContentsOrThrowException($path): string
  {
    $fileContent = file_get_contents($path);
    if (false === $fileContent) {
      throw new CiteProcException("Couldn't read $path");
    }
    return $fileContent;
  }

  /**
   * @return mixed
   * @throws CiteProcException
   */
  public static function loadLocalesMetadata()
  {
    $localesMetadataPath = self::vendorPath() . "/citation-style-language/locales/locales.json";
    return json_decode(self::readFileContentsOrThrowException($localesMetadataPath));
  }

  /**
   * @return bool|string
   * @throws CiteProcException
   */
  private static function vendorPath()
  {
    $vendorPath = DRUPAL_ROOT . '/libraries';
    if (!is_dir($vendorPath)) {
      throw new CiteProcException('CiteProc dependencies not present. Please install by running "drush archipelago-download-citeproc-dependencies');
    }
    return $vendorPath;
  }
}
