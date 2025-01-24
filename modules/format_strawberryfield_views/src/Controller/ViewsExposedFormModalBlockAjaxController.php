<?php

namespace Drupal\format_strawberryfield_views\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Http\RequestStack as DrupalRequestStack;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\PathProcessor\PathProcessorManager;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack as SymfonyRequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;

/**
 * Defines a controller to load a Modal Exposed Views Form Block via AJAX.
 */
class ViewsExposedFormModalBlockAjaxController extends ControllerBase {

  /**
   * The entity storage for block.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * The dynamic router service.
   *
   * @var \Symfony\Component\Routing\Matcher\RequestMatcherInterface
   */
  protected $router;

  /**
   * The path processor service.
   *
   * @var \Drupal\Core\PathProcessor\InboundPathProcessorInterface
   */
  protected $pathProcessor;

  /**
   * The current route match service.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

  /**
   * Constructs a ViewsExposedFormModalBlockAjaxController object.
   *
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPath
   *   The current path service.
   * @param \Symfony\Component\Routing\RouterInterface $router
   *   The router service.
   * @param \Drupal\Core\PathProcessor\PathProcessorManager $pathProcessor
   *   The path processor manager.
   * @param \Drupal\Core\Routing\CurrentRouteMatch $currentRouteMatch
   *   The current route match service.
   */
  public function __construct(RendererInterface $renderer, CurrentPathStack $currentPath, RouterInterface $router, PathProcessorManager $pathProcessor, CurrentRouteMatch $currentRouteMatch) {
    $this->storage = $this->entityTypeManager()->getStorage('block');
    $this->renderer = $renderer;
    $this->currentPath = $currentPath;
    $this->router = $router;
    $this->pathProcessor = $pathProcessor;
    $this->currentRouteMatch = $currentRouteMatch;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('renderer'),
      $container->get('path.current'),
      $container->get('router'),
      $container->get('path_processor_manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * Loads and renders the facet blocks via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The ajax response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the view was not found.
   */
  public function ajaxExposedFormBlockView(Request $request) {
    $response = new AjaxResponse();

    // Rebuild the request and the current path, needed for facets.
    $path = $request->request->get('exposedform_link') ?? NULL;
    $modalviews_blocks = $request->request->all('modalviews_blocks') ?? [];

    if (empty($path) || empty($modalviews_blocks)) {
      throw new NotFoundHttpException('No Modal Exposed Views Form links or blocks found.');
    }

    // Now Dear Diego. the page argument is permeating on a POST
    // Making a new query that was on page 100 fail bc it might not a page 100 for that query
    // Drupal is hard
    $path_expanded = UrlHelper::parse($path);
    $filtered = UrlHelper::filterQueryParameters(
      $path_expanded['query'], ['page']
    );
    $path_expanded['query'] = $filtered;
    UrlHelper::buildQuery($path_expanded['query']);
    $path_object = \Drupal::pathValidator()->getUrlIfValid($path_expanded['path']);
    if (!$path_object) {
      // Stay on the same page if the redirect was invalid.
      throw new NotFoundHttpException('No Modal Exposed Views Form links or blocks found.');
    }
    $path_object->setOptions($path_expanded);
    $path = $path_object->toString();

    // Make sure we are not updating blocks multiple times.
    $modalviews_blocks = array_unique($modalviews_blocks);

    $new_request = Request::create($path);
    $new_request->setSession($request->getSession());
    // Add ajax_page_state to the new request if set.
    if ($request->request->has('ajax_page_state')) {
      $new_request->request->set('ajax_page_state', $request->request->all('ajax_page_state'));
    }
    elseif ($request->query->has('ajax_page_state')) {
      $new_request->query->set('ajax_page_state', $request->query->all('ajax_page_state'));
    }
    $request_stack = new \Symfony\Component\HttpFoundation\RequestStack;

    $processed = $this->pathProcessor->processInbound($path, $new_request);
    $processed_request = Request::create($processed);

    $this->currentPath->setPath($processed_request->getPathInfo());
    $request->attributes->add($this->router->matchRequest($new_request));
    $this->currentRouteMatch->resetRouteMatch();
    $request_stack->push($new_request);

    $container = \Drupal::getContainer();
    $container->set('request_stack', $request_stack);

    // Build the blocks found for the current request and update.
    foreach ($modalviews_blocks as $block_inner_selector => $block_id) {
      $block_entity = $this->storage->load($block_id);
      // inner selector is not used but just as a way of having a consistent unique ID when posting the values
      if ($block_entity) {
        // Render a block, then add it to the response as a replace command.
        $block_view = $this->entityTypeManager
          ->getViewBuilder('block')
          ->view($block_entity);
        $block_view = (string) $this->renderer->renderInIsolation($block_view);
        $response->addCommand(new ReplaceCommand('[data-drupal-modalblock-selector="js-modal-form-views-block-id-' .$block_id.'"]', $block_view));
      }
    }



    return $response;
  }
}
