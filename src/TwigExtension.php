<?php

namespace Drupal\format_strawberryfield;

use Drupal\Component\Utility\Html;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigTest;
use Twig\TwigFilter;
use Twig\TwigFunction;
use League\HTMLToMarkdown\HtmlConverter;
use Drupal\format_strawberryfield\CiteProc\Render;

/**
 * Class TwigExtension.
 *
 * @package Drupal\format_strawberryfield
 */
class TwigExtension extends AbstractExtension {

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
    ];
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
    string $bundle_identifier = '',
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
    $bundle_identifier = trim($bundle_identifier);
    $label_field = $fields[$entity_type][0] ?? NULL;
    if ($label_field) {
      $bundle_field = $fields[$entity_type][1] ?? NULL;
      $limit = min((int) $limit, 100);
      $label = trim($label);
      try {
        /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
        $query = \Drupal::entityTypeManager()
          ->getStorage($entity_type)
          ->getQuery();
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
   * @param array $value
   * @param array $styles
   * @param string $locale
   *
   * @return string
   */
  public function bibliography(array $value, string $locale, array $styles = []): string {

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

    $json_string = json_encode($value);
    $json_data = json_decode($json_string);
    $json_error = json_last_error();
    if ($json_error != JSON_ERROR_NONE) {
      return $json_error;
    }
    $render = new Render();
    if ($locale) {
        $bibliography = $render->bibliography($locale, $styles, $json_data);
    }
    else {
      $bibliography = $render->bibliography(null, $styles, $json_data);
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
    $rendered_bibliography = \Drupal::service('renderer')->render($render_bibliography);
    return $rendered_bibliography;
  }
  public function clipboardCopy(string $copyButtonCssClass, string $copyContentCssClass) {

    $uniqueid = Html::getUniqueId('clipboard-copy');
    $button_html = [
      '#type' => 'container',
      '#attributes' => [
        'id' => $uniqueid,
        'class' => ['clipboard-copy'],
        'data-clipboard-copy-button' => $copyButtonCssClass,
        'data-clipboard-copy-content' => $copyContentCssClass,
      ],
      '#attached' => [
        'library' => [
          'format_strawberryfield/clipboard_copy',
          'format_strawberryfield/clipboard_copy_strawberry',
         ],
      ],
    ];
    $rendered_button = \Drupal::service('renderer')->render($button_html);
    return $rendered_button;

  }
}
