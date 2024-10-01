<?php

namespace Drupal\format_strawberryfield\Entity\Controller;

use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\PathItem;
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
    $header['url'] = $this->t('Open API Config');
    $header['active'] = $this->t('Is active ?');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /* @var $entity \Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity */
    // Build a demo URL so people can see it working
    $json = $this->getDemoAPI($entity) ?? $this->t('API structure can not be generated yet');
    $row['id'] = $entity->id();
    $row['label'] = $entity->label();
    $row['url'] = $json ? [
      'data' => [
        '#markup' => $this->t('<pre>@demolink</pre>',
        [
          '@demolink' => trim($json),
        ]
        ),
      ],
    ] : $json;

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
  private function getDemoAPI(MetadataAPIConfigEntity $entity) {
    $json = NULL;

    //@TODO this can be an entity level method.
    $parameters = $entity->getConfiguration()['openAPI'];
    $schema_parameters = [];
    if (!is_array($parameters)) {
      $parameters = [];
    }
    $openAPI = new OpenApi(
      [
        'openapi' => '3.0.2',
        'info'    => [
          'title'   => 'Test API',
          'version' => '1.0.0',
        ],
        'paths'   => [],
      ]
    );

    $path = Url::fromRoute(
      'format_strawberryfield.metadataapi_caster_base',
      [
        'metadataapiconfig_entity' => $entity->id(),
        'patharg' => 'empty',
      ],
      ['absolute' => true]
    )->toString();
    $path = dirname($path, 1);
    $pathargument = '';
    foreach ($parameters as $param) {
      // @TODO For now we need to make sure there IS a single path argument
      // In the config
      if (isset($param['param']['in']) && $param['param']['in'] === 'path') {
        $pathargument = '{' . $param['param']['name'] . '}';
      }
      $schema_parameters[] = $param['param'];
    }
    $path = $path . '/' . $pathargument;
    $PathItem = new PathItem(['get' => ['parameters' => $schema_parameters]]);

    $openAPI->paths->addPath($path, $PathItem);

    $json = \cebe\openapi\Writer::writeToJson($openAPI, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    return $json;
  }

}
