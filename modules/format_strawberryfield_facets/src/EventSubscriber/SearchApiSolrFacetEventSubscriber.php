<?php
namespace Drupal\format_strawberryfield_facets\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\search_api_solr\Event\PostSetFacetsEvent;
use Drupal\search_api_solr\Event\PreSetFacetsEvent;
use Drupal\search_api_solr\Event\PreExtractFacetsEvent;


class SearchApiSolrFacetEventSubscriber implements EventSubscriberInterface {

  /**
   * @inheritDoc
   */
  public static function getSubscribedEvents() {
    return [
      SearchApiSolrEvents::PRE_SET_FACETS => 'preSetFacets',
      SearchApiSolrEvents::PRE_EXTRACT_FACETS => 'preExtractFacets',
      SearchApiSolrEvents::POST_SET_FACETS => 'postSetFacets',
    ];
  }

  /**
   * @param \Drupal\search_api_solr\Event\PostSetFacetsEvent $event
   */
  public function postSetFacets(PostSetFacetsEvent $event): void {
    $query = $event->getSearchApiQuery();
    $solarium_query = $event->getSolariumQuery();
    // To get a list of solarium events:
    // @see http://solarium.readthedocs.io/en/stable/customizing-solarium/#plugin-system
    if ($query->getOption('search_api_facets')) {
      // Check if any has search_api_sbf_date_range as 'type'
    }
  }

  /**
   * @param \Drupal\search_api_solr\Event\PreSetFacetsEvent $event
   */
  public function preSetFacets(PreSetFacetsEvent $event): void {
    $query = $event->getSearchApiQuery();
    $solarium_query = $event->getSolariumQuery();
    // To get a list of solarium events:
    // @see http://solarium.readthedocs.io/en/stable/customizing-solarium/#plugin-system
    if ($query->getOption('search_api_facets')) {
      if ($query->getOption('search_api_facets')) {
        // Check if any has search_api_sbf_date_range as 'type'
      }
    }
  }

  /**
   * @param \Drupal\search_api_solr\Event\PreExtractFacetsEvent $event
   */
  public function preExtractFacets(PreExtractFacetsEvent $event): void {
    $query = $event->getSearchApiQuery();
    $solarium_query = $event->getSolariumResult();
    // To get a list of solarium events:
    // @see http://solarium.readthedocs.io/en/stable/customizing-solarium/#plugin-system
    if ($query->getOption('search_api_facets')) {
    }
  }
}
