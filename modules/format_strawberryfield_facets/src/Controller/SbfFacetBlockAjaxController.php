<?php

namespace Drupal\format_strawberryfield_facets\Controller;

use Drupal\Core\Ajax\SettingsCommand;
use Symfony\Component\HttpFoundation\Request;
use Drupal\facets\Controller\FacetBlockAjaxController;

/**
 * Defines a controller to load a facet via AJAX.
 */
class SbfFacetBlockAjaxController extends FacetBlockAjaxController {


  /**
   * Loads and renders the facet blocks via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the view was not found.
   */
  public function ajaxFacetBlockView(Request $request) {
    $response = parent::ajaxFacetBlockView($request);
    $facets_blocks = $request->request->all()['facets_blocks'] ?? [];
    // Build the facets blocks found for the current request and update.
    foreach ($facets_blocks as $block_id => $block_selector) {
      $block_entity = $this->storage->load($block_id);
      if ($block_entity) {
        // Damn Lazy builder!
        $render_array  = \Drupal\block\BlockViewBuilder::preRender(\Drupal\block\BlockViewBuilder::lazyBuilder($block_id, 'full'));
        if (isset($render_array['content'][0]['#attached']['drupalSettings'])) {
          $response->addCommand(new SettingsCommand(['facets' => []], TRUE), TRUE);
          $response->addCommand(new SettingsCommand(['facets' => $render_array['content'][0]['#attached']['drupalSettings']['facets'] ?? []], FALSE), TRUE);
        }
      }
      //$response->addCommand(new SettingsCommand(['facets' => []], TRUE), TRUE);
    }
    return $response;
  }

}
