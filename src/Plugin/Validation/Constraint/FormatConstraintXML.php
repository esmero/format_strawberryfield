<?php

namespace Drupal\format_strawberryfield\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Valid JSON constraint.
 *
 * Verifies that input values are valid JSON.
 *
 * @Constraint(
 *   id = "valid_strawberry_xml",
 *   label = @Translation("Valid XML", context = "Validation")
 * )
 */
class FormatConstraintXML extends Constraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public $message = 'This document is not valid XML (@error).';

}