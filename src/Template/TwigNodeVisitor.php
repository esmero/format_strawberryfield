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
    // We use this to inject a call to render_var -> TwigExtension->renderVar()
    // before anything is printed.
    if ($node instanceof PrintNode) {
      if (!empty($this->skipRenderVarFunction)) {
        // No need to add the callback, we have escape active already.
        unset($this->skipRenderVarFunction);
        return $node;
      }
      $class = get_class($node);
      $line = $node->getTemplateLine();
      return new $class(
        new FunctionExpression('render_var', new Node([$node->getNode('expr')]), $line),
        $line
      );
    }
    // Change the 'drupal_escape' filter to our own 'format_strawberry_safe_escape' filter.
    elseif ($node instanceof FilterExpression) {
      $name = $node->getNode('filter')->getAttribute('value');
      if ('drupal_escape' == $name) {
        // Use our own SBF escape filter that will not just die on a wrong render array.
        $node->getNode('filter')->setAttribute('value', 'format_strawberry_safe_escape');
        // Store that we have a filter active already that knows
        // how to deal with render arrays.
        $this->skipRenderVarFunction = TRUE;
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
