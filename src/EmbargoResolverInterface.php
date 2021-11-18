<?php


namespace Drupal\format_strawberryfield;

/**
 * Embargo And Inheritance Resolver Interface
 *
 * @ingroup format_strawberryfield
 */
interface EmbargoResolverInterface {

  /**
   * Checks if we can bypass embargo.
   *    If not possible we return an array with more info
   *
   * @param array $jsondata
   *
   * @return array
   *    Returns array
   *    with [(bool) embargoed, $date|FALSE, (bool) IP is enforced]
   *
   */
  public function embargoInfo(string $uuid, array $jsondata);

}