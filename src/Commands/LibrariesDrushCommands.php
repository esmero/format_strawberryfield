<?php

namespace Drupal\format_strawberryfield\Commands;

use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exceptions\UserAbortException;
use Drush\Exec\ExecTrait;
use Psr\Log\LogLevel;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Format Strawberryfield Drush commandfile.
 *
 */
class LibrariesDrushCommands extends DrushCommands {

  /**
   * Downloads libraries for citation formatter.
   *
   * @param string $libraryfilepath
   *    A file containing either a full JSON API data payload or just SBF JSON
   *   data.
   *
   * @throws \Exception if ingest is not possible
   *
   * @command archipelago:libraries-download
   * @aliases ap-libraries-download
   *
   * @usage archipelago:libraries-download download_url
   */

}
