<?php

namespace Drupal\format_strawberryfield\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Valid JSON constraint.
 *
 * Verifies that input values are valid JSON.
 */
#[Constraint(id: 'valid_strawberry_jsonld', label: new TranslatableMarkup('Valid jsonld', ['context' => 'Validation']))]
class FormatConstraintJsonLd extends SymfonyConstraint {

  /**
   * The default violation message.
   *
   * @var string
   */
  public $message = 'This document is not valid JSON-LD (@error).';

}