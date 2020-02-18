<?php

namespace Drupal\format_strawberryfield\Plugin\Validation\Constraint;
use Drupal\facets\Exception\Exception;
use ML\JsonLD\JsonLD;
use ML\JsonLD\Exception\JsonLdException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;


/**
 * Class FormatConstraintXMLValidator
 *
 * Checks if string is valid XML
 *
 * @package Drupal\format_strawberryfield\Plugin\Validation\Constraint
 */
class FormatConstraintJsonLdValidator extends ConstraintValidator  {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {

    if (empty(trim($value->value))) {
      return;
    }
    try {
      $jsonld = JsonLD::getDocument($value->value);
    }
    catch (JsonLdException $e) {
      $this->context->addViolation($constraint->message, ['@error' => $e->getMessage()]);
    }
  }
}