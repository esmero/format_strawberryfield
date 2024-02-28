<?php

namespace Drupal\format_strawberryfield_rest_oai_pmh\Plugin\OaiMetadataMap;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\rest_oai_pmh\Plugin\OaiMetadataMapBase;
use Drupal\strawberryfield\Tools\StrawberryfieldJsonHelper;
use Drupal\views\Views;

/**
 * Mods using a View.
 *
 * @OaiMetadataMap(
 *  id = "format_strawberryfield_metadatadisplay_template_mods",
 *  label = @Translation("MODS Metadatadisplay Template"),
 *  metadata_format = "mods",
 *  template = {
 *    "type" = "module",
 *    "name" = "format_strawberryfield_rest_oai_pmh",
 *    "directory" = "templates",
 *    "file" = "mods"
 *  }
 * )
 */
class MetadatadisplayTemplateMapMods extends OaiMetadataMapBase {

  /**
   * Provides information on the metadata format.
   *
   * @return string[]
   *   The metadata format specification.
   *
   */
  public function getMetadataFormat() {
    return [
      'metadataPrefix' => 'mods',
      'schema' => 'http://www.loc.gov/standards/mods/v3/mods-3-7.xsd',
      'metadataNamespace' => 'http://www.loc.gov/mods/v3',
    ];
  }

  /**
   * Default metadata wrapper info for mods
   *
   * @return string[]
   */
  public static function defaultMetadataWrapperElements(): array {
    return [
      '@xmlns:mods' => 'http://www.loc.gov/mods/v3',
      '@xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
      '@xmlns:xlink' => 'http://www.w3.org/1999/xlink',
      '@version' => '3.4',
      '@xsi:schemaLocation' => 'http://www.loc.gov/mods/v3 http://www.loc.gov/standards/mods/v3/mods-3-4.xsd',
    ];
  }

  /**
   * Provides information contained in the metadata wrapper.
   *
   * @return array
   *   The information needed in the metadata wrapper.
   *
   */
  public function getMetadataWrapper() {
    $config = \Drupal::config('format_strawberryfield_rest_oai_pmh.settings');
    $elements =  $config->get('mods-wrapper-elements') ?? self::defaultMetadataWrapperElements();
    return [
      'mods' => $elements
    ];
  }

  /**
   * Method to transform the provided entity into the desired metadata record.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to transform.
   *
   * @return string
   *   rendered XML.
   */
  public function transformRecord(ContentEntityInterface $entity) {

    $render_array = ['metadata_prefix' => 'mods'];

    $config = \Drupal::config('format_strawberryfield_rest_oai_pmh.settings');
    $template_id = $config->get('mods-template');

    if($template_id) {
      /** @var \Drupal\format_strawberryfield\Entity\MetadataDisplayEntity $metadatadisplayEntity */
      $metadatadisplayEntity = \Drupal::entityTypeManager()->getStorage('metadatadisplay_entity')->load($template_id);
      if($metadatadisplayEntity) {
        $sbf_fields = \Drupal::service('strawberryfield.utility')->bearsStrawberryfield($entity);

        // Set initial context.
        $context = [
          'node' => $entity,
          'iiif_server' => \Drupal::service('config.factory')
            ->get('format_strawberryfield.iiif_settings')
            ->get('pub_server_url'),
        ];

        // Add the SBF json context.
        // @see MetadataExposeDisplayController::castViaTwig()
        foreach ($sbf_fields as $field_name) {
          /** @var \Drupal\strawberryfield\Field\StrawberryFieldItemList $field */
          $field = $entity->get($field_name);
          foreach ($field as $offset => $fielditem) {
            $jsondata = json_decode($fielditem->value, TRUE);
            // Preorder as:media by sequence.
            $ordersubkey = 'sequence';
            foreach (StrawberryfieldJsonHelper::AS_FILE_TYPE as $key) {
              StrawberryfieldJsonHelper::orderSequence($jsondata, $key, $ordersubkey);
            }
            if ($offset === 0) {
              $context['data'] = $jsondata;
            }
            else {
              $context['data'][$offset] = $jsondata;
            }
          }
        }
        $render_array['elements'] = $metadatadisplayEntity->renderNative($context);
      }
    }

    return parent::build($render_array);
  }

}
