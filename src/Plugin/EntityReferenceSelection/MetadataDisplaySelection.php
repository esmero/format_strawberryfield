<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/28/18
 * Time: 2:04 PM
 */

namespace Drupal\format_strawberryfield\Plugin\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Form\FormStateInterface;

/**
 * Metadata Display plugin implementation of the Entity Reference Selection plugin.
 *
 * @EntityReferenceSelection(
 *   id = "default:metadatadisplay",
 *   label = @Translation("Metadata Display Entity selection"),
 *   entity_types = {"metadatadisplay_entity"},
 *   group = "default",
 *   weight = 1
 * )
 */
class MetadataDisplaySelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state
  ) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Disable autocreate.
    $form['auto_create']['#access'] = FALSE;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function buildEntityQuery(
    $match = NULL,
    $match_operator = 'CONTAINS'
  ) {
    $query = parent::buildEntityQuery($match, $match_operator);
    // Only if it has Twig inside
    // This selection plugin allows a passed
    /*'#selection_settings' => [
      'filter' => [
        'mimetype' => 'application/xml'
      ]
    ],
    as part of the element that implements it. I can also be an array
     'mimetype' => ['application/xml','application/json']
    */
    $configuration = $this->getConfiguration();
    $query->condition('twig', 'NULL','IS NOT NULL');
    if ($configuration['filter']['mimetype'] ?? FALSE) {
      $configuration['filter']['mimetype'] = (array) $configuration['filter']['mimetype'];
      $query->condition('mimetype', $configuration['filter']['mimetype'],'IN');
    }
    return $query;
  }
}
