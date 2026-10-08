<?php

declare(strict_types=1);

namespace Drupal\format_strawberryfield_facets\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Theme\ThemePreprocess;

class FormatStrawberryFieldFacetsHooks {

  /**
   * Implements hook_preprocess_HOOK() for the Date Range Slider Facet item lists.
   */
  #[Hook('preprocess_facets_item_list__sbf_range_date_slider')]
  public function facetsItemListSbfRangeDateSlider(array &$variables): void {
    /** @var \Drupal\facets\Entity\FacetInterface $facet */
    $facet = $variables['facet'];
    if ($facet !== NULL) {
      if ($facet->get('show_title') === TRUE) {
        $variables['title'] = $facet->label();
      }
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  function theme() {
    return [
      'facets_item_list__sbf_range_date_slider' => [
        'variables' => [
          'facet' => NULL,
          'items' => [],
          'title' => '',
          'list_type' => 'ul',
          'wrapper_attributes' => [],
          'attributes' => [],
          'empty' => NULL,
          'context' => [],
        ],
        'initial preprocess' => ThemePreprocess::class . ':preprocessItemList',
      ],
    ];
  }




}
