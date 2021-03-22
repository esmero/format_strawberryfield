<?php
namespace Drupal\format_strawberryfield;
use Twig\TwigTest;

/**
 * Class TwigExtension.
 *
 * @package Drupal\format_strawberryfield
 */
class TwigExtension extends \Twig_Extension {

  public function getTests(): array
  {
    return [
      new TwigTest('instanceof', [$this, 'is_instanceof']),
    ];
  }

  public function is_instanceof($value, string $type): bool
  {
    return ('null' === $type && null === $value)
      || (\function_exists($func = 'is_'.$type) && $func($value))
      || $value instanceof $type;
  }

  /**
   * Returns entity objects with matching title/name/label in the specified language context.
   *
   * Supported entity types are node, taxonomy_term, group, and user.
   *
   * @param  string  $label
   *   The entity label that we're looking for
   * @param  string  $entity_type
   *   The entity type.
   * @param  string  $bundle_identifier
   *   The entity bundle (may be empty)
   * @param  numeric  $limit
   *   Restrict to number of results.
   * @param  null  $langcode
   *   (optional) For which language the entity should be rendered, defaults to
   *   the current content language.
   *
   * @return null|[Drupal\Core\Entity\EntityInterface]
   *   An array of entity objects or NULL if no entity with label exists.
   */
  public function load_entities_by_label(string $label, string $entity_type, string $bundle_identifier = '', $limit = 1, $langcode = NULL): ?array {
    $label = \Drupal::database()->escapeLike($label);
    switch($entity_type) {
      case 'node':
        $query = \Drupal::entityQuery('node')
          ->condition('title', $label, 'LIKE')
          ->range(0,$limit);
        if($bundle_identifier) {
          $query->condition('type', $bundle_identifier);
        }
        $ids = $query->execute();
        break;
      case 'taxonomy_term':
        $query = \Drupal::entityQuery('taxonomy_term')
          ->condition('name', $label, 'LIKE')
          ->range(0,$limit);
        if($bundle_identifier) {
          $query->condition('vid', $bundle_identifier);
        }
        $ids = $query->execute();
        break;
      case 'group':
        $query = \Drupal::entityQuery('group')
          ->condition('label', $label, 'LIKE')
          ->range(0,$limit);
        if($bundle_identifier) {
          $query->condition('type', $bundle_identifier);
        }
        $ids = $query->execute();
        break;
      case 'user':
        $query = \Drupal::entityQuery('user')
          ->condition('name', $label, 'LIKE')
          ->range(0,$limit);
        $ids = $query->execute();
        break;
    }

    if(!empty($ids)) {
      $entities = $this->getEntityTypeManager()->getStorage($entity_type)->loadMultiple($ids);
      if (empty($entities)) {
        return NULL;
      }

      // Get the entities in the specified context language.
      $entityRepository = $this->getEntityRepository();
      $translated_entities = [];
      foreach($entities as $entity) {
        $translated_entities[$entity->id()] = $entityRepository->getTranslationFromContext($entity, $langcode);
      }
      return $translated_entities;
    }

    return NULL;

  }

}
