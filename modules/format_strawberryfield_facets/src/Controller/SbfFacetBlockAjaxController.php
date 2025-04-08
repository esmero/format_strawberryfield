<?php

namespace Drupal\format_strawberryfield_facets\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\SettingsCommand;
use Symfony\Component\HttpFoundation\Request;
use Drupal\facets\Controller\FacetBlockAjaxController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    // The parent method provides the actual block rendering of the facet.
    // $response = parent::ajaxFacetBlockView($request); but if we call it
    // we will have to render/process/calculate every block twice.
    // So i will attempt (against my own good mental health)
    // so copy/mimic instead of parent -> return -> re-do
    // Changes are Facet has not been patched upstream for missing session */
    // @TODO: remove in 1.6.0 once https://www.drupal.org/files/issues/2024-08-10/3052574-session-not-set-warning-251.patch
    // and https://www.drupal.org/files/issues/2024-08-20/facets-add-missing-ajax_page_state-3466566-2.0.8.patch
    // comes in.

    $response = new AjaxResponse();

    // Rebuild the request and the current path, needed for facets.
    $path = $request->request->get('facet_link');
    $facets_blocks = $request->request->all()['facets_blocks'] ?? [];
    // Make sure we are not updating blocks multiple times.
    $facets_blocks = array_unique($facets_blocks);

    $host = $request->getSchemeAndHttpHost();
    $base_url = strpos($path, $host) ? $host . base_path() : base_path();
    $path = preg_replace('/^' . str_replace('/', '\/', $base_url) . '/', '/', $path);

    if (empty($path) || empty($facets_blocks)) {
      throw new NotFoundHttpException('No facet link or facet blocks found.');
    }

    $new_request = Request::create($path);
    if ($session = $request->getSession()) {
      $new_request->setSession($session);
    }
    $request_stack = new RequestStack();

    // Add ajax_page_state to the new request if set.
    if ($request->request->has('ajax_page_state')) {
      $new_request->request->set('ajax_page_state', $request->request->all('ajax_page_state'));
    }
    elseif ($request->query->has('ajax_page_state')) {
      $new_request->request->set('ajax_page_state', $request->query->all('ajax_page_state'));
    }

    $processed = $this->pathProcessor->processInbound($path, $new_request);
    $processed_request = Request::create($processed);

    $this->currentPath->setPath($processed_request->getPathInfo());
    $request->attributes->add($this->router->matchRequest($new_request));
    $this->currentRouteMatch->resetRouteMatch();
    $request_stack->push($new_request);

    $container = \Drupal::getContainer();
    $container->set('request_stack', $request_stack);
    $active_facet = $request->request->get('active_facet');
    $drupal_settings = [];
    $libs = [];
    // Build the facets blocks found for the current request and update.
    foreach ($facets_blocks as $block_id => $block_selector) {
      $block_entity = $this->storage->load($block_id);

      if ($block_entity) {
        // We only pre-render the lazyBuilder so deeper settings/libraries can be "seen"
        // But the ReplaceCommmand internal Attachment extractor.
        $render_array = \Drupal\block\BlockViewBuilder::lazyBuilder($block_id, 'full');
        if (isset($render_array['#block'])) {
          $render_array = \Drupal\block\BlockViewBuilder::preRender($render_array);
          // #block will be used by the preRender
          // But we need to also remove the pre_render callbacks so renderInIsolate
          // Does not try again to call it making it fail/ or forcing us to pass
          // the original/pre-build like the parent method does. (twice the processing)
          unset($render_array['#pre_render']);
        }
        $response->addCommand(new ReplaceCommand($block_selector, $render_array));
      }
    }

    $response->addCommand(new InvokeCommand('[data-block-plugin-id="' . $active_facet . '"]', 'addClass', ['facet-active']));

    // Update filter summary block.
    $update_summary_block = $request->request->get('update_summary_block');
    if ($update_summary_block) {
      $facet_summary_block_id = $request->request->get('facet_summary_block_id');
      $facet_summary_wrapper_id = $request->request->get('facet_summary_wrapper_id');
      $facet_summary_block_id = str_replace('-', '_', $facet_summary_block_id);

      if ($facet_summary_block_id) {
        $block_entity = $this->storage->load($facet_summary_block_id);
        $block_view = $this->entityTypeManager
          ->getViewBuilder('block')
          ->view($block_entity);
        $response->addCommand(new ReplaceCommand('[data-drupal-facets-summary-id=' . $facet_summary_wrapper_id . ']', $block_view));
      }
    }
    return $response;
  }
}
