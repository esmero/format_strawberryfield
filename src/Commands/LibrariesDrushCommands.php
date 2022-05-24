<?php

namespace Drupal\format_strawberryfield\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\Client;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Archiver\Tar;
use Drupal\Component\Datetime\TimeInterface;

/**
 * A Format Strawberryfield Drush commandfile.
 *
 */
class LibrariesDrushCommands extends DrushCommands {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The state key value store.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  protected $cslRoot = DRUPAL_ROOT . '/libraries/citation-style-language';

  protected $cslLocalesPath = DRUPAL_ROOT . '/libraries/citation-style-language/locales';

  protected $cslStylesPath = DRUPAL_ROOT . '/libraries/citation-style-language/styles';

  /**
   * Constructs the object.
   *
   * @param \GuzzleHttp\Client $http_client
   *   The HTTP client.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state key value store.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file handler.
   */
  public function __construct(
    Client $http_client,
    StateInterface $state,
    TimeInterface $time,
    FileSystemInterface $file_system
  ) {
    parent::__construct();
    $this->httpClient = $http_client;
    $this->state = $state;
    $this->time = $time;
    $this->fileSystem = $file_system;
  }

  /**
   * Downloads files from requested source to requested destination.
   *
   * @command archipelago:download
   * @aliases archipelago-download
   */
  public function downloadFiles($source, $destination) {
    $io = $this->io();

    $io->write("<info>$source</info>");

    $directory = dirname($destination);
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      $io->error("Could not create directory $directory.");
      return 1;
    }

    try {
      $response = $this->httpClient->get($source, ['sink' => $destination]);
    }
    catch (\Exception $exception) {
      $io->error($exception->getMessage());
      return 1;
    }

    $status_code = $response->getStatusCode();
    Response::$statusTexts[$status_code];
    $io->writeln(' [' . $status_code . ' ' . Response::$statusTexts[$status_code] . ']');

    $io->success("Files have been downloaded into $destination directory.");
    return $destination;
  }

  private function downloadExtractCiteProcDependencies() {
    $file_system = $this->fileSystem;
    $csl_root = $this->cslRoot;
    $csl_locales_path = $this->cslLocalesPath;
    $csl_styles_path = $this->cslStylesPath;

    if (is_dir($csl_root)) {
      $file_system->deleteRecursive($csl_root);
    }
    // @TODO Eventually will need to update to 1.1.0 and accommodate breaking changes.
    $csl_styles_url = 'https://github.com/citation-style-language/styles/tarball/v1.0.2';
    $csl_locales_url = 'https://github.com/citation-style-language/locales/tarball/v1.0.2';
    $tmp_dir = $file_system->getTempDirectory() ;
    $csl_styles_destination = $this->downloadFiles($csl_styles_url, $tmp_dir . '/citeproc-styles.tar.gz');
    $csl_locales_destination = $this->downloadFiles($csl_locales_url, $tmp_dir . '/citeproc-locales.tar.gz');
    $csl_styles_tar = new TAR($csl_styles_destination);
    $csl_locales_tar = new TAR($csl_locales_destination);

    $csl_locales_files_all = $csl_locales_tar->listContents();
    $csl_locales_files_extract = [];
    $csl_locales_path_remove = explode('/', $csl_locales_files_all[0],0 )[0];
    foreach ( $csl_locales_files_all as $csl_locales_file ) {
      if (str_ends_with( $csl_locales_file, '.xml' ) || str_ends_with( $csl_locales_file, '.json' )) {
        array_push( $csl_locales_files_extract, $csl_locales_file );
      }
    }
    $csl_locales_tar->extract($csl_root, $csl_locales_files_extract);
    rename($csl_root . '/' . $csl_locales_path_remove, $csl_locales_path);

    $csl_styles_files_all = $csl_styles_tar->listContents();
    $csl_styles_files_extract = [];
    $csl_styles_path_remove = explode('/', $csl_styles_files_all[0],0 )[0];
    foreach ( $csl_styles_files_all as $csl_styles_file ) {
      if (str_ends_with( $csl_styles_file, '.csl')) {
        array_push( $csl_styles_files_extract, $csl_styles_file );
      }
    }
    $csl_styles_tar->extract($csl_root, $csl_styles_files_extract);
    rename($csl_root . '/' . $csl_styles_path_remove, $csl_styles_path );
  }

  /**
   * Downloads citeproc-php and dependencies.
   *
   * @command archipelago:download-citeproc-dependencies
   * @aliases archipelago-download-citeproc-dependencies
   */
  public function installCiteProcDependencies() {
    $io = $this->io();
    $csl_root = $this->cslRoot;
    $csl_locales_path = $this->cslLocalesPath;
    $csl_styles_path = $this->cslStylesPath;
    if (is_dir($csl_root) || (is_dir($csl_locales_path) && is_dir($csl_styles_path))) {
      $io->note('Citeproc-php dependencies are present.');

      // @TODO Add documentation about the fact that re-downloading and
      // extracting via below option will delete what's existing in folders and
      // try again.
      $download_again = $io->choice('Download and extract libraries again?', ['Yes']);
      if ($download_again == 0) {
        $this->downloadExtractCiteProcDependencies();
      }
      return 1;
    }
    else {
      $io->note('Citeproc-php dependencies are not present. Downloading and extracting them now.');
      $this->downloadExtractCiteProcDependencies();
    }
  }
}
