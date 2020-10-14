<?php
/**
 * Created by PhpStorm.
 * User: dpino
 * Date: 11/19/18
 * Time: 2:03 PM
 */

namespace Drupal\format_strawberryfield\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Template\TwigEnvironment;
use Twig\Source;
use Twig_Error_Syntax;



class TwigTemplateConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected $twig;

  public function __construct(TwigEnvironment $twig)
  {
    $this->twig = $twig;
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('twig'));
  }

  public function validate($value, Constraint $constraint)
  {
    if (!$value->value) {
      return;
    }

    $twig = $this->twig;
    $message = $constraint->message;
    try {
      $source = new Source($value->value, $constraint->TwigTemplateLogicalName);
      $twig->parse($twig->tokenize($source));
    } catch (Twig_Error_Syntax $e) {
      if ($constraint->useTwigMessage) {
        $message = $e->getMessage();
      }

      $this->context->addViolation($message);
    }
  }

}
