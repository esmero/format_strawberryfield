<?php

namespace Drupal\format_strawberryfield_facets;

use Drupal\Core\Security\TrustedCallbackInterface;

class BlockDataAttributesRestorer implements TrustedCallbackInterface {

  public static function alterBlockWrapper(array $build) {
    // restores top level data attributes remove in Drupal 10.4
    // but needed to target via AJAX a refresh on facet/view changes
    // when empty
    if (isset($build['content']['#attributes'])) {
      $build['#attributes']  =  $build['#attributes'] + $build['content']['#attributes'];
    }
    return $build;
  }
  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['alterBlockWrapper'];
  }
}