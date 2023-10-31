<?php


namespace Drupal\format_strawberryfield_views\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\search_api_solr\Event\PostConvertedQueryEvent;
use Solarium\Component\ComponentAwareQueryInterface;

class SearchApiSolrEventSubscriber implements EventSubscriberInterface {

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents() {
    return [
      SearchApiSolrEvents::POST_CONVERT_QUERY => 'convertedQuery'
    ];
  }

  /**
   * @param \Drupal\search_api_solr\Event\PostConvertedQueryEvent $event
   */
  public function convertedQuery(PostConvertedQueryEvent $event): void {
    $query = $event->getSearchApiQuery();
    $solarium_query = $event->getSolariumQuery();
    // To get a list of solarium events:
    // @see http://solarium.readthedocs.io/en/stable/customizing-solarium/#plugin-system
    if ($query->getOption('sbf_advanced_search_filter')) {
      $components = $solarium_query->getComponents();
      if (isset($components['edismax'])) {
        $solarium_query->removeComponent(ComponentAwareQueryInterface::COMPONENT_EDISMAX);
        $solarium_query->addParam('defType','lucene');
      }
    }
    if ($query->getOption('sbf_join_flavor')) {
      $options = $query->getOption('sbf_join_flavor');
      if (is_array($options) &&
        !empty($options['from']) &&
        !empty($options['to']) &&
        !empty($options['v'])) {
        // Options might contain more data used for HL/Advanced search etc.
        // see \Drupal\format_strawberryfield_views\Plugin\views\filter\StrawberryFlavorsJoin::query
        $conjunction = $options['#conjunction'] ?? 'OR'; // Used to connect the join with the main one
        $join_term['from'] = $options['from'];
        $join_term['to'] = $options['to'];
        $subquery = $options['v'];
        $join_term['v'] = '$subquery';
        // $options['method'] = 'topLevelDV' or 'dvWithScore' This requires docvalues to be set on the joined fields
        // This is desired because it will be faster
        // Also adding $options['score'] = 'none' even faster.
        // @TODO enable extra UUID type Solr field to be used as joined ones
        $join = $solarium_query->getHelper()->qparser(
          'join',
          $join_term
        );
        $new_query_string = $solarium_query->getQuery() . " {$conjunction} " . $join;
        $solarium_query->setQuery($new_query_string);
        $solarium_query->addParam(
          'subquery', $subquery
        );
      }
    }
    elseif ($query->getOption('sbf_join_flavor_advanced')) {
      $substitutions = $query->getOption('sbf_join_flavor_advanced');
      if (is_array($substitutions) &&
        !empty($options['from']) &&
        !empty($options['to']) &&
        !empty($options['v'])) {
        $subquery = $options['v'];
        $options['v'] = '$subquery';
        // $options['method'] = 'topLevelDV' or 'dvWithScore' This requires docvalues to be set on the joined fields
        // This is desired because it will be faster
        // Also adding $options['score'] = 'none' even faster.
        // @TODO enable extra UUID type Solr field to be used as joined ones
        $join = $solarium_query->getHelper()->qparser(
          'join',
          $options
        );
        $new_query_string = $solarium_query->getQuery() . ' OR ' . $join;
        $solarium_query->setQuery($new_query_string);
        $solarium_query->addParam(
          'subquery', $subquery
        );
      }
    }
  }
}
