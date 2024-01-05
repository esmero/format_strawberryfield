<?php

namespace Drupal\format_strawberryfield;

/**
 * Metadata Display Usage Interface.
 *
 * @ingroup format_strawberryfield
 */
Interface MetadataDisplayUsageServiceInterface {

  public function getRenderableUsage(MetadataDisplayInterface $metadatadisplay_entity):array;

  public function getUsage(MetadataDisplayInterface $metadatadisplay_entity):bool;


}
