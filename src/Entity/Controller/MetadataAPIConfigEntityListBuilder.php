<?php

namespace Drupal\format_strawberryfield\Entity\Controller;

use Drupal\Core\Url;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity;

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
    $url = $this->getDemoUrl($entity) ?? $this->t('API URL can not be generated yet');
    $row['id'] = $entity->id();
    $row['label'] = $entity->label();
    $row['url'] = $url ? [
      'data' => [
        '#markup' => $this->t(
        '@demolink',
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
  private function getDemoUrl(MetadataAPIConfigEntity $entity) {
    $url = NULL;
    $extension = NULL;
    $parameters = $entity->getConfiguration()['openAPI'];
    foreach ($parameters as $param) {
      if ($param['param']['in'] ?? NULL === 'path') {
        $pathargument = $param['param']['name'];
      }
      $schema_parameters[] = $param['param'];
    }

    $url = Url::fromRoute(
        'format_strawberryfield.metadataapi_caster_base',
        [
          'metadataapiconfig_entity' => $entity->id(),
          'patharg' => '{' . $pathargument . '}',
        ],
      ['absolute' => true]
      )->toString();

    return $url;
  }

}
