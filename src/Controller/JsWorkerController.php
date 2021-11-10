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
    $response = new Response(
      'importScripts("https://cdn.jsdelivr.net/npm/replaywebpage@1.5.5/sw.js");'
    );
    // Alternative https://unpkg.com/replaywebpage@1.5.5/sw.js
    $response->headers->set('Content-Type', 'text/javascript');
    return $response;
  }

  /**
   * Serves 'statically' Index to avoid failure while worker is warming up.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function serveindex() {

    $index = <<<'EOD'
<!doctype html>
<html class="no-overflow">
<head>
<link rel="manifest" href="/webmanifest.json">
<link rel="icon" href="build/icon.png" type="image/png" />
<title>ReplayWeb.page</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<script src="./ui.js"></script>
</head>
<body>
<replay-app-main></replay-app-main>
</body>
</html>;
EOD;
    $response = new Response($index);
    $response->headers->set('Content-Type', 'text/html');
    return $response;
  }
}
