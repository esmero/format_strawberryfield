<?php

namespace Drupal\format_strawberryfield\Entity\Controller;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity;
use Symfony\Component\HttpFoundation\File\MimeType\ExtensionGuesser;

/**
 * Provides a list controller for the MetadataDisplay entity.
 *
 * @ingroup format_strawberryfield
 */
class MetadataAPIConfigEntityListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   *
   * We override ::render() so that we can add our own content above the table.
   * parent::render() is where EntityListBuilder creates the table using our
   * buildHeader() and buildRow() implementations.
   */
  public function render() {
    $build['description'] = [
      '#markup' => $this->t(
        'Strawberry Field Formatter Module implements Metadata API Endpoints and uses Views and Metadata Display Entities as a configuration option to provide dynamic APIs based on Metadata present in each Node that contains a Strawberryfield type of field (JSON). You can manage those Metadata Display entities on the <a href="@adminlink">Metadata Display Content Page</a>.',
        [
          '@adminlink' => \Drupal::urlGenerator()
            ->generateFromRoute('entity.metadataapi_entity.collection'),
        ]
      ),
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
    $header['id'] = $this->t('Metadata API Endpoint Config ID');
    $header['label'] = $this->t('Label');
    $header['url'] = $this->t('Example URL API Entry point');
    $header['active'] = $this->t('Is active ?');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity */
    // Build a demo URL so people can see it working
    $url = $this->getDemoUrlForItem($entity) ?? $this->t('No Content matches this Endpoint Enabled Bundles Configuration yet. Please create one to see a Demo link here');
    $row['id'] = $entity->id();
    $row['label'] = $entity->label();
    $row['url'] = $url ? [
      'data' => [
        '#markup' => $this->t(
        '<a href="@demolink">@demolink</a>.',
        [
          '@demolink' => $url,
        ]
        ),
      ],
    ] : $url;

    $row['active'] = $entity->isActive() ? $this->t('Yes') : $this->t('No');

    return $row + parent::buildRow($entity);
  }

  /**
   * Generates a valid Example URL given a Node UUID.
   *
   * @param \Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity $entity
   *   The Exposed Metadata Entity we are processing the URL for.
   * @return \Drupal\Core\GeneratedUrl|null|string
   *   A Drupal URL if we have enough arguments or NULL if not.
   */
  private function getDemoUrlForItem(MetadataAPIConfigEntity $entity) {
    $url = NULL;
    $extension = NULL;
    return $url;
    // @TODO implement this once we have the API config done.
    try {
      $metadata_display_entity = $entity->getMetadataDisplayEntity();
      $responsetypefield = $metadata_display_entity ? $metadata_display_entity->get('mimetype') : NULL;
      $responsetype = $responsetypefield ? $responsetypefield->first()->getValue() : NULL;
      // We can have a LogicException or a Data One, both extend different
      // classes, so better catch any.
    }
    catch (\Exception $exception) {
      $this->messenger()->addError(
        'For Metadata endpoint @metadataexposed, either @metadatadisplay does not exist or has no mimetype Drupal field setup or no value for it. Please check that @metadatadisplay still exists, the entity has that field and there is a default Output Format value for it. Error message is @e',
        [
          '@metadataexposed' => $entity->label(),
          '@metadatadisplay' => $entity->getMetadataDisplayEntity()->label(),
          '@e' => $exception->getMessage(),
        ]
          );
      return $url;
    }

    $responsetype = !empty($responsetype['value']) ? $responsetype['value'] : 'text/html';

    // Guess extension based on mime,
    // \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeExtensionGuesser
    // has no application/ld+json even if recent
    // And Drupal provides no mime to extension.
    // @TODO maybe we could have https://github.com/FileEye/MimeMap as
    // a dependency in SBF. That way the next hack would not needed.
    if ($responsetype == 'application/ld+json') {
      $extension = 'jsonld';
    }
    else {
      $guesser = ExtensionGuesser::getInstance();
      $extension = $guesser->guess($responsetype);
    }

    $filename = !empty($extension) ? 'default.' . $extension : 'default.html';

    $url = \Drupal::urlGenerator()
      ->generateFromRoute(
        'format_strawberryfield.metadatadisplay_caster',
        [
          'node' => $uuid,
          'metadataexposeconfig_entity' => $entity->id(),
          'format' => $filename,
        ]
      );

    return $url;
  }

}
