<?php

namespace Drupal\format_strawberryfield;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderableInterface;
use Drupal\format_strawberryfield\Template\TwigNodeVisitor;
use Drupal\file\FileInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\format_strawberryfield\Entity\MetadataDisplayEntity;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\Markup as TwigMarkup;
use Twig\Runtime\EscaperRuntime;
use Twig\TwigTest;
use Twig\TwigFilter;
use Twig\TwigFunction;
use League\HTMLToMarkdown\HtmlConverter;
use Drupal\format_strawberryfield\CiteProc\Render;
use Drupal\search_api\ParseMode\ParseModePluginManager;
use Drupal\Core\Render\RendererInterface;
use EDTF\EdtfFactory;
use Drupal\views\Views;

/**
 * Class TwigExtension.
 *
 * @package Drupal\format_strawberryfield
 */
class TwigExtension extends AbstractExtension {

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * @var HttpKernelInterface
   */
  protected HttpKernelInterface $httpKernel;

  /**
   * Constructs \Drupal\format_strawberryfield\TwigExtension
   *
   * @param RendererInterface $renderer
   *   The renderer.
   * @param ParseModePluginManager $parse_mode_manager
   *   The search API parse mode manager.
   * @param RequestStack $request_stack
   * @param HttpKernelInterface $http_kernel
   */
  public function __construct(RendererInterface $renderer, ParseModePluginManager $parse_mode_manager, RequestStack $request_stack, HttpKernelInterface $http_kernel) {
    $this->renderer = $renderer;
    $this->parseModeManager = $parse_mode_manager;
    $this->requestStack = $request_stack;
    $this->httpKernel = $http_kernel;
  }

  public function getTests(): array {
    return [
      new TwigTest('instanceof', [$this, 'is_instanceof']),
    ];
  }

  /**
   * @param $value
   * @param string $type
   *
   * @return bool
   */
  public function is_instanceof($value, string $type): bool {
    return ('null' === $type && NULL === $value)
      || (\function_exists($func = 'is_' . $type) && $func($value))
      || $value instanceof $type;
  }

  /**
   * @inheritDoc
   */
  public function getFunctions() {
    return [
      new TwigFunction('sbf_entity_ids_by_label',
        [$this, 'entityIdsByLabel']),
      new TwigFunction('clipboard_copy',
        [$this, 'clipboardCopy']),
      new TwigFunction('sbf_search_api',
        [$this, 'searchApiQuery']),
      new TwigFunction('sbf_file_content', [$this, 'sbfFileContent'], ['is_safe' => ['all']]),
      new TwigFunction('sbf_drupal_view_paged',[$this, 'sbfDrupalView'])
    ];
  }

  /**
   * @inheritDoc
   */
  public function getFilters() {
    return [
      new TwigFilter('sbf_json_decode', [$this, 'sbfJsonDecode']),
      new TwigFilter('markdown_2_html', [$this, 'markdownToHtml'],
        ['is_safe' => ['all']]),
      new TwigFilter('html_2_markdown', [$this, 'htmlToMarkdown'],
        ['is_safe' => ['all']]),
      new TwigFilter('bibliography', [$this, 'bibliography'], ['is_safe' => ['all']]),
      new TwigFilter('edtf_2_human_date', [$this, 'edtfToHumanDate'],
        ['is_safe' => ['all']]),
      new TwigFilter('edtf_2_iso_date', [$this, 'edtfToIsoDate'],
        ['is_safe' => ['all']]),
      // Replace Drupal core's twig escape filter, that throws exception on invalid render array with our own.
      new TwigFilter('format_strawberry_safe_escape', [$this, 'escapeFilter'], ['needs_environment' => TRUE, 'is_safe_callback' => 'twig_escape_filter_is_safe']),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getNodeVisitors() {
    // The node visitor is needed to wrap all variables with
    // render_var -> TwigExtension->renderVar() function.
    return [
      new TwigNodeVisitor(),
    ];
  }

  public function sbfDrupalView($name, $display_id = 'default', $page = 0) {
      $args = func_get_args();
      // Remove $name, $display_id and $page from the arguments.
      unset($args[0], $args[1],  $args[2]);
      $view = Views::getView($name);
      if (!$view || !$view->access($display_id)) {
        return NULL;
      }
    $view->setCurrentPage($page);
    $output = $view->executeDisplay($display_id, $args);
    return is_array($output) && isset($output['#markup']) ? $output['#markup'] : NULL;
  }


  /**
   * Returns entity ids of entities with matching title/name/label.
   *
   * @param string $label
   *   The entity label that we're looking for
   * @param string $entity_type
   *   The entity type.
   *   Supported entity types are node, taxonomy_term, group, and user.
   * @param string $bundle_identifier
   *   The entity bundle (may be empty)
   * @param int $limit
   *   Restrict to number of results. Capped at no more than 100.
   *
   * @return null|array
   *   An array of Entity IDs for the entities found, or NULL if no match.
   */
  public function entityIdsByLabel(
    string $label,
    string $entity_type,
    string $bundle_identifier = NULL,
           $limit = 1
  ): ?array {
    $fields = [
      'node' => ['title', 'type'],
      'taxonomy_term' => ['name', 'vid'],
      'group' => ['label', 'type'],
      'user' => ['name', NULL],
    ];
    $limit = (int) $limit;
    $entity_type = trim($entity_type);
    $bundle_identifier = !empty($bundle_identifier) ? trim($bundle_identifier) : NULL;
    $label_field = $fields[$entity_type][0] ?? NULL;
    if ($label_field) {
      $bundle_field = $fields[$entity_type][1] ?? NULL;
      $limit = min((int) $limit, 100);
      $label = trim($label);
      try {
        /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
        $query = \Drupal::entityTypeManager()
          ->getStorage($entity_type)
          ->getQuery()
          ->accessCheck(TRUE);
        $query->condition($label_field, $label);
        if ($bundle_identifier && $bundle_field) {
          $query->condition($bundle_field, $bundle_identifier);
        }
        $query->range(0, $limit);
        $ids = $query->execute();
      } catch (\Exception $exception) {
        $message = t('@exception_type thrown in @file:@line while querying for @entity_type entity ids matching "@label". Message: @response',
          [
            '@exception_type' => get_class($exception),
            '@file' => $exception->getFile(),
            '@line' => $exception->getLine(),
            '@entity_type' => $entity_type,
            '@label' => $label,
            '@response' => $exception->getMessage(),
          ]);
        \Drupal::logger('format_strawberryfield')->warning($message);
        return NULL;
      }

      if (!empty($ids)) {
        return $ids;
      }
    }
    return NULL;
  }

  /**
   * JSON Decodes a string.
   *
   * To make this function safe we define a 64 max depth, always associative
   * array and fail on non Valid UTF8. No user provided Bit Masks are allowed
   *
   * @param mixed $value the value to decode
   *
   * @return mixed The JSON decoded value
   *    - NULL if failure, not the right type (e.g Iterable) or if NULL
   *    - TRUE/FALSE
   *    - An array
   */
  public function sbfJsonDecode($value) {
    if ($value instanceof Markup) {
      $value = (string) $value;
    }
    elseif (\is_iterable($value)) {
      // Do not fail, return an empty array;
      return NULL;
    }
    try {
      return json_decode($value, TRUE, 64,
        JSON_INVALID_UTF8_IGNORE | JSON_OBJECT_AS_ARRAY);
    } catch (\Exception $exception) {
      return NULL;
    }
  }

  /**
   * Converts HTML to Markdown.
   *
   * @param $body
   * @param array $options
   *
   * @return string
   */
  public function htmlToMarkdown($body, array $options = []): string {
    static $converters;

    if (!class_exists(HtmlConverter::class)) {
      throw new \LogicException('You cannot use the "html_2_markdown" filter as league/html-to-markdown is not installed; try running "composer require league/html-to-markdown".');
    }
    if (empty($body)) {
      return '';
    }
    if (!is_string($body)) {
      return '';
    }

    $options = $options + [
        'hard_break' => TRUE,
        'strip_tags' => TRUE,
        'remove_nodes' => 'head style',
      ];

    if (!isset($converters[$key = serialize($options)])) {
      $converters[$key] = new HtmlConverter($options);
    }

    return $converters[$key]->convert($body);
  }

  /**
   * Converts Markdown to HTML.
   *
   * @param mixed $body
   *
   * @return string|null
   */
  public function markdownToHtml($body): string {
    if (!class_exists(\Parsedown::class)) {
      throw new \LogicException('You cannot use the "markdown_2_html" filter as erusev/parsedown is not installed; try running "composer require erusev/parsedown".');
    }
    if (empty($body)) {
      return '';
    }
    if (!is_string($body)) {
      return '';
    }

    $Parsedown = new \Parsedown();
    $Parsedown->setSafeMode(TRUE);
    return $Parsedown->text($body);
  }

  /**
   * Generates CSL bibliography.
   *
   * @param string $value
   * @param string $locale
   * @param array $styles
   *
   * @return string
   */
  public function bibliography(string $value, string $locale, array $styles = []): string {

    //  @EXAMPLE_JSON = '[
    //    {
    //      "author": [
    //            {
    //              "family": "Doe",
    //                "given": "James",
    //                "suffix": "III"
    //            }
    //        ],
    //        "id": "item-1",
    //        "issued": {
    //      "date-parts": [
    //        [
    //          "2001"
    //        ]
    //      ]
    //        },
    //        "title": "My Anonymous Heritage",
    //        "type": "book"
    //    },
    //    {
    //      "author": [
    //            {
    //              "family": "Anderson",
    //                "given": "John"
    //            },
    //            {
    //              "family": "Brown",
    //                "given": "John"
    //            }
    //        ],
    //        "id": "ITEM-2",
    //        "type": "book",
    //        "title": "Two authors writing a book"
    //    }
    // ]';

    $json_data = json_decode($value);
    $json_error = json_last_error();
    if ($json_error != JSON_ERROR_NONE) {
      return $json_error;
    }
    $render = new Render();
    if ($locale) {
      $bibliography = $render->bibliography($locale, $styles, $json_data);
    }
    else {
      $bibliography = $render->bibliography(NULL, $styles, $json_data);
    }
    $uniqueid = Html::getUniqueId('bibliography');
    $render_bibliography = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $uniqueid,
        'class' => ['bibliography'],
      ],
      '#attached' => [
        'library' => 'format_strawberryfield/citations_strawberry'],
    ];
    $render_bibliography['bibliography'] = [
      '#markup' => \Drupal\Core\Render\Markup::create($bibliography),
    ];
    $rendered_bibliography = $this->renderer->render($render_bibliography);
    return $rendered_bibliography;
  }

  /**
   * Generates ClipBoardCopy HTML/JS element.
   *
   * @param string $copyContentCssClass
   * @param string $copyButtonCssClass
   * @param string $copyButtonText
   *
   * @return mixed
   */
  public function clipboardCopy(string $copyContentCssClass = NULL, string $copyButtonCssClass = NULL, string $copyButtonText = NULL) {

    if (is_null($copyContentCssClass)) {
      return '';
    }
    if (empty($copyContentCssClass)) {
      return '';
    }
    if (!is_string($copyContentCssClass)) {
      return '';
    }
    if (is_null($copyButtonCssClass) || empty($copyButtonCssClass)) {
      $copyButtonCssClass = 'clipboard-copy-button';
    }

    if (is_null($copyButtonText) || empty($copyButtonText)) {
      $copyButtonText = t("Copy to clipboard");
    }

    $uniqueid = Html::getUniqueId('clipboard-copy-data');
    $button_html = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $uniqueid,
        'class' => ['clipboard-copy-data','hidden'],
        'data-clipboard-copy-button' => $copyButtonCssClass,
        'data-clipboard-copy-content' => $copyContentCssClass,
        'data-clipboard-copy-button-text' => $copyButtonText,
      ],
      '#attached' => [
        'library' => [
          'format_strawberryfield/clipboard_copy',
          'format_strawberryfield/clipboard_copy_strawberry',
        ],
      ],
    ];
    $rendered_button = $this->renderer->render($button_html);
    return $rendered_button;

  }

  /**
   * Converts EDTF to human-readable date.
   *
   * @param mixed $edtfString
   * @param string $lang
   *
   * @return string
   */
  public function edtfToHumanDate($edtfString, string $lang = NULL): string {
    if (empty($edtfString)) {
      return '';
    }
    if (!is_string($edtfString)) {
      return '';
    }

    $lang = $lang ?? 'en';
    $parser = EdtfFactory::newParser();
    $parsed = $parser->parse($edtfString);
    if ($parsed->isValid()) {
      $edtfValue = $parsed->getEdtfValue();
      try {
        $humanizer = EdtfFactory::newHumanizerForLanguage($lang);
        return $humanizer->humanize($edtfValue);
      }
      catch (\Exception $exception) {
        return '';
      }
    }
    return '';
  }

  /**
   * Converts EDTF to ISO date(s).
   *
   * @param mixed $edtfString
   *
   * @return array
   *   Will be empty if EDTF is not valid or passed arguments are invalid
   */
  public function edtfToIsoDate($edtfString): array {
    if (empty($edtfString)) {
      return [];
    }
    if (!is_string($edtfString)) {
      return [];
    }
    $values_parsed = [];
    $parser = EdtfFactory::newParser();
    try {
      $parsed = $parser->parse($edtfString);
      if ($parsed->isValid()) {
        $edtfValue = $parsed->getEdtfValue();
        // @todo remove once EDTF fixes their invalid Constructor for EDTF\Model\Interval that should per interface never allow NULL for start nor end date
        switch (get_class($edtfValue)) {
          case "EDTF\Model\Interval":
            if ($edtfValue->hasStartDate()) {
              $values_parsed[] = date('Y-m-d', $edtfValue->getMin());
            }
            if ($edtfValue->hasEndDate()) {
              $values_parsed[] = date('Y-m-d', $edtfValue->getMax());
            }
            break;
          default:

            $values_parsed[] = date('Y-m-d', $edtfValue->getMin());
            $values_parsed[] = date('Y-m-d', $edtfValue->getMax());
            break;
        }
        return array_unique($values_parsed);
      }
    }
    catch (\Exception $exception) {
      return [];
    }

    return [];
  }



  /**
   * Executes and Search API query programatically
   *
   * @param string $index
   *    The machine name of the Search API index to search against
   * @param string $term
   *    A Full text term to search against
   * @param array  $fulltext
   *    An array of Fields (Fulltext) to search Term against.
   *    If empty all will be used
   * @param array  $filters
   *    An associative array with fields => filters to match against
   * @param array  $facets
   *    An array of fields to facet
   * @param array  $sort
   *    An associative array with fields => Sort Order
   * @param int    $limit
   *    How many results to return
   * @param int    $offset
   *    Offset for the results
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function searchApiQuery(string $index, string $term, array $fulltext, array $filters, array $facets, array $sort = ['search_api_relevance' => QueryInterface::SORT_DESC], int $limit = NULL, int $offset = NULL): array {

    $limit = $limit ?? 1;
    $offset = $offset ?? 0;

    /** @var \Drupal\search_api\IndexInterface[] $indexes */
    $indexes = \Drupal::entityTypeManager()
      ->getStorage('search_api_index')
      ->loadMultiple([$index]);

    // We can check if $fulltext, $filters and $facets are inside $indexes['theindex']->field_settings["afield"]?
    foreach ($indexes as $search_api_index) {

      // Create the query.
      // How many?
      $query = $search_api_index->query([
        'limit' => $limit,
        'offset' => $offset,
      ]);

      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      foreach ($sort as $field => $order) {
        $query->sort($field, $order);
      }
      $query->keys($term);
      if (!empty($fulltext)) {
        $query->setFulltextFields($fulltext);
      }

      $query->setOption('search_api_retrieved_field_values', ['id']);
      foreach ($filters as $field => $condition) {
        $query->addCondition($field, $condition);
      }
      // Facets, does this search api index supports them?
      if ($search_api_index->getServerInstance()->supportsFeature('search_api_facets')) {
        // My real goal!
        //https://solarium.readthedocs.io/en/stable/queries/select-query/building-a-select-query/components/facetset-component/facet-pivot/
        $facet_options = [];
        foreach ($facets as $facet_field) {
          $facet_options['facet:' . $facet_field] = [
            'field'     => $facet_field,
            'limit'     => 100,
            'operator'  => 'or',
            'min_count' => 1,
            'missing'   => TRUE,
          ];
        }

        if (!empty($facet_options)) {
          $query->setOption('search_api_facets', $facet_options);
        }
      }
      /* Basic is good enough for facets */
      $query->setProcessingLevel(QueryInterface::PROCESSING_FULL);
      // see also \Drupal\search_api_autocomplete\Plugin\search_api_autocomplete\suggester\LiveResults::getAutocompleteSuggestions
      $results = $query->execute();
      $extradata = $results->getAllExtraData();
      // We return here
      $return = [];
      foreach( $results->getResultItems() as $itemid => $resultItem) {
        // We can not allow any extraction or entity load happening here
        // The Search API entity loading will interrupt other sessions/active NODEs
        // and will disable EDIT/any management on the ADO that is using this
        // Extension. So we will get what is in the index (solr)
        // Will have no issues with this.
        // This is related to "QueryInterface::PROCESSING_FULL" but is needed to respect
        // Permissions. If not this will get anything from the Server
        // Including hidden/unpublished things.
        foreach ($resultItem->getFields(FALSE) as $field) {
          $return['results'][$itemid]['fields'][$field->getFieldIdentifier()]
            = $field->getValues();
        }
      }

      $return['total'] = $results->getResultCount();
      if (isset($extradata['search_api_facets'])) {
        foreach($extradata['search_api_facets'] as $facet_id => $facet_values) {
          [$not_used, $field_id] = explode(':', $facet_id);
          $facet = [];
          if (is_array($facet_values)) {
            foreach ($facet_values as $entry) {
              $facet[$entry['filter']] = $entry['count'];
            }
            $return['facets'][$field_id] = $facet;
          }
        }
      }
      return $return;
    }
    return [];
  }

  public function sbfFileContent(string $node_uuid, string $file_uuid, string $format) {
    try {
      /** @var ContentEntityInterface[] $nodes */
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadByProperties(['uuid' => $node_uuid]);
      $node = reset($nodes);

      if ($node && $node->access('view') && $node->hasField('field_file_drop')) {
        /** @var FileInterface[] $files */
        $files = \Drupal::entityTypeManager()
          ->getStorage('file')
          ->loadByProperties(['uuid' => $file_uuid]);
        $file = reset($files);
        $found = FALSE;
        $files_referenced = $node->get('field_file_drop')->getValue();
        foreach ($files_referenced as $fileinfo) {
          if ($fileinfo['target_id'] == $file->id()) {
            /* @var $found \Drupal\file\Entity\File */
            $found = $file;
            break;
          }
        }
        if($found && isset(MetadataDisplayEntity::ALLOWED_MIMETYPES[$file->getMimeType()])) {
            return file_get_contents($file->getFileUri());
        }
      }
    }
    catch (\Exception $exception) {
      return '';
    }

    return '';
  }

  /**
   * Overrides drupal_escape().
   *
   * Replacement function for Drupal's core Twig's escape filter.
   *
   * @param \Twig\Environment $env
   *   A Twig Environment instance.
   * @param mixed $arg
   *   The value to be escaped.
   * @param string $strategy
   *   The escaping strategy. Defaults to 'html'.
   * @param string $charset
   *   The charset.
   * @param bool $autoescape
   *   Whether the function is called by the auto-escaping feature (TRUE) or by
   *   the developer (FALSE).
   *
   * @return string|null
   *   The escaped, rendered output, or NULL if there is no valid output.
   *
   * @throws \Exception
   *   When $arg is passed as an object which does not implement __toString(),
   *   RenderableInterface or toString().
   */
  public function escapeFilter(Environment $env, $arg, $strategy = 'html', $charset = NULL, $autoescape = FALSE) {
    // Check for a numeric zero int or float.
    if ($arg === 0 || $arg === 0.0) {
      return 0;
    }

    // Return early for NULL and empty arrays.
    if ($arg == NULL) {
      return NULL;
    }

    // Quick and simple. We can only bail out Array keys that are numeric that have also a scalar as value.
    // Many render arrays can be nested of sub-sub things. One does never know.
    if (is_array($arg)) {
      foreach ($arg as $key => $value) {
        if (is_int($key) && is_scalar($value)) {
          \Drupal::logger('format_strawberryfield')->log(LogLevel::WARNING, 'Array can not be printed via Template for key <em>@key</em> with value: <em>@value</em>', [
            '@key' => $key,
            '@value' => $value,
          ]);
          if (!(isset($arg['#printed']) && $arg['#printed'] == TRUE && isset($arg['#markup']) && strlen($arg['#markup']) > 0)) {
            return NULL;
          }
        }
      }
    }

    $this->bubbleArgMetadata($arg);


    // Keep \Twig\Markup objects intact to support autoescaping.
    if ($autoescape && ($arg instanceof TwigMarkup || $arg instanceof MarkupInterface)) {
      return $arg;
    }

    $return = NULL;

    if (is_scalar($arg)) {
      $return = (string) $arg;
    }
    elseif (is_object($arg)) {
      if ($arg instanceof RenderableInterface) {
        $arg = $arg->toRenderable();
      }
      elseif (method_exists($arg, '__toString')) {
        $return = (string) $arg;
      }
      // You can't throw exceptions in the magic PHP __toString() methods, see
      // http://php.net/manual/language.oop5.magic.php#object.tostring so
      // we also support a toString method.
      elseif (method_exists($arg, 'toString')) {
        $return = $arg->toString();
      }
      else {
        throw new \Exception('Object of type ' . get_class($arg) . ' cannot be printed.');
      }
    }

    // We have a string or an object converted to a string: Autoescape it!
    if (isset($return)) {
      if ($autoescape && $return instanceof MarkupInterface) {
        return $return;
      }
      // Drupal only supports the HTML escaping strategy, so provide a
      // fallback for other strategies.
      if ($strategy == 'html') {
        return Html::escape($return);
      }
      return $env->getRuntime(EscaperRuntime::class)->escape($return, $strategy, $charset, $autoescape);
    }

    // This could be a normal render array, which is no longer safe by definition bc renderer is too strict on render arrays
    // Early return if this element was pre-rendered (no need to re-render).
    if (isset($arg['#printed']) && $arg['#printed'] == TRUE && isset($arg['#markup']) && strlen($arg['#markup']) > 0) {
      return $arg['#markup'];
    }

    $arg['#printed'] = FALSE;
    $rendered_value = NULL;
    try {
      $rendered_value = $this->renderer->render($arg);
    }
    catch (\LogicException $e) {
      // We can't just catch any exception. The Ajax responder from the FormBuilder will throw exceptions
      // Just to close the response! Drupal gosh. So hard!
      // This Might fail on an AjaxResponse or anything that is using RenderRoot?
      // I have no solution yet.
      // But will work on Template previews and full page renders.
      \Drupal::logger('format_strawberryfield')->log('error', $e->getMessage(), []);
    }
    return $rendered_value;
  }

  /**
   * Bubbles Twig template argument's cacheability & attachment metadata.
   *
   * For example: a generated link or generated URL object is passed as a Twig
   * template argument, and its bubbleable metadata must be bubbled.
   *
   * @see \Drupal\Core\GeneratedLink
   * @see \Drupal\Core\GeneratedUrl
   *
   * @param mixed $arg
   *   A Twig template argument that is about to be printed.
   *
   * @see \Drupal\Core\Theme\ThemeManager::render()
   * @see \Drupal\Core\Render\RendererInterface::render()
   */
  protected function bubbleArgMetadata($arg) {
    // If it's a renderable, then it'll be up to the generated render array it
    // returns to contain the necessary cacheability & attachment metadata. If
    // it doesn't implement CacheableDependencyInterface or AttachmentsInterface
    // then there is nothing to do here.
    if ($arg instanceof RenderableInterface || !($arg instanceof CacheableDependencyInterface || $arg instanceof AttachmentsInterface)) {
      return;
    }

    $arg_bubbleable = [];
    BubbleableMetadata::createFromObject($arg)
      ->applyTo($arg_bubbleable);

    $this->renderer->render($arg_bubbleable);
  }
}
