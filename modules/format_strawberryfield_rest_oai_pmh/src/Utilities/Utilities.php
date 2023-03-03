<?php

namespace Drupal\format_strawberryfield_rest_oai_pmh\Utilities;


class Utilities {

  /**
   * Takes an array and returns a csv string.
   *
   * @param $input
   * @param $delimiter
   * @param $enclosure
   *
   * @return string
   */
  public static function  str_putcsv($input, $delimiter = ',', $enclosure = '"'): string {
        $fp = fopen('php://temp', 'r+b');
        fputcsv($fp, $input, $delimiter, $enclosure);
        rewind($fp);
        $data = rtrim(stream_get_contents($fp), "\n");
        fclose($fp);
        return $data;
    }

}
