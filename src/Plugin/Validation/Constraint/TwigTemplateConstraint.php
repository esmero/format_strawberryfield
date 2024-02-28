<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/19/18
 * Time: 2:20 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Validation\Constraint;
use Symfony\Component\Validator\Constraint;

/**
 * Checks that the submitted value is a unique integer.
 *
 * @Constraint(
 *   id = "TwigTemplateConstraint",
 *   label = @Translation("Unique Integer", context = "Validation"),
 *   type = "string"
 * )
 */
class TwigTemplateConstraint extends Constraint {
  public $message = 'Value is not a valid Twig template.';
  public $useTwigMessage = false;
  public $TwigTemplateLogicalName = 'MetadataDisplayEntity';
}
