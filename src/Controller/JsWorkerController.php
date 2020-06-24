<?php

namespace Drupal\format_strawberryfield\Controller;


use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;


/**
 * A JS Worker Static JS controller.
 */
class JsWorkerController extends ControllerBase {


  /**
   * Serves 'statically' the replay web JS Worker file.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function servereplay() {
    $response = new Response('importScripts("https://unpkg.com/replaywebpage@1.0.0/sw.js");');
    $response->headers->set('Content-Type','text/javascript');
    return $response;
    }
}