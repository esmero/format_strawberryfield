<?php

namespace Drupal\format_strawberryfield;

use Twig\TwigTest;

/**
 * Class TwigExtension.
 *
 * @package Drupal\format_strawberryfield
 */
class TwigExtension extends \Twig_Extension {

  public function getTests(): array {
    return [
      new TwigTest('instanceof', [$this, 'is_instanceof']),
    ];
  }

  /**
   * @param $value
   * @param  string  $type
   *
   * @return bool
   */
  public function is_instanceof($value, string $type): bool {
    return ('null' === $type && NULL === $value)
      || (\function_exists($func = 'is_' . $type) && $func($value))
      || $value instanceof $type;
  }

  /**
   * @inheritDoc
   */
  public function getFunctions() {
    return [
      new \Twig_SimpleFunction('sbf_entity_ids_by_label',
        [$this, 'entityIdsByLabel']),
    ];
  }

  /**
   * Returns entity ids of entities with matching title/name/label.
   *
   * @param string $label
   *   The entity label that we're looking for
   * @param string $entity_type
   *   The entity type.
   *   Supported entity types are node, taxonomy_term, group, and user.
   * @param string $bundle_identifier
   *   The entity bundle (may be empty)
   * @param int $limit
   *   Restrict to number of results. Capped at no more than 100.
   *
   * @return null|array
   *   An array of Entity IDs for the entities found, or NULL if no match.
   */
  public function entityIdsByLabel(
    string $label,
    string $entity_type,
    string $bundle_identifier = '',
    $limit = 1
  ): ?array {
    $fields = [
      'node' => ['title', 'type'],
      'taxonomy_term' => ['name', 'vid'],
      'group' => ['label', 'type'],
      'user' => ['name', NULL],
    ];
    $limit = (int) $limit;
    $entity_type = trim($entity_type);
    $bundle_identifier = trim($bundle_identifier);
    $label_field = $fields[$entity_type][0] ?? NULL;
    if ($label_field) {
      $bundle_field = $fields[$entity_type][1] ?? NULL;
      $limit = min((int) $limit, 100);
      $label = trim($label);
      try {
        /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
        $query = \Drupal::entityTypeManager()->getStorage($entity_type)->getQuery();
        $query->condition($label_field, $label);
        if ($bundle_identifier && $bundle_field) {
          $query->condition($bundle_field, $bundle_identifier);
        }
        $query->range(0, $limit);
        $ids = $query->execute();
      }
      catch (\Exception $exception) {
        $message = t('@exception_type thrown in @file:@line while querying for @entity_type entity ids matching "@label". Message: @response',
          [
            '@exception_type' => get_class($exception),
            '@file' => $exception->getFile(),
            '@line' => $exception->getLine(),
            '@entity_type' => $entity_type,
            '@label' => $label,
            '@response' => $exception->getMessage(),
          ]);
        \Drupal::logger('format_strawberryfield')->warning($message);
        return NULL;
      }

      if (!empty($ids)) {
        return $ids;
      }
    }
    return NULL;
  }
}
