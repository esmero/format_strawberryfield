<?php

namespace Drupal\format_strawberryfield_facets\Controller;

use Drupal\Core\Ajax\SettingsCommand;
use Symfony\Component\HttpFoundation\Request;
use Drupal\facets\Controller\FacetBlockAjaxController;
use Symfony\Component\HttpFoundation\RequestStack;

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
    $response = parent::ajaxFacetBlockView($request);

    /* Start patching
    // Changes are Facet has not been patched upstream for missing session */
    // @TODO: remove in 1.6.0 once https://www.drupal.org/files/issues/2024-08-10/3052574-session-not-set-warning-251.patch
    // and https://www.drupal.org/files/issues/2024-08-20/facets-add-missing-ajax_page_state-3466566-2.0.8.patch
    // comes in.
    $path = $request->request->get('facet_link');
    $new_request = Request::create($path);
    $new_request->setSession($request->getSession());
    $request_stack = new RequestStack();

    $processed = $this->pathProcessor->processInbound($path, $new_request);
    $processed_request = Request::create($processed);
    $this->currentPath->setPath($processed_request->getPathInfo());
    $request->attributes->add($this->router->matchRequest($new_request));
    $this->currentRouteMatch->resetRouteMatch();
    // Add ajax_page_state to the new request if set.
    if ($request->request->has('ajax_page_state')) {
      $new_request->request->set('ajax_page_state', $request->request->all('ajax_page_state'));
    }
    elseif ($request->query->has('ajax_page_state')) {
     $new_request->query->set('ajax_page_state', $request->query->all('ajax_page_state'));
    }
    $request_stack->push($new_request);
    $container = \Drupal::getContainer();
    $container->set('request_stack', $request_stack);
    /* end patching */
    return $response;
  }
}
