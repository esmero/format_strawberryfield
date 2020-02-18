<?php

namespace Drupal\format_strawberryfield\Plugin\Validation\Constraint;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use \LibXMLError;
use \DOMDocument;


/**
 * Class FormatConstraintXMLValidator
 *
 * Checks if string is valid XML
 *
 * @package Drupal\format_strawberryfield\Plugin\Validation\Constraint
 */
class FormatConstraintXMLValidator extends ConstraintValidator  {

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {

    if (empty(trim($value->value))) {
      return;
    }
    $errors = $this->isXMLContentValid($value->value, '1.0', 'utf-8');

    foreach($errors as $error) {
      $this->context->addViolation($constraint->message, ['@error' => $error->message]);
    }
  }

  /**
   * @param string $xmlContent A well-formed XML string
   * @param string $version 1.0
   * @param string $encoding utf-8
   * @return LibXMLError[]
   */
  protected function isXMLContentValid($xmlContent, $version = '1.0', $encoding = 'utf-8')
  {

    libxml_use_internal_errors(true);

    $doc = new DOMDocument($version, $encoding);
    $doc->loadXML($xmlContent);

    $errors = libxml_get_errors();
    libxml_clear_errors();

    return $errors;
  }

}