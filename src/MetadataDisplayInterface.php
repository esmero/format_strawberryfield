<?php
namespace Drupal\format_strawberryfield;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use \Drupal\Core\Template\TwigEnvironment;

  /**
   * Provides an interface defining a Metadata Display entity.
   * @ingroup format_strawberryfield
   */
interface MetadataDisplayInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Gets the Twig Environment.
   *
   * @return TwigEnvironment
   */
  public function twigEnvironment();

  /**
   * Processes this Twig template into an Render array.
   *
   * @param array $context
   *
   * @return array
   */
  public function processHTML(array $context);

  /**
   * Renders a Twig template using a Context array.
   *
   * @param array $context
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   */
  public function renderNative(array $context);

  /**
   * Returns an array will all defined Twig Variables for this Twig template.
   *
   * @return array
   * @throws \Twig\Error\SyntaxError
   */
  public function getTwigVariablesUsed();

  /**
   * Calculates or Returns cached related Cache tags.
   *
   * @param bool $force
   *
   * @return array
   * @throws \Twig\Error\SyntaxError
   */
  public function getRelatedCacheTagsToInvalidate(bool $force);

}

