<?php
namespace Drupal\format_strawberryfield\Entity\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;


  /**
   * Provides a list controller for the MetadataDisplay entity.
   *
   * @ingroup format_strawberryfield
   */
class MetadataDisplayListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * We override ::render() so that we can add our own content above the table.
   * parent::render() is where EntityListBuilder creates the table using our
   * buildHeader() and buildRow() implementations.
   */
  public function render() {
    $build['description'] = [
      '#markup' => $this->t('Strawberry Field Formatter Module implements a Metadata Display Entity. You can manage the fields on the <a href="@adminlink">Metadata Display admin page</a>.', array(
        '@adminlink' => \Drupal::urlGenerator()
          ->generateFromRoute('format_strawberryfield.metadatadisplay_settings'),
      )),
    ];

    $build += parent::render();
    return $build;
  }

  /**
   * {@inheritdoc}
   *
   * Building the header and content lines for the contact list.
   *
   * Calling the parent::buildHeader() adds a column for the possible actions
   * and inserts the 'edit' and 'delete' links as defined for the entity type.
   */
  public function buildHeader() {
    $header['id'] = $this->t('Metadata Display ID');
    $header['name'] = $this->t('Name');
    $header['last update'] = $this->t('Last update');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity */
    $row['id'] = $entity->id();
    $row['name'] = $entity->toLink();
    $row['last update'] = \Drupal::service('date.formatter')->format($entity->changed->value, 'custom', 'd/m/Y');
    return $row + parent::buildRow($entity);
  }

}