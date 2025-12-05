<?php

namespace Drupal\format_strawberryfield\Breadcrumb;

use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\format_strawberryfield\Entity\MetadataDisplayEntity;
use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;

class MetadataDisplayBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;
  /**
   * @inheritDoc
   */
  public function applies(RouteMatchInterface $route_match) {
    $metadata_display_entity = $route_match->getParameter('metadatadisplay_entity');
    return $metadata_display_entity instanceof MetadataDisplayEntity;
  }

  /**
   * @inheritDoc
   */
  public function build(RouteMatchInterface $route_match) {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $metadata_display_entity = $route_match->getParameter('metadatadisplay_entity');
    $links[] = Link::createFromRoute($this->t('Home'), '<front>');

    $links[] = Link::createFromRoute($this->t('Metadata Display List'), 'entity.metadatadisplay_entity.collection');
    if ($metadata_display_entity instanceof MetadataDisplayEntity) {
      $links[] = Link::createFromRoute($metadata_display_entity->label(), 'entity.metadatadisplay_entity.canonical', ['metadatadisplay_entity' =>$metadata_display_entity->id()]);
    }
    return $breadcrumb->setLinks($links);
  }

}