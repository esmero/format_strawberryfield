<?php

namespace Drupal\format_strawberryfield_views\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * AJAX command that sets the browser URL using the History State
 *
 * This command is implemented in Drupal.AjaxCommands.prototype.SbfSetBrowserUrl
 * in js/modal-exposed-form-ajax.js
 */
class SbfSetBrowserUrl implements CommandInterface {

  /**
   * The URL to be set in the browser.
   *
   * @var string
   */
  protected $url;

  /**
   * Constructs a new command instance.
   *
   * @param string $url
   *   The URL to be set in the browser
   */
  public function __construct(string $url) {
    $this->url = $url;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'SbfSetBrowserUrl',
      'url' => $this->url,
    ];
  }

}
