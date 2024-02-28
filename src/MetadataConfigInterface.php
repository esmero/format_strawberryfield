<?php
namespace Drupal\format_strawberryfield;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;

  /**
   * Provides an interface defining a Metadata Config entity.
   * @ingroup format_strawberryfield
   */
interface MetadataConfigInterface extends ConfigEntityInterface  {

  /* Generates a valid Example URL given a Node UUID.
   *
   * @param string $uuid
   * An UUID of a node configured for this Exposed endpoint.
   * @return \Drupal\Core\GeneratedUrl|null|string
   * A Drupal URL if we have enough arguments or NULL if not.
  */
  public function getUrlForItemFromNodeUUID(string $uuid, $absolute = FALSE);

}
