<?php

namespace Drupal\format_strawberryfield;

use Twig\Markup;
use Twig\TwigTest;
use Twig\TwigFilter;
use Twig\TwigFunction;

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
      new TwigFunction('sbf_entity_ids_by_label',
        [$this, 'entityIdsByLabel']),
    ];
  }

  /**
   * @inheritDoc
   */
  public function getFilters() {
    return [
      new TwigFilter('sbf_json_decode', [$this, 'sbfJsonDecode'])
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

  /**
   * JSON Decodes a string.
   *
   * To make this function safe we define a 64 max depth, always associative
   * array and fail on non Valid UTF8. No user provided Bit Masks are allowed
   *
   * @param mixed $value the value to decode
   *
   * @return mixed The JSON decoded value
   *    - NULL if failure, not the right type (e.g Iterable) or if NULL
   *    - TRUE/FALSE
   *    - An array
   */
  public function sbfJsonDecode($value) {
    error_log(var_export($value, true));
    if ($value instanceof Markup) {
      $value = (string) $value;
    }
    elseif (\is_iterable($value)) {
      // Do not fail, return an empty array;
      return NULL;
    }
    try {
      return json_decode($value, TRUE, 64, JSON_INVALID_UTF8_IGNORE | JSON_OBJECT_AS_ARRAY);
    } catch (\Exception $exception) {
      return NULL;
    }
  }

}
