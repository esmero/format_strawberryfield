<?php

namespace Drupal\format_strawberryfield_facets\Plugin\facets_summary\processor;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\facets\Exception\InvalidProcessorException;
use Drupal\facets\Plugin\facets\query_type\SearchApiDate;
use Drupal\facets\Processor\ProcessorInterface;
use Drupal\facets_summary\FacetsSummaryInterface;
use Drupal\facets_summary\Processor\BuildProcessorInterface;
use Drupal\facets_summary\Processor\ProcessorPluginBase;

/**
 * Empty Results and Search Query Facet Summary Processor.
 *
 * @SummaryProcessor(
 *   id = "sbf_last_active_facets",
 *   label = @Translation("Adds Option to remove selected facets when there are no results at all."),
 *   description = @Translation("When checked and no results, a summary of previously selected facets will be calculated from the request."),
 *   stages = {
 *     "build" = 60
 *   }
 * )
 */
class LastActiveFacetsProcessor extends ProcessorPluginBase implements BuildProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function build(FacetsSummaryInterface $facets_summary, array $build, array $facets) {
    $config = $facets_summary->getProcessorConfigs()[$this->getPluginId()];
    $facets_config = $facets_summary->getFacets();
    $results_count = array_sum(array_map(function ($it) {
      /** @var \Drupal\facets\FacetInterface $it */
      return count($it->getResults());
    }, $facets));

    if (($results_count == 0 ) || ($config['settings']['enable_query'] ?? FALSE)) {
      /** @var \drupal\facets\FacetSource\FacetSourcePluginInterface $facet_source_plugin */
      $facet_source_plugin = \Drupal::service(
        'plugin.manager.facets.facet_source'
      )->createInstance($facets_summary->getFacetSourceId());
      $search_id = $facet_source_plugin->getDisplay()->getPluginId();
      $results_query = \Drupal::service('search_api.query_helper')->getResults(
        $search_id
      );
    }

    if ($results_count == 0 && $results_query) {
      $results = [];
      foreach ($facets as $facet) {
        $build_stage_processors = $facet->getProcessorsByStage(ProcessorInterface::STAGE_BUILD, TRUE);
        $active_values = $facet->getActiveItems();
        // Need to reset this bc each facet might have a different Query processor.
        $facet_results = [];
        if (empty($active_values)) {
          continue;
        }
        foreach ($active_values as $active_value) {
          if ($facet->getQueryType() == 'search_api_date' && !is_array($active_value)) {
            if (in_array(
              'date_item', array_keys($build_stage_processors)
            )
            ) {
              try {
                $active_value = $this->getTimestamp($active_value, $build_stage_processors['date_item']->getConfiguration()['granularity'] ?? 1);
              } catch (\Exception $exception) {
                // Do nothing if the String to Timestamp Date failed.
                continue;
              }
            }
          }
          $facet_results[] = [
            'filter' => $active_value,
            'count'  => 1,
          ];
        }

        $configuration = [
          'query' => $results_query->getQuery(),
          'facet' => $facet,
          'results' => $facet_results ?? [],
        ];

        $query_type = \Drupal::service('plugin.manager.facets.query_type')
          ->createInstance(
            $facet->getQueryType(), $configuration
          );
        $query_type->getConfiguration();
        $query_type->build();
        // This will just rebuild the widget bc the facets
        // were already built and statically cached so ...\Drupal::service('facets.manager')->build($facet);
        $facet_results = $facet->getResults();
        foreach ($build_stage_processors as $processor) {
          if (!$processor instanceof
            \Drupal\facets\Processor\BuildProcessorInterface
          ) {
            throw new InvalidProcessorException("The processor {$processor->getPluginDefinition()['id']} has a build definition but doesn't implement the required BuildProcessorInterface interface");
          }
          $facet_results = $processor->build($facet, $facet_results);
        }
        $facet->setResults($facet_results);
        // Accumulate URLs?
        foreach( $facet->getResults() as $result) {
          $urls[] = $result->getUrl();
        }
        $show_count = $facets_config[$facet->id()]['show_count'];
        $results = array_merge(
          $results, $this->buildResultTree($show_count, $facet->getResults())
        );
      }

      $build['#items'] = $results;
      if ($config['settings']['enable_empty_message'] ?? FALSE) {
        $item = [
          '#theme' => 'facets_summary_empty',
          '#message' => [
            '#type' => 'processed_text',
            '#text' => $config['settings']['text']['value'],
            '#format' => $config['settings']['text']['format'],
          ],
        ];
        array_unshift($build['#items'], $item);
      }
    }

    if (($config['settings']['enable_query'] ?? FALSE) && $results_query) {
      // The original View
      /** @var \Drupal\views\ViewExecutable $view */
      $view = $results_query->getQuery()->getOptions()['search_api_view'];
      if ($view->getDisplay()->displaysExposed()) {
        $exposed_input = $view->getExposedInput();
        $view->getRequest()->getRequestUri();
        $keys_to_filter = [];
        $key_with_search_value = [];
        // Oh gosh, blocks...
        if (!$view->getDisplay()->isDefaultDisplay() && empty($view->getDisplay()->options['filters'] ?? [] )) {
          $filters = $view->getDisplay()->handlers['filter'];
          foreach ($filters as $filter) {
            /* @var \Drupal\views\Plugin\views\ViewsHandlerInterface $filter */
            if ($filter->getPluginId() == 'search_api_fulltext' && $filter->isExposed()) {
              $keys_to_filter[] = $filter->options['expose']['operator_id'] ?? NULL;
              $keys_to_filter[] = $filter->options['expose']['identifier'] ?? NULL;
              $key_with_search_value[] = $filter->options['expose']['identifier'] ?? NULL;
            }
            elseif ($filter->getPluginId() == 'sbf_advanced_search_api_fulltext'
              && $filter->isExposed()
            ) {
              $current_count = 1;
              $field_count_field = NULL;
              if ($filter->options['expose']['identifier'] ?? NULL ) {
                $field_count_field = $filter->options['expose']['identifier'] . '_advanced_search_fields_count';
                $current_count = $exposed_input[$field_count_field] ?? ($filter->options['expose']['advanced_search_fields_count'] ?? 1);
              }
              $extra_keys_to_filter = [];
              $extra_keys_to_filter_flat = [];
              $keys_to_filter[] = $filter->options['expose']['operator_id'] ?? NULL;
              $keys_to_filter[] = $filter->options['expose']['identifier'] ?? NULL;

              $key_with_search_value[] = $filter->options['expose']['identifier'] ?? NULL;
              $keys_to_filter[] = $filter->options['expose']['searched_fields_id'] ?? NULL;
              $keys_to_filter[] = $filter->options['expose']['advanced_search_operator_id']
                ?? NULL;
              // This adds the base keys in position 0 since they don't have a suffix.
              $extra_keys_to_filter[0] = $keys_to_filter;
              foreach ($keys_to_filter as $key_to_filter) {
                for (
                  $i = 1;
                  $i < $filter->options['expose']['advanced_search_fields_count'] ?? 1;
                  $i++
                ) {
                  $extra_keys_to_filter[$i][] = $key_to_filter . '_' . $i;
                  $extra_keys_to_filter_flat[] = $key_to_filter . '_' . $i;
                  if (in_array($key_to_filter, $key_with_search_value)){
                    if ($i < $current_count) {
                      $key_with_search_value[$i] = $key_to_filter . '_' . $i;
                    }
                  }
                }
              }
              // adds the $field_count_field back. If empty the array filter will get rid of it
              $keys_to_filter[] = $field_count_field;
              $keys_to_filter = array_merge(
                $keys_to_filter, $extra_keys_to_filter_flat
              );

              $keys_to_filter = array_unique($keys_to_filter);
              $keys_to_filter = array_filter($keys_to_filter);
            }
          }
        }
        else {
          foreach ($view->getDisplay()->options['filters'] as $filter) {
            if ($filter['plugin_id'] == 'search_api_fulltext'
              && $filter['exposed']
            ) {
              $keys_to_filter[] = $filter['expose']['operator_id'] ?? NULL;
              $keys_to_filter[] = $filter['expose']['identifier'] ?? NULL;
              $key_with_search_value[] = $filter['expose']['identifier'] ??
                NULL;
            }
            elseif ($filter['plugin_id'] == 'sbf_advanced_search_api_fulltext'
              && $filter['exposed']
            ) {
              $current_count = 1;
              $field_count_field = NULL;
              if ($filter['expose']['identifier'] ?? NULL ) {
                $field_count_field = $filter['expose']['identifier'] . '_advanced_search_fields_count';
                $current_count = $exposed_input[$field_count_field] ?? ($filter['expose']['advanced_search_fields_count'] ?? 1);
              }

              $extra_keys_to_filter = [];
              $extra_keys_to_filter_flat = [];
              $keys_to_filter[] = $filter['expose']['operator_id'] ?? NULL;
              $keys_to_filter[] = $filter['expose']['identifier'] ?? NULL;
              $key_with_search_value[] = $filter['expose']['identifier'] ??
                NULL;
              $keys_to_filter[] = $filter['expose']['searched_fields_id'] ??
                NULL;
              $keys_to_filter[]
                = $filter['expose']['advanced_search_operator_id']
                ?? NULL;
              $extra_keys_to_filter[0] = $keys_to_filter;
              foreach ($keys_to_filter as $key_to_filter) {
                for (
                  $i = 1;
                  $i < $filter['expose']['advanced_search_fields_count'] ?? 1;
                  $i++
                ) {
                  $extra_keys_to_filter[$i][] = $key_to_filter . '_' . $i;
                  $extra_keys_to_filter_flat[] = $key_to_filter . '_' . $i;
                  if (in_array($key_to_filter, $key_with_search_value)) {
                    if ($i < $current_count) {
                      $key_with_search_value[$i] = $key_to_filter . '_' . $i;
                    }
                  }
                }
              }


              $keys_to_filter[] = $field_count_field;
              $keys_to_filter = array_merge(
                $keys_to_filter, $extra_keys_to_filter_flat
              );
              $keys_to_filter = array_unique($keys_to_filter);
              $keys_to_filter = array_filter($keys_to_filter);
            }
          }
        }

        $request = $view->getRequest();
        $facet_source = $facets_summary->getFacetSource();

        $urlGenerator = \Drupal::service('facets.utility.url_generator');
        $url_active = $urlGenerator->getUrlForRequest(
          $request, $facet_source ? $facet_source->getPath() : NULL
        );
        $params = $request->query->all();
        $search_terms = [];
        $query_params = [];
        foreach ($params as $key => $param) {
          if (in_array($key, $keys_to_filter)) {
            $numeric = array_search($key, $key_with_search_value);
            if ($numeric !== FALSE) {
              $search_terms[$numeric] = $exposed_input[$key] ?? NULL;
            }
            $query_params[$key] = $params[$key];
            unset($params[$key]);
          }
        }

        $search_terms = array_filter($search_terms, function ($element) {
          return ((is_string($element) && '' !== trim($element)) || is_numeric($element));
        });

        if (!($config['settings']['multiple_query'] ?? FALSE) || count($search_terms) == 1) {
          if (count($search_terms)) {
            $url_active->setOption('query', $params);
            $display_value = '';
            if ($config['settings']['quote_query'] ?? FALSE) {
              $display_value = implode(
                " ", array_map(
                  function ($string) {
                    return '"' . $string . '"';
                  }, $search_terms
                )
              );
            } else {
              $display_value = implode(" ", $search_terms);
            }

            $item = [
              '#theme' => 'facets_result_item__summary',
              '#value' => $display_value,
              '#show_count' => FALSE,
              '#count' => 0,
              '#is_active' => TRUE,
            ];
            $item = (new Link($item, $url_active))->toRenderable();
            $item['#wrapper_attributes'] = [
              'class' => [
                'facet-summary-item--facet',
                'facet-summary-item--query',
              ],
            ];
            $build['#items'][] = $item;
          }
        }
        elseif ($config['settings']['multiple_query'] ?? FALSE) {
          foreach($search_terms as $numeric_key => $search_term ) {
            $search_term_array = [];
            $search_term_array[] =  $search_term;
            $new_url = clone $url_active;
            $new_params = $query_params;
            foreach ($extra_keys_to_filter[$numeric_key] ?? [] as $groupedkey) {
                unset($new_params[$groupedkey]);
            }
            // Check if $extra_keys_to_filter_excluding also contains the search term?

            $new_params = $params + $new_params;
            $new_url->setOption('query', $new_params);
            if ($config['settings']['quote_query'] ?? FALSE) {
              $display_value = implode(
                " ", array_map(
                  function ($string) {
                    ltrim($string, '"');
                    rtrim($string, '"');
                    $string = str_replace('"', "'", $string);
                    return '"' . $string . '"';
                  }, $search_term_array
                )
              );
            } else {
              $display_value = implode(" ", $search_term_array);
            }

            $item = [
              '#theme' => 'facets_result_item__summary',
              '#value' => $display_value,
              '#show_count' => FALSE,
              '#count' => 0,
              '#is_active' => TRUE,
            ];
            $item = (new Link($item, $new_url))->toRenderable();
            $item['#wrapper_attributes'] = [
              'class' => [
                'facet-summary-item--facet',
                'facet-summary-item--query',
              ],
            ];
            $build['#items'][] = $item;
          }
        }
      }
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, FacetsSummaryInterface $facets_summary) {
    // By default, there should be no config form.
    $config = $this->getConfiguration();
    $build['message'] = [
      '#type' => 'markup',
      '#markup' => $this->t('It is recommended to disable the `Show a text when there are no results` Processor when using this one.')
    ];
    $build['enable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Facet Summary when no results'),
      '#default_value' => $config['enable'],
    ];
    $build['enable_query'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Provide a Summary for the associated Facet Source Query Terms. This might be enabled even if no Results'),
      '#default_value' => $config['enable_query'],
    ];
    $build['multiple_query'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Separate each Query Term into its own facet summary. This might be enabled even if no Results'),
      '#default_value' => $config['multiple_query'],
      '#states' => [
        'visible' => [
          ':input[name="facets_summary_settings[sbf_last_active_facets][settings][enable_query]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $build['quote_query'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('If Query Terms should be surrounded by Double Quotes. If the query itself is surrounded by double quotes those will be removed and internal ones replaced by single ones (just as output, the real value will be untouched) to differentiate them.'),
      '#default_value' => $config['quote_query'],
      '#states' => [
        'visible' => [
          ':input[name="facets_summary_settings[sbf_last_active_facets][settings][enable_query]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $build['enable_empty_message'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Provide a Facet Summary when no results'),
      '#default_value' => $config['enable_empty_message'],
    ];
    $build['text'] = [
      '#type' => 'text_format',
      '#title' => $this->t('Empty text'),
      '#format' => $config['text']['format'],
      '#editor' => TRUE,
      '#default_value' => $config['text']['value'],
    ];


    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'enable' => TRUE,
      'enable_empty_message' => TRUE,
      'enable_query' => FALSE,
      'multiple_query' => FALSE,
      'quote_query' => TRUE,
      'text' => [
        'format' => 'plain_text',
        'value' => $this->t('No results found.'),
      ],
    ];
  }

  /**
   * Build result tree, taking possible children into account.
   *
   * @param bool $show_count
   *   Show the count next to the facet.
   * @param \Drupal\facets\Result\ResultInterface[] $results
   *   Facet results array.
   *
   * @return array
   *   The rendered links to the active facets.
   */
  protected function buildResultTree(bool $show_count, array $results) {
    $items = [];
    foreach ($results as $result) {
      if ($result->isActive()) {
        $item = [
          '#theme' => 'facets_result_item__summary',
          '#value' => $result->getDisplayValue(),
          '#show_count' => $show_count,
          '#count' => 0,
          '#is_active' => TRUE,
          '#facet' => $result->getFacet(),
          '#raw_value' => $result->getRawValue(),
        ];
        $item = (new Link($item, $result->getUrl()))->toRenderable();
        $item['#wrapper_attributes'] = [
          'class' => [
            'facet-summary-item--facet',
          ],
        ];
        $items[] = $item;
      }
      if ($children = $result->getChildren()) {
        $items = array_merge($items, $this->buildResultTree($children));
      }
    }
    return $items;
  }

  protected function getTimestamp($value, int $granularity) {

    switch ($granularity) {
      case SearchApiDate::FACETAPI_DATE_YEAR:
        $format = 'Y';
        break;
      case SearchApiDate::FACETAPI_DATE_MONTH:
        $format = 'Y-m';
        break;

      case SearchApiDate::FACETAPI_DATE_DAY:
        $format = 'Y-m-d';
        break;

      case SearchApiDate::FACETAPI_DATE_HOUR:
        $format = 'Y-m-d\TH';
        break;

      case SearchApiDate::FACETAPI_DATE_MINUTE:
        $format = 'Y-m-d\TH:i';
        break;

      default:
        $format = 'Y-m-d\TH:i:s';
        break;
    }

    $date = \DateTime::createFromFormat($format, $value);
    return $date->getTimestamp();
  }

}
