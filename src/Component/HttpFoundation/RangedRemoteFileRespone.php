<?php


namespace Drupal\format_strawberryfield\Component\HttpFoundation;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;

class RangedRemoteFileRespone extends BinaryFileResponse {

  protected static $trustXSendfileTypeHeader = false;

  /**
   * @var File
   */
  protected $file;
  protected $offset = 0;
  protected $end = 0;
  protected $maxlen = -1;
  protected $deleteFileAfterSend = false;

  /**
   * {@inheritdoc}
   */
  public function prepare(Request $request): static
  {
    if (!$this->headers->has('Content-Type')) {
      $this->headers->set('Content-Type', $this->file->getMimeType() ?: 'application/octet-stream');
    }

    if ('HTTP/1.0' !== $request->server->get('SERVER_PROTOCOL')) {
      $this->setProtocolVersion('1.1');
    }

    $this->ensureIEOverSSLCompatibility($request);

    $this->offset = 0;
    $this->maxlen = -1;

    if (false === $fileSize = $this->file->getSize()) {
      return $this;
    }
    $this->headers->set('Content-Length', $fileSize);

    if (!$this->headers->has('Accept-Ranges')) {
      // Only accept ranges on safe HTTP methods
      $this->headers->set('Accept-Ranges', $request->isMethodSafe(false) ? 'bytes' : 'none');
    }

    if (self::$trustXSendfileTypeHeader && $request->headers->has('X-Sendfile-Type')) {
      // Use X-Sendfile, do not send any content.
      $type = $request->headers->get('X-Sendfile-Type');
      $path = $this->file->getRealPath();
      // Fall back to scheme://path for stream wrapped locations.
      if (false === $path) {
        $path = $this->file->getPathname();
      }
      if ('x-accel-redirect' === strtolower($type)) {
        // Do X-Accel-Mapping substitutions.
        // @link https://www.nginx.com/resources/wiki/start/topics/examples/x-accel/#x-accel-redirect
        foreach (explode(',', $request->headers->get('X-Accel-Mapping', '')) as $mapping) {
          $mapping = explode('=', $mapping ?? '', 2);

          if (2 === \count($mapping)) {
            $pathPrefix = trim($mapping[0] ?? '');
            $location = trim($mapping[1] ?? '');

            if (substr($path, 0, \strlen($pathPrefix)) === $pathPrefix) {
              $path = $location.substr($path, \strlen($pathPrefix));
              // Only set X-Accel-Redirect header if a valid URI can be produced
              // as nginx does not serve arbitrary file paths.
              $this->headers->set($type, $path);
              $this->maxlen = 0;
              break;
            }
          }
        }
      } else {
        $this->headers->set($type, $path);
        $this->maxlen = 0;
      }
    } elseif ($request->headers->has('Range')) {
      // Process the range headers.
      if (!$request->headers->has('If-Range') || $this->hasValidIfRangeHeader($request->headers->get('If-Range'))) {
        $range = $request->headers->get('Range') ?? '';

        [$start, $end] = explode('-', substr($range, 6), 2) + [0];

        $end = ('' === $end) ? $fileSize - 1 : (int) $end;
        $this->end = $end;
        if ('' === $start) {
          $start = $fileSize - $end;
          $end = $fileSize - 1;
        } else {
          $start = (int) $start;
        }

        if ($start <= $end) {
          if ($start < 0 || $end > $fileSize - 1) {
            $this->setStatusCode(416);
            $this->headers->set('Content-Range', sprintf('bytes */%s', $fileSize));
          } elseif (0 !== $start || $end !== $fileSize - 1) {
            $this->maxlen = $end < $fileSize ? $end - $start + 1 : -1;
            $this->offset = $start;

            $this->setStatusCode(206);
            $this->headers->set('Content-Range', sprintf('bytes %s-%s/%s', $start, $end, $fileSize));
            $this->headers->set('Content-Length', $end - $start + 1);
          }
        }
      }
    }

    return $this;
  }

  private function hasValidIfRangeHeader($header)
  {
    if ($this->getEtag() === $header) {
      return true;
    }

    if (null === $lastModified = $this->getLastModified()) {
      return false;
    }

    return $lastModified->format('D, d M Y H:i:s').' GMT' === $header;
  }

  /**
   * Sends the file.
   *
   * {@inheritdoc}
   */
  public function sendContent(): static
  {
    if (!$this->isSuccessful()) {
      return parent::sendContent();
    }

    if (0 === $this->maxlen) {
      return $this;
    }

    $out = fopen('php://output', 'wb');

    // Pass context needed for S3 byte range
    // Remember Kids. This is the right range syntax.
    // @See https://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.35
    $context = stream_context_create([
      's3' => [
        'Range' => "bytes=" . $this->offset . "-" . $this->end,
        'seekable' => FALSE
      ]
    ]);
    $file = fopen($this->file->getPathname(), 'r', false, $context);
    stream_copy_to_stream($file, $out, $this->maxlen);

    fclose($out);
    fclose($file);

    if ($this->deleteFileAfterSend && file_exists($this->file->getPathname())) {
      unlink($this->file->getPathname());
    }

    return $this;
  }

}
