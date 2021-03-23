<?php
namespace Drupal\format_strawberryfield;
use Twig\TwigTest;
use Drupal\twig_tweak\TwigExtension as TwigTweakExtension;

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
   * Returns render arrays of entities with matching title/name/label in the specified view mode and language context.
   *
   * Supported entity types are node, taxonomy_term, group, and user.
   *
   * @param  string  $label
   *   The entity label that we're looking for
   * @param  string  $entity_type
   *   The entity type.
   * @param  string  $bundle_identifier
   *   The entity bundle (may be empty)
   * @param  string  $view_mode
   *   The view mode for the render array that should be returned for each entity.
   * @param  bool  $check_access
   *   Whether to check for entity access permission (default TRUE).
   * @param  int  $limit
   *   Restrict to number of results. Capped at no more than 100.
   * @param  null  $langcode
   *   (optional) For which language the entity should be rendered, defaults to
   *   the current content language.
   *
   * @return null|array
   *   An array of render arrays for the entities found, or NULL if the entity does not exist.
   */
  public function format_strawberryfield_load_entities_by_label(string $label, string $entity_type, string $bundle_identifier = '', $view_mode = 'default', $check_access = TRUE, $limit = 1, $langcode = NULL): ?array {
    $label = \Drupal::database()->escapeLike($label);
    $limit = min($limit, 100);
    /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
    try {
      switch($entity_type) {
        case 'node':
          $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery();
          $query->condition('title', $label, 'LIKE')
            ->accessCheck($check_access)
            ->range(0,$limit);
          if($bundle_identifier) {
            $query->condition('type', $bundle_identifier);
          }
          $ids = $query->execute();
          break;
        case 'taxonomy_term':
          $query = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery();
          $query->condition('name', $label, 'LIKE')
            ->accessCheck($check_access)
            ->range(0,$limit);
          if($bundle_identifier) {
            $query->condition('vid', $bundle_identifier);
          }
          $ids = $query->execute();
          break;
        case 'group':
          $query = \Drupal::entityTypeManager()->getStorage('group')->getQuery();
          $query->condition('label', $label, 'LIKE')
            ->accessCheck($check_access)
            ->range(0,$limit);
          if($bundle_identifier) {
            $query->condition('type', $bundle_identifier);
          }
          $ids = $query->execute();
          break;
        case 'user':
          $query = \Drupal::entityTypeManager()->getStorage('user')->getQuery();
          $query->condition('name', $label, 'LIKE')
            ->accessCheck($check_access)
            ->range(0,$limit);
          $ids = $query->execute();
          break;
      }
    }
    catch(\Exception $exception) {
      $responseMessage = $exception->getMessage();
      $message = $this->t('@exception_type thrown in @file:@line while querying for @entity_type entity ids matching "@label". Message: @response',
        [
          '@exception_type' => get_class($exception),
          '@file' => $exception->getFile(),
          '@line' => $exception->getLine(),
          '@entity_type' => $entity_type,
          '@label' => $label,
          '@response' => $responseMessage
        ]);
      \Drupal::logger('format_strawberryfield')->warning($message);
      return NULL;
    }

    if(!empty($ids)) {
      try {
        $entities = [];
        $twig_tweak = new TwigTweakExtension();
        foreach ($ids as $id) {
          $entities[$id] = $twig_tweak->drupalEntity($entity_type, $id,
            $view_mode, $langcode);
        }
        return $entities;
      }
      catch(\Exception $exception) {
        $responseMessage = $exception->getMessage();
        $message = $this->t('@exception_type thrown in @file:@line while attempting to render @entity_type with id @id using Drupal\twig_tweak\TwigExtension::drupalEntity. Message: @response',
          [
            '@exception_type' => get_class($exception),
            '@file' => $exception->getFile(),
            '@line' => $exception->getLine(),
            '@entity_type' => $entity_type,
            '@id' => $id,
            '@response' => $responseMessage
          ]);
        \Drupal::logger('format_strawberryfield')->warning($message);
        return NULL;

      }
    }

    return NULL;

  }

}
