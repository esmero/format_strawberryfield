<?php

namespace Drupal\format_strawberryfield;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for the View Mode resolver service.
 *
 * @ingroup format_strawberryfield
 */
interface ViewModeResolverInterface {

  /**
   * Gets the view mode for a given entity based on eligibility and priority.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   *
   * @return string
   *   View mode machine name.
   */
  public function get(ContentEntityInterface $entity);

  /**
   * Gets an array of eligible view modes for a given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The content entity.
   *
   * @return array
   *   Array of view modes with the following structure:
   *    - jsontype: A string defining the chosen type, Book, Media, etc.
   *    - view_mode: View mode machine name
   *    - active: Boolean that marks if a view mode is active.
   *    - weight: The relative weight
   */
  public function getCandidates(ContentEntityInterface $entity);

}
