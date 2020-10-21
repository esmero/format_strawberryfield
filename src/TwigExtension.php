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
}