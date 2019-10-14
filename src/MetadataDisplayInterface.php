<?php
namespace Drupal\format_strawberryfield;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use \Drupal\Core\Template\TwigEnvironment;

  /**
   * Provides an interface defining a Metadata Display entity.
   * @ingroup format_strawberryfield
   */
interface MetadataDisplayInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Gets the Twig Environment.
   *
   * @return TwigEnvironment
   */
  public function twigEnvironment();
}
