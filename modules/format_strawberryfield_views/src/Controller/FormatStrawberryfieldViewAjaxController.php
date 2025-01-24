<?php

namespace Drupal\format_strawberryfield_views\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Ajax\ScrollTopCommand;
use Drupal\views\Ajax\ViewAjaxResponse;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\views\Controller\ViewAjaxController;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\format_strawberryfield_views\Ajax\SbfSetBrowserUrl;

/**
 * Defines a controller to load a view via AJAX.
 */
class FormatStrawberryfieldViewAjaxController extends ViewAjaxController {

  /**
   * Parameters that should be filtered and ignored inside ajax requests.
   */
  public const FILTERED_QUERY_PARAMETERS = [
    'view_name',
    'view_display_id',
    'view_args',
    'view_path',
    'view_dom_id',
    'pager_element',
    'view_base_path',
    'ajax_page_state',
    'exposed_form_display',
    AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER,
    FormBuilderInterface::AJAX_FORM_REQUEST,
    MainContentViewSubscriber::WRAPPER_FORMAT,
  ];


  /**
   * The entity storage for views.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * The factory to load a view executable with.
   *
   * @var \Drupal\views\ViewExecutableFactory
   */
  protected $executableFactory;

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
   * The redirect destination.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected PathValidatorInterface $pathValidator;

  /**
   * Constructs a ViewAjaxController object.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage for views.
   * @param \Drupal\views\ViewExecutableFactory $executable_factory
   *   The factory to load a view executable with.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path
   *   The current path.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   The redirect destination.
   * @param \Drupal\Core\Path\PathValidatorInterface $path_validator
   *   The Core Path validator.
   */
  public function __construct(EntityStorageInterface $storage, ViewExecutableFactory $executable_factory, RendererInterface $renderer, CurrentPathStack $current_path, RedirectDestinationInterface $redirect_destination, PathValidatorInterface $path_validator) {
    $this->storage = $storage;
    $this->executableFactory = $executable_factory;
    $this->renderer = $renderer;
    $this->currentPath = $current_path;
    $this->redirectDestination = $redirect_destination;
    $this->pathValidator = $path_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('view'),
      $container->get('views.executable'),
      $container->get('renderer'),
      $container->get('path.current'),
      $container->get('redirect.destination'),
      $container->get('path.validator')
    );
  }

  /**
   * Loads and renders a view via AJAX.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   *
   * @return \Drupal\views\Ajax\ViewAjaxResponse
   *   The view response as ajax response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the view was not found.
   */
  public function ajaxView(Request $request) {
    $name = $request->get('view_name');
    $display_id = $request->get('view_display_id');
    if (isset($name) && isset($display_id)) {
      $args = $request->get('view_args', '');
      $args = $args !== '' ? explode('/', Html::decodeEntities($args)) : [];

      // Arguments can be empty, make sure they are passed on as NULL so that
      // argument validation is not triggered.
      $args = array_map(function ($arg) {
        return ($arg == '' ? NULL : $arg);
      }, $args);

      $path = $request->get('view_path') ?? Html::escape($this->currentPath->getPath());
      // If a view has an invalid Path (e.g. you added some % somewhere) this will be null.
      $target_url = $this->pathValidator->getUrlIfValid($path ?? '/');
      $dom_id = $request->get('view_dom_id');
      $dom_id = isset($dom_id) ? preg_replace('/[^a-zA-Z0-9_-]+/', '-', $dom_id) : NULL;
      $pager_element = $request->get('pager_element');
      $pager_element = isset($pager_element) ? intval($pager_element) : NULL;
      // Assume its there if not told otherwise
      $exposed_form_display = (bool) $request->get('exposed_form_display', TRUE);

      $response = new ViewAjaxResponse();

      $existing_page_state = $request->get('ajax_page_state');
      foreach (self::FILTERED_QUERY_PARAMETERS as $key) {
        $request->query->remove($key);
        $request->request->remove($key);
      }

      // Load the view.
      if (!$entity = $this->storage->load($name)) {
        throw new NotFoundHttpException();
      }
      $view = $this->executableFactory->get($entity);
      if ($view && $view->access($display_id) && $view->setDisplay($display_id) && $view->display_handler->ajaxEnabled()) {
        // Fix the current path for paging.
        if (!empty($path)) {
          $this->currentPath->setPath('/' . ltrim($path, '/'), $request);
        }
        // Let's check if our view has our advanced search filter
        $filters = $view->getDisplay()->display['display_options']['filters'] ?? [];
        // Gather the Views POST and GET upfront.
        $views_post = $view->getRequest()->request->all();
        $views_get = $view->getRequest()->query->all();

        foreach ($filters as $filter) {
          // Deal with the RESET here
          if (($views_get['op'] ?? '') === "Reset" || ($views_post['op'] ?? '') === "Reset" || ($views_get['reset'] ?? FALSE)) {
            unset($views_post[$filter['expose']['identifier']]);
            unset($views_get[$filter['expose']['identifier']]);
          }
            /* @var \Drupal\views\Plugin\views\ViewsHandlerInterface $filter */
          elseif ($filter['plugin_id'] == 'sbf_advanced_search_api_fulltext'
            && $filter['exposed'] == TRUE
          ) {
            if ($filter['expose']['identifier'] ?? NULL ) {
              // At this stage the loaded View has already processed
              // the whole GET bag as its own input based on the active request
              // We need to alter that. Let's check if we have the id in both GET and POST
              // If in POST, POST wins and replaces/needs to replace GET in the view
              unset($views_post['ajax_page_state']);
              unset($views_get['ajax_page_state']);
              if (isset($views_post[$filter['expose']['identifier']])) {
                foreach ($views_get as $get_args_keys => $value) {
                  if (strpos($get_args_keys, $filter->options['expose']['identifier']) === 0) {
                    unset($views_get[$get_args_keys]);
                  }
                }
              }
            }
          }
        }
        $view->getRequest()->query->replace($views_post + $views_get);
        // Create a clone of the request object to avoid mutating the request
        // object stored in the request stack.
        $request_clone = clone $request;

        // Add all POST data, because AJAX is sometimes a POST and many things,
        // such as tablesorts, exposed filters and paging assume GET.
        $param_union = $request_clone->request->all() + $request_clone->query->all();
        $used_query_parameters = $param_union;
        unset($param_union['ajax_page_state']);
        $request_clone->query->replace($used_query_parameters);
        $response->setView($view);
        // Overwrite the destination.
        // @see the redirect.destination service.
        $origin_destination = $path;


        $query = UrlHelper::buildQuery($used_query_parameters);
        if ($query != '') {
          if (!isset($used_query_parameters['reset']) && ($used_query_parameters['op'] ?? '') !== "Reset") {
            unset($used_query_parameters['op']);
            $origin_destination .= '?' . $query;
          }
          else {
            $used_query_parameters = [];
          }
          if ($target_url) {
            //Remove views%2Fajax from the URL set to the browser. makes no sense to allow that to be bookmarked.
            unset($used_query_parameters['/views/ajax']);
            $target_url->setOption('query', $used_query_parameters);
          }
        }
        $this->redirectDestination->set($origin_destination);

        // Override the display's pager_element with the one actually used.
        if (isset($pager_element)) {
          $response->addCommand(new ScrollTopCommand(".js-view-dom-id-$dom_id"));
          $view->displayHandlers->get($display_id)->setOption('pager_element', $pager_element);
        }
        // Reuse the same DOM id, so it matches that in drupalSettings.
        $view->dom_id = $dom_id;

        // Populate request attributes temporarily with ajax_page_state theme
        // and theme_token for theme negotiation.
        $theme_keys = [
          'theme' => TRUE,
          'theme_token' => TRUE,
        ];
        if (is_array($existing_page_state) &&
          ($temp_attributes = array_intersect_key($existing_page_state, $theme_keys))) {
          $request->attributes->set('ajax_page_state', $temp_attributes);
        }
        $context = new RenderContext();

        $preview = $this->renderer->executeInRenderContext($context, function () use ($view, $display_id, $args) {
          return $view->preview($display_id, $args);
        });
        if (!$context->isEmpty()) {
          $bubbleable_metadata = $context->pop();
          BubbleableMetadata::createFromRenderArray($preview)
            ->merge($bubbleable_metadata)
            ->applyTo($preview);
        }
        $request->attributes->remove('ajax_page_state');
        $response->addCommand(new ReplaceCommand(".js-view-dom-id-$dom_id", $preview));
        $response->addCommand(new PrependCommand(".js-view-dom-id-$dom_id", ['#type' => 'status_messages']));
        //@TODO revisit in Drupal 10
        //@See https://www.drupal.org/project/drupal/issues/343535
        if ($target_url) {
          $seturl = TRUE;
          $extenders = $view->display_handler->getExtenders();
          foreach ($extenders as $extender) {
            if (($extender->getPluginId()== "sbf_ajax_interactions") &&  ($extender->options['sbf_ajax_dont_seturl'] ?? FALSE)) {
              $seturl = FALSE;
            }
          }
          if ($seturl) {
            $response->addCommand(new SbfSetBrowserUrl($target_url->toString()));
          }
        }

        // Views with ajax enabled aren't refreshing filters placed in blocks.
        // Only <div> containing view is refreshed. ReplaceCommand is fixing
        // that for view, if it uses ajax and exposed forms in block.
        if ($exposed_form_display && $view->display_handler->usesExposed() && $view->display_handler->getOption('exposed_block')) {
          $view_id = preg_replace('/[^a-zA-Z0-9-]+/', '-', $name . '-' . $display_id);
          $context = new RenderContext();
          $exposed_form = $this->renderer->executeInRenderContext($context, function () use ($view) {
            return $view->display_handler->viewExposedFormBlocks();
          });
          if (!$context->isEmpty()) {
            $bubbleable_metadata = $context->pop();
            BubbleableMetadata::createFromRenderArray($exposed_form)
              ->merge($bubbleable_metadata)
              ->applyTo($exposed_form);
          }
          $response->addCommand(new ReplaceCommand("#views-exposed-form-" . $view_id, $this->renderer->render($exposed_form)));
        }
        $request->query->set('ajax_page_state', $existing_page_state);
        return $response;
      }
      else {
        throw new AccessDeniedHttpException();
      }
    }
    else {
      throw new NotFoundHttpException();
    }
  }
}
