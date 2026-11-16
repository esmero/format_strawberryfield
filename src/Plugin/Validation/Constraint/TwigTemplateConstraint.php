<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/19/18
 * Time: 2:20 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Validation\Constraint;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Checks that the submitted value is a unique integer.
 */
#[Constraint(
  id: 'TwigTemplateConstraint',
  label: new TranslatableMarkup('Valid Twig template',
    ['context' => 'Validation']),
  type: 'string'
)]
class TwigTemplateConstraint extends SymfonyConstraint {
  public $message = 'Value is not a valid Twig template.';
  public $useTwigMessage = false;
  public $TwigTemplateLogicalName = 'MetadataDisplayEntity';
}
