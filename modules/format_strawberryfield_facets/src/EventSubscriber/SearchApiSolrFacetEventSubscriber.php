<?php
namespace Drupal\format_strawberryfield_facets\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\search_api_solr\Event\SearchApiSolrEvents;
use Drupal\search_api_solr\Event\PostSetFacetsEvent;
use Drupal\search_api_solr\Event\PreSetFacetsEvent;
use Drupal\search_api_solr\Event\PreExtractFacetsEvent;
use Solarium\Component\Facet\JsonRange;


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
    /* @var $solarium_query \Solarium\Core\Query\QueryInterface  */
    $solarium_query = $event->getSolariumQuery();
    // To get a list of solarium events:
    // @see http://solarium.readthedocs.io/en/stable/customizing-solarium/#plugin-system
    // This option is set by \Drupal\format_strawberryfield_facets\Plugin\facets\query_type\SearchApiDateRange
    if ($query->getOption('sbf_date_stats_field')) {
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
    if ($query->getOption('sbf_date_stats_field')) {
      $solr_field_names = $query->getIndex()->getServerInstance()->getBackend()->getSolrFieldNames($query->getIndex());
      $facet_set = $solarium_query->getFacetSet();
      //['local_key' => 'priceranges', 'field' => 'price', 'start'=>1 ,'end'=>300,'gap'=>100, 'other'=>JsonRange::OTHER_ALL]
      foreach( $query->getOption('sbf_date_stats_field') as $sbf_date_ranges) {
      /*$facet_set->createJsonFacetRange([
        'local_key' => $sbf_date_ranges['field'].'-sbf-date-stats',
        'field' => $solr_field_names[$sbf_date_ranges['field']],
        'start' => '1876-01-01T00:00:00Z',
        'end' => '2025-01-01T00:00:00Z',
        'gap' => '+10YEAR',
        'other'=> JsonRange::OTHER_ALL
      ]);*/
      $stats = $solarium_query->getStats();
      $stats->createField('{!min=true max=true distinctValues=false countDistinct=false}'.$solr_field_names[$sbf_date_ranges['field']]);
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
    if ( $query->getOption('sbf_date_stats_field')) {
    }
  }
}
