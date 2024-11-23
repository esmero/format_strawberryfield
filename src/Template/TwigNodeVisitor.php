<?php

namespace Drupal\format_strawberryfield\Template;

use Twig\Environment;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\Node\PrintNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Provides a TwigNodeVisitor to change the generated parse-tree.
 *
 * This is used to ensure that everything printed is wrapped via the
 * TwigExtension->renderVar() function in order to just write {{ content }}
 * in templates instead of having to write {{ render_var(content) }}.
 *
 * @see twig_render
 */
class TwigNodeVisitor implements NodeVisitorInterface {

  /**
   * Tracks whether there is a render array aware filter active already.
   */
  protected ?bool $skipRenderVarFunction;

  /**
   * {@inheritdoc}
   */
  public function enterNode(Node $node, Environment $env): Node {
    return $node;
  }

  /**
   * {@inheritdoc}
   */
  public function leaveNode(Node $node, Environment $env): ?Node {
    // Change the 'drupal_escape' filter to our own 'format_strawberry_safe_escape' filter.
    if ($node instanceof FilterExpression) {
      $name = $node->getNode('filter')->getAttribute('value');
      if ('drupal_escape' == $name) {
        $node->getNode('filter')->setAttribute('value', 'format_strawberry_safe_escape');
      }
    }
    return $node;
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority() {
    // Just above the Optimizer and the Drupal core one, which should be the normal last two ones.
    return 257;
  }

}
