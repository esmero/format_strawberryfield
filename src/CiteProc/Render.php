<?php

namespace Drupal\format_strawberryfield\CiteProc;

use Drupal\Component\Utility\Html;
use Seboettg\CiteProc\CiteProc;
use Seboettg\CiteProc\Exception\CiteProcException;

class Render {

  protected $cslLocalesPath = DRUPAL_ROOT . '/libraries/citation-style-language/locales';

  protected $cslStylesPath = DRUPAL_ROOT . '/libraries/citation-style-language/styles';

  /**
   * Parses CSS string into an array for processing.
   *
   * @param string $css
   * @return array
   */
  private function parseCSS(string $css): array {
    preg_match_all( '/(?ims)([a-z0-9\s\.\:#_\-@,]+)\{([^\}]*)\}/', $css, $arr);
    $result = array();
    foreach ($arr[0] as $i => $x){
      $selector = trim($arr[1][$i]);
      $rules = explode(';', trim($arr[2][$i]));
      $rules_arr = array();
      foreach ($rules as $strRule){
        if (!empty($strRule)){
          $rule = explode(":", $strRule);
          $rules_arr[trim($rule[0])] = trim($rule[1]);
        }
      }

      $selectors = explode(',', trim($selector));
      foreach ($selectors as $strSel){
        $result[$strSel] = $rules_arr;
      }
    }
    return $result;
  }

  /**
   * Loads xml formatted CSL stylesheet of a given stylesheet name, e.g. "american-physiological-society" for
   * apa style.
   *
   * See in styles folder (which is included as git submodule) for all available style sheets
   *
   * @param string $locale
   * @param array $styleNames e.g. "american-physiological-society" for apa
   * @param array $jsonData
   * @return string
   * @throws CiteProcException
   */
  public function bibliography(string $locale, array $styleNames, array $jsonData): string {

    try {
      $citation_locales_file = $this->cslLocalesPath . '/locales.json';
      $citation_locales_file_contents = file_get_contents($citation_locales_file);
      $citation_locales = json_decode($citation_locales_file_contents, true);

      $available_locale = false;
      $len_of_available_locale = strlen($locale);
      $locale_hyphen = strpos($locale, '-') == 2;
      $primary_dialects = $citation_locales['primary-dialects'];
      $language_names = $citation_locales['language-names'];
      if ( $len_of_available_locale == 2) {
        $normalized_locale = strtolower($locale);
        $available_locale = array_key_exists($normalized_locale, $primary_dialects) ? $primary_dialects[$normalized_locale] : $locale;
      }
      elseif ($len_of_available_locale == 5 && $locale_hyphen) {
        $normalized_locale = strtolower(substr($locale, 0, 2)) . '-' . strtoupper(substr($locale, 3, 2));
        $available_locale = array_key_exists($normalized_locale, $language_names) ? $normalized_locale : $locale;
      }

      $rendered_bibliography = '';
      $citation_style_directory = $this->cslStylesPath;
      $select = '
        <label for="citation-styles">Style:</label>
        <select name="citation-style" class="citation-style-selector">
      ';

      $style_iterator = 0;
      foreach ($styleNames as $selected_style) {
        $style = StyleSheet::loadStyleSheet($selected_style);
        if ($available_locale) {
          $citeProc = new CiteProc($style, $available_locale);
        }
        else {
          $citeProc = new CiteProc($style);
        }
        $bibliography = $citeProc->render($jsonData, "bibliography");
        $cssStyles = $citeProc->renderCssStyles();
        $parsedStyle = $this->parseCSS($cssStyles);
        $processed_bibliography = $bibliography;
        foreach ($parsedStyle as $css_prop => $css_statements) {
          $css_selector = ltrim($css_prop, '.');
          $css_selector_len = strlen($css_selector);
          $pos = strpos($processed_bibliography,$css_selector);
          if ($pos !== false) {
            $inline_rule = ' style="';
            foreach($css_statements as $css_property => $css_value) {
              $inline_rule .= $css_property . ':' . $css_value . ';';
            }
            $inline_rule .= '"';
            $start = 0;
            while (($inline_pos = strpos(($processed_bibliography),$css_selector,$start)) !== false) {
              $processed_bibliography = substr_replace($processed_bibliography, $inline_rule, $inline_pos + $css_selector_len + 1, 0);
              $start = $inline_pos + 1;
            }
          }
        }
        if ($style_iterator > 0) {
          $copyId = Html::getUniqueId('clipboard-copy');
          $processed_bibliography = '<div id="' . $copyId . '" class="hidden csl-bib-body-container '. $selected_style . '">' . $processed_bibliography . '</div>';
        }
        else {
          $copyId = Html::getUniqueId('clipboard-copy');
          $processed_bibliography = '<div id="' . $copyId . '" class="csl-bib-body-container '. $selected_style . '">' . $processed_bibliography . '</div>';
        }
        $rendered_bibliography .= $processed_bibliography;
        $style_file = $citation_style_directory . '/' . $selected_style . '.csl';
        $style_xml = simplexml_load_file($style_file);
        $style_title = $style_xml->info->title->__toString();
        $select .= '<option value="' . $selected_style . '">' . $style_title . '</option>';
        ++$style_iterator;
      }
    } catch (Exception $e) {
      echo $e->getMessage();
    }
    $select .= '
      </select>';
    $result = $select . $rendered_bibliography;
    return $result;
  }
}
