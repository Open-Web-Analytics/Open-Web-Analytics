<?php

namespace OWA\Tests;

/**
 * Captures what each report controller DECLARES, so a conversion can be shown
 * to preserve it.
 *
 * A report is defined by the query it will issue and the widgets it declares,
 * not by the pixels it eventually produces. Every one of the config-driven
 * controllers expresses both as a bag of key/value pairs set during action(),
 * so that bag IS the report -- and comparing it before and after a rewrite is
 * the only available definition of "did not change the report".
 *
 * SCOPE: the config-driven reports only. The bespoke ones prefetch result sets
 * through the data-access layer, so what they hold depends on a database and on
 * which rows happen to exist; snapshotting that would produce a fixture that
 * passes on one machine. Those are listed explicitly below rather than filtered
 * by a predicate, so the exclusion is a decision someone made and can revisit
 * rather than an accident of a regex.
 */
final class ReportCharacterizationHarness
{
    /**
     * Reports that prefetch data, and are therefore not characterizable this
     * way. Each keeps its controller through the conversion (see the design
     * note), so none of these is a report the harness needs to protect.
     */
    private const PREFETCHING = array(
        'ReportDomstreams',
        'ReportGoalFunnel',
    );

    /**
     * Files under Controller/Report*.php that are not reports.
     *
     * ReportsRest is the REST data-endpoint controller -- its "report names"
     * are hand-written queries, not report configurations.
     *
     * Report is the dispatcher that resolves a reportId. It matches the glob
     * because of where it lives, and this harness noticed the moment it was
     * added, which is the drift detection working rather than a nuisance.
     */
    private const NOT_A_REPORT = array( 'ReportsRest', 'Report' );

    /**
     * A fixed value for every request parameter a report reads.
     *
     * The 20 parameterised reports interpolate a URL parameter into their
     * constraints and title. Snapshotting them with no parameter would leave
     * the one part that differs from a pure config report completely untested,
     * so every parameter is supplied -- the same sentinel everywhere, so the
     * snapshot shows WHERE it lands rather than what it is.
     *
     * LOWERCASE deliberately. Several detail reports normalise the value with
     * strtolower() before building their constraint -- ReportAdDetail,
     * ReportCampaignDetail and ReportSourceDetail among them -- so a
     * mixed-case sentinel would arrive changed and every check for it would
     * have to be loosened to case-insensitive. A sentinel that is a fixed point
     * of that transformation keeps the assertions exact, so a report that
     * mangles the value in any OTHER way still fails loudly.
     */
    /**
     * Report id => the controller class that used to implement it.
     *
     * These 35 reports are configuration now: modules/Base/reports/<id>.json,
     * rendered by Core\ConfiguredReport. The map is what lets the conversion
     * keep being checked after the controllers are gone -- the golden file
     * records what each of them DECLARED, and that record is still the standard
     * the JSON has to meet.
     *
     * So the fixture stays keyed by class name on purpose. It is a record of
     * what those controllers did, not a directory of reports that exist now;
     * re-keying it to report ids would rewrite the evidence.
     */
    public const CONVERTED = array(
        'action-detail'             => 'ReportActionDetail',
        'action-group'              => 'ReportActionGroup',
        'action-groups'             => 'ReportActionGroups',
        'action-tracking'           => 'ReportActionTracking',
        'attribution-history'       => 'ReportAttributionHistory',
        'document'                  => 'ReportDocument',
        'visitors'                  => 'ReportVisitors',
        'dom-clicks'                => 'ReportDomClicks',
        'campaigns'                 => 'ReportCampaigns',
        'referral-detail'           => 'ReportReferralDetail',
        'ad-detail'                 => 'ReportAdDetail',
        'ad-type-detail'            => 'ReportAdTypeDetail',
        'ad-types'                  => 'ReportAdTypes',
        'ads'                       => 'ReportAds',
        'anchortext'                => 'ReportAnchortext',
        'avg-order-value'           => 'ReportAvgOrderValue',
        'browser-detail'            => 'ReportBrowserDetail',
        'browsers'                  => 'ReportBrowsers',
        'campaign-detail'           => 'ReportCampaignDetail',
        'commerce'                  => 'ReportCommerce',
        'content'                   => 'ReportContent',
        'dashboard'                 => 'ReportDashboard',
        'country-detail'            => 'ReportCountryDetail',
        'creative-performance'      => 'ReportCreativePerformance',
        'days-to-purchase'          => 'ReportDaysToPurchase',
        'ecommerce'                 => 'ReportEcommerce',
        'ecommerce-conversion-rate' => 'ReportEcommerceConversionRate',
        'entry-pages'               => 'ReportEntryPages',
        'exit-pages'                => 'ReportExitPages',
        'feeds'                     => 'ReportFeeds',
        'geolocation'               => 'ReportGeolocation',
        'goals'                     => 'ReportGoals',
        'host-detail'               => 'ReportHostDetail',
        'hosts'                     => 'ReportHosts',
        'keyword-detail'            => 'ReportKeywordDetail',
        'keywords'                  => 'ReportKeywords',
        'os'                        => 'ReportOs',
        'os-detail'                 => 'ReportOsDetail',
        'page-type-detail'          => 'ReportPageTypeDetail',
        'page-types'                => 'ReportPageTypes',
        'pages'                     => 'ReportPages',
        'product-categories'        => 'ReportProductCategories',
        'product-category-detail'   => 'ReportProductCategoryDetail',
        'product-detail'            => 'ReportProductDetail',
        'product-sku-detail'        => 'ReportProductSkuDetail',
        'product-skus'              => 'ReportProductSkus',
        'products'                  => 'ReportProducts',
        'referral-link-text-detail' => 'ReportReferralLinkTextDetail',
        'referring-sites'           => 'ReportReferringSites',
        'revenue'                   => 'ReportRevenue',
        'search-engine-detail'      => 'ReportSearchEngineDetail',
        'search-engines'            => 'ReportSearchEngines',
        'source-detail'             => 'ReportSourceDetail',
        'sources'                   => 'ReportSources',
        'state-detail'              => 'ReportStateDetail',
        'traffic'                   => 'ReportTraffic',
        'transactions'              => 'ReportTransactions',
        'visitors-age'              => 'ReportVisitorsAge',
        'visitors-loyalty'          => 'ReportVisitorsLoyalty',
        'visitors-recency'          => 'ReportVisitorsRecency',
        'visits-to-purchase'        => 'ReportVisitsToPurchase',
    );

    /**
     * Definitions written as definitions, with no controller behind them.
     *
     * CONVERTED maps a report to the controller it replaced, which was every
     * definition while the conversion was the only way one came to exist. It is
     * not any more: the format exists so reports can be AUTHORED, and the first
     * one that is has no predecessor to be equivalent to.
     *
     * Kept separate rather than folded in, because the two mean different
     * things -- a converted report has a recorded standard to meet, an authored
     * one has only its own baseline.
     */
    public const AUTHORED = array( 'clicks', 'latest-visits' );

    public const SENTINEL = 'characterization_sentinel';

    /**
     * Every in-scope report, named the way the fixture names it.
     *
     * Two kinds now: reports still implemented by a controller, and the
     * converted ones, which are JSON. Both are named by the controller class,
     * because the fixture records what those controllers DECLARED and that
     * record is the standard a conversion has to meet -- renaming the keys
     * because the implementation moved would discard the evidence.
     *
     * So a converted report leaves this list only when it stops being a report,
     * never merely because its file was deleted.
     *
     * @return array<int, string> sorted
     */
    /**
     * Widgets deliberately re-typed since the conversion, and what they were.
     *
     * The golden fixture records what a CONTROLLER declared, and these widgets
     * no longer declare it -- on purpose. A half-width `grid` drew a dimension
     * picker and a filter above it, and at half the row that bar did not fit,
     * so the widget grew a horizontal scrollbar. Each of these asks for one
     * metric against one dimension, which is exactly what a `grid-card` is: the
     * same table at the same width, without the controls it had no room for.
     *
     * Named one by one rather than allowed as a class, and here rather than in
     * either test, because BOTH read this fixture -- one keyed by report id and
     * one by class name -- and two copies of the list is how they come to
     * disagree about what is excused.
     *
     * Everything else about these widgets is still held to the record: the
     * query, the sort, the link, the colspan. A fifth widget converted later
     * fails until somebody writes down which one and why.
     *
     * class => [ widget id => [ what the controller declared, what it is now ] ]
     */
    public const RETYPED = array(
        'ReportActionTracking' => array( 'actionsByGroup' => array( 'grid', 'grid-card' ),
                                         'actionsByName'  => array( 'grid', 'grid-card' ),
                                         'trend'          => array( 'trend', 'trend-card' ) ),

        /*
         * Site Metrics is a trend CARD -- its totals above the chart, its own
         * metrics rather than the report's -- and Top Content is a table card
         * beside it, which is the pairing that fills the top row.
         */
        'ReportDashboard'      => array( 'siteTrend'      => array( 'trend', 'trend-card' ),
                                         'topContent'     => array( 'grid', 'grid-card' ) ),
        'ReportContent'        => array( 'toppagetypes'   => array( 'grid', 'grid-card' ),
                                         'toppages'       => array( 'grid', 'grid-card' ),
                                         // Page views, pages per visit and
                                         // bounce rate above the shape, at half
                                         // a row.
                                         'trend'          => array( 'trend', 'trend-card' ) ),
        'ReportEcommerce'      => array( 'productName'    => array( 'grid', 'grid-card' ) ),
        'ReportTraffic'        => array( 'trend'          => array( 'trend', 'trend-card' ) ),
        'ReportVisitors'       => array( 'browserTypes'   => array( 'grid', 'grid-card' ),
                                         'trend'          => array( 'trend', 'trend-card' ) ),
    );

    /**
     * Put a deliberately re-typed widget back to what the controller declared,
     * so everything ELSE about it is still compared.
     *
     * The allowance is checked as it is applied, and the problems come back for
     * the caller to assert on: a listed widget that is not there, or that is
     * not carrying the new type, is reported rather than quietly excusing
     * nothing. Otherwise a stale entry would be an allowance for a widget that
     * no longer exists -- which reads as coverage and is not.
     *
     * @param string $class the controller the fixture recorded
     * @param array  $config the declared bag, as it is now
     * @return array{config: array, problems: array<int, string>}
     */
    public static function undoRetyping( string $class, array $config ): array
    {
        $expected = self::RETYPED[ $class ] ?? array();

        if ( ! $expected ) {

            return array( 'config' => $config, 'problems' => array() );
        }

        $problems = array();
        $seen     = array();

        foreach ( (array) ( $config['widgets'] ?? array() ) as $i => $widget ) {

            $widgetId = (string) ( ( (array) $widget )['id'] ?? '' );

            if ( ! isset( $expected[ $widgetId ] ) ) {

                continue;
            }

            list( $was, $now ) = $expected[ $widgetId ];

            $is = ( (array) $widget )['type'] ?? null;

            if ( $is !== $now ) {

                $problems[] = sprintf(
                    '%s:%s is listed as re-typed to "%s" but is "%s" -- either it '
                  . 'changed again or the allowance is stale',
                    $class, $widgetId, $now, is_string( $is ) ? $is : gettype( $is ) );

                continue;
            }

            $config['widgets'][ $i ]['type'] = $was;

            $seen[] = $widgetId;
        }

        foreach ( array_diff( array_keys( $expected ), $seen ) as $missing ) {

            $problems[] = sprintf( '%s lists re-typed widget "%s", which its definition '
                . 'does not contain', $class, $missing );
        }

        return array( 'config' => $config, 'problems' => $problems );
    }

    /**
     * Widgets deliberately MOVED or RESIZED since the conversion, and why.
     *
     * The golden fixture records the layout a controller declared. A report
     * being redesigned no longer has that layout on purpose -- the dashboard's
     * Latest Visits grid now sits directly under the site trend at full width,
     * because it groups by seven dimensions and half a row was never enough
     * room for them.
     *
     * NAMED, and narrow. Only the widgets listed here may differ in position or
     * span; every other widget must still be in its recorded place with its
     * recorded size, the widget id set must still match exactly, and every
     * other key of every widget -- query, sort, title, link -- is still
     * compared. Relaying a report out is not a licence to change what it asks
     * for.
     *
     * class => [ widget ids whose position and span are no longer the record's ]
     */
    /**
     * Widgets ADDED since the conversion, by the report they were added to.
     *
     * The golden file records what a controller declared, and a report that has
     * since grown a widget declares more than that. Without naming them, the
     * comparison fails wholesale and says only that two bags differ -- which
     * would leave a converted report unable to gain a widget without giving up
     * the proof that the rest of it still matches.
     *
     * So an addition is named here and dropped from the comparison, and
     * EVERYTHING ELSE about the report goes on being compared key for key. The
     * allowance is checked as it is applied: a listed widget that is not there
     * is reported, so an entry cannot outlive the widget it describes.
     *
     * This does NOT excuse removals. A widget the record has and the definition
     * does not still fails, which is the direction that loses a report
     * something.
     */
    public const ADDED = array(

        /*
         * Entry pages, exit pages and the most-clicked elements, as cards
         * beside Top Page Types. The first two were only reachable through the
         * links block at the bottom; the third is new reporting.
         */
        'ReportContent' => array( 'entrypages', 'exitpages', 'clickedelements' ),

        /*
         * One card per way of looking at a visitor -- where they came from, how
         * often, how recently, how long ago they first arrived. Each leads to
         * the report that details it, which is what the links block used to do
         * by naming them.
         */
        'ReportVisitors' => array( 'domains', 'loyalty', 'recency', 'age' ),

        /*
         * Where traffic ARRIVED (entry pages), what it was split by (a pie per
         * medium, source and campaign), and the two reports the links block
         * named without showing: referring sites and countries.
         */
        'ReportTraffic' => array(
            'entryPages', 'source', 'campaign', 'referringSites', 'countries' ),
    );

    /**
     * Take the added widgets out, so the rest of the report is still compared.
     *
     * @return array{config: array, problems: array<int, string>}
     */
    public static function undoAdding( string $class, array $config ): array
    {
        $added = self::ADDED[ $class ] ?? array();

        if ( ! $added ) {

            return array( 'config' => $config, 'problems' => array() );
        }

        $problems = array();
        $seen     = array();
        $kept     = array();

        foreach ( (array) ( $config['widgets'] ?? array() ) as $widget ) {

            $id = (string) ( ( (array) $widget )['id'] ?? '' );

            if ( in_array( $id, $added, true ) ) {

                $seen[] = $id;

                continue;
            }

            $kept[] = $widget;
        }

        foreach ( array_diff( $added, $seen ) as $missing ) {

            $problems[] = sprintf( '%s lists "%s" as added, but the definition does not have '
                . 'it -- the allowance is stale', $class, $missing );
        }

        $config['widgets'] = $kept;

        return array( 'config' => $config, 'problems' => $problems );
    }

    /**
     * Widgets deliberately REMOVED since the conversion.
     *
     * The counterpart of ADDED, and kept separate from it on purpose: an
     * addition is a report gaining something and a removal is a report losing
     * it, and the second is the one worth being asked about twice. Both are
     * named rather than tolerated, and both are checked as they are applied --
     * a widget listed here that is STILL in the definition fails, so an entry
     * cannot outlive the removal it describes.
     *
     * class => [ widget ids the definition no longer has ]
     */
    public const REMOVED = array(

        /*
         * Latest Visits and the links block. The roster of visits is its own
         * report and was duplicated here at half a row; the links block listed
         * four reports that are now cards on this page, each showing what it
         * links to instead of naming it.
         */
        'ReportVisitors' => array( 'latestVisits', 'visitorReports' ),

        /*
         * The three "Visits From ..." boxes, Top Keywords, and the links block.
         *
         * The boxes counted visits by medium and the pie beside them drew the
         * same split; there are three pies now -- medium, source, campaign --
         * and a number stated once beside the shape of it is the same fact
         * twice. Top Keywords has its own report and the search-terms grid on
         * it is not a summary of anything. The links block named Referring Web
         * Sites and the rest; two of them are cards on this report now, which
         * is the same journey with the data already on it.
         *
         * The empty string is the links block: it declares no id, which is
         * legal, and an id-less widget keys as ''.
         */
        'ReportTraffic' => array(
            'fromsearch', 'fromdirect', 'fromreferral', 'topkeywords', '' ),
    );

    /**
     * Take the removed widgets out of the RECORD, so the rest still compares.
     *
     * @return array{expected: array, problems: array<int, string>}
     */
    public static function undoRemoval( string $class, array $expected, array $actual ): array
    {
        $removed = self::REMOVED[ $class ] ?? array();

        if ( ! $removed ) {

            return array( 'expected' => $expected, 'problems' => array() );
        }

        $problems = array();

        $stillThere = array();

        foreach ( (array) ( $actual['widgets'] ?? array() ) as $widget ) {

            $stillThere[] = (string) ( ( (array) $widget )['id'] ?? '' );
        }

        $kept = array();

        foreach ( (array) ( $expected['widgets'] ?? array() ) as $widget ) {

            $id = (string) ( ( (array) $widget )['id'] ?? '' );

            if ( in_array( $id, $removed, true ) ) {

                if ( in_array( $id, $stillThere, true ) ) {

                    $problems[] = sprintf( '%s lists "%s" as removed, but the definition still '
                        . 'has it -- the allowance is stale', $class, $id );
                }

                continue;
            }

            $kept[] = $widget;
        }

        $expected['widgets'] = $kept;

        return array( 'expected' => $expected, 'problems' => $problems );
    }

    public const RELAID_OUT = array(
        // Latest Visits moved under the site trend at full width -- it groups
        // by seven dimensions and half a row was never enough. The other three
        // went to a quarter each, which is a row of four across.
        /*
         * ...and since: Latest Visits went to the END, Actions to just after
         * Traffic Sources, and Top Referrers to a quarter. Site Metrics states
         * no span at all now -- half a row is what a trend card IS, so the
         * width comes from the type.
         *
         * topContent is NOT here, and that is worth saying: it became a
         * half-width card immediately after the trend, which is exactly where
         * and how the original controller had it. The allowance it used to need
         * is gone because the layout came back to the record.
         */
        'ReportDashboard' => array( 'latestVisits', 'actions', 'topReferrers', 'siteTrend' ),

        /*
         * Top Pages and Top Page Types became quarter-width cards, and the
         * report-links block beside them matches.
         *
         * The empty string is that report-links widget: it declares no id,
         * which is legal -- only the widgets something addresses need one --
         * and the index below keys an id-less widget as ''. Naming it that way
         * rather than giving it an id keeps this a layout allowance instead of
         * also adding a key the record does not have.
         */
        /*
         * Only the page-types card and the links block beside it. toppages used
         * to be here and is not any more: it is back at half a row immediately
         * after the trend, which is exactly where and how the controller had
         * it. The trend never needed an entry -- it declared no span then and
         * declares none now; what changed is that half a row is the trend
         * card's own default rather than a number it states.
         */
        'ReportContent'  => array( 'toppagetypes', '' ),

        // Prior/Next pages, the heatmap link and the related reports are a row
        // of four quarters.
        'ReportDocument' => array( 'priorpages', 'nextpages', 'heatmap', 'moreAnalytics' ),

        /*
         * The trend states its width now. A trend card is half a row by
         * default, and this one is meant to run the whole way across, so the
         * number is stated where the type's own default used to be enough.
         */
        'ReportActionTracking' => array( 'trend' ),

        /*
         * The medium pie. It was half a row on its own; it is now one of three
         * pies across a row -- medium, source, campaign -- so it is a third,
         * and it sits with the other two rather than directly under the trend.
         */
        /*
         * The medium pie was half a row on its own; it is now one of three
         * pies across a row -- medium, source, campaign -- so it is a third,
         * and it sits with the other two rather than directly under the trend.
         */
        /*
         * ...and the trend went to half a row, which puts Entry Pages beside
         * it instead of under it.
         */
        'ReportTraffic' => array( 'medium', 'trend' ),
    );

    /**
     * Values deliberately RESTATED since the conversion, and what they were.
     *
     * The golden fixture is the pre-conversion record and cannot be
     * regenerated into agreement: the controllers it was taken from are
     * deleted, so a regeneration would capture the definitions' own output and
     * the equivalence proof would become a comparison with itself. So a
     * deliberate change is named here instead.
     *
     * Each entry is one value, by path, with what the controller declared and
     * what the definition says now. `null` on the right means the key is gone.
     * The allowance is checked as it is applied -- a value that is not what the
     * entry says it is now fails, so an entry cannot outlive the change it
     * describes.
     *
     * class => [ widget id => [ path => [ was, is ] ] ]
     */
    /**
     * Reports deliberately RENAMED since the conversion.
     *
     * The report's own title, which RESTATED cannot carry because that is keyed
     * by widget. Same shape and same rule: what the controller declared, what
     * it says now, and the allowance fails if the definition says neither.
     *
     * class => [ was, is ]
     */
    public const RETITLED = array(

        // "Web Pages" was the odd one out: every other content report is named
        // for what it lists, and the nav entry beside it already said "Pages".
        'ReportPages' => array( 'Web Pages', 'Pages' ),
    );

    /**
     * Put a deliberately renamed report's title back to what the record has.
     *
     * @return array{config: array, problems: array<int, string>}
     */
    public static function undoRetitling( string $class, array $config ): array
    {
        $entry = self::RETITLED[ $class ] ?? null;

        if ( ! $entry ) {

            return array( 'config' => $config, 'problems' => array() );
        }

        list( $was, $is ) = $entry;

        $now = $config['title'] ?? null;

        if ( $now !== $is ) {

            return array( 'config' => $config, 'problems' => array( sprintf(
                '%s is listed as renamed to "%s", but its title is %s -- either it changed '
              . 'again or the allowance is stale', $class, $is, json_encode( $now ) ) ) );
        }

        $config['title'] = $was;

        return array( 'config' => $config, 'problems' => array() );
    }

    public const RESTATED = array(

        /*
         * EVERY table card dropped its own resultsPerPage.
         *
         * A card's height is its row count, so cards sitting beside each other
         * need the same one or the row comes out ragged -- the same reason they
         * share a width. The six shipped cards had chosen 5, 10 and 25 between
         * them. They take ConfiguredReport::DEFAULT_CARD_ROWS now, so there is
         * one number and one place to change it.
         */

        'ReportDashboard' => array(
            // A full URL in a column narrow enough for a path.
            'latestVisits' => array(
                'query.dimensions' => array(
                    'ipAddress,entryPageUrl,medium,source,city,country,priorVisitCount',
                    'ipAddress,entryPagePath,medium,source,city,country,priorVisitCount',
                ),
            ),

            /*
             * A trend card names its own metrics -- it does not take the report
             * metric set -- and `visits` is not among them, so the chart draws
             * the first of the ones it does measure.
             */
            'siteTrend' => array(
                'query.metrics' => array(
                    null,
                    'uniqueVisitors,pageViews,bounceRate,pagesPerVisit,visitDuration',
                ),
                'chartMetric'   => array( 'visits', 'uniqueVisitors' ),
            ),

            /*
             * Top Content became a card, and a card shows ONE dimension. It has
             * to be the one the row link is built from -- the link carries a
             * pagePath into the page detail report -- so pageTitle goes, the
             * link column becomes the path, and the excludeColumns that existed
             * only to hide it goes too.
             */
            'topContent' => array(
                'query.dimensions'     => array( 'pageTitle,pagePath', 'pagePath' ),
                'link.linkColumn'      => array( 'pageTitle', 'pagePath' ),
                'excludeColumns'       => array( array( 'pagePath' ), null ),
                'query.resultsPerPage' => array( 10, null ),
            ),
        ),

        /*
         * chartWidth was DEAD, not merely unused: the template wrote it onto
         * the explorer's option bag and the pie reads a different one, taking
         * its width from the DOM. This pie carried '300px' and drew at 619.
         * Removed rather than wired up -- wiring it would put back the thing it
         * was reported as, which is pies at different sizes on different
         * reports.
         */
        'ReportTraffic' => array(

            'medium' => array(
                'chartWidth' => array( '300px', null ),

                // It had none. One of three pies in a row needs to say which
                // one it is; the only pie on the report did not.
                'title'      => array( null, 'Mediums' ),
            ),

            /*
             * A trend CARD names its own metrics and always shows them -- the
             * boxes ARE the card, so there is nothing left for
             * showMetricBoxes to turn off.
             */
            'trend' => array(
                'query.metrics'    => array( null, 'visits,uniqueVisitors,visitDuration' ),
                'showMetricBoxes'  => array( false, null ),
            ),
        ),

        'ReportContent' => array(
            // The trend breaks out by page path: a line per page over the total.
            /*
             * The trend breaks out by page path: a line per page over the
             * total. It has to name its own metrics to do it -- the report's
             * set includes bounceRate, which is measured on the SESSION, and
             * pagePath is on the request. One query is answered from one fact
             * table, so asking for both is not a query at all; before the
             * guard in getAllRelatedDimensions() it was a 500 with an empty
             * body.
             */
            /*
             * It names its own metrics because a trend CARD does not take the
             * report metric set, and those are not the report's: page views
             * with pages per visit and bounce rate beside it.
             *
             * The dimensions came BACK to what the controller declared. It had
             * been broken out by page path -- a line per page over the total --
             * and a card cannot be broken out at all, so `date` alone is both
             * the new answer and the old one. No allowance needed for it.
             */
            'trend' => array(
                'query.metrics' => array( null, 'pageViews,pagesPerVisit,bounceRate' ),
            ),
            /*
             * Top Pages shows TITLES again, which is what the controller had.
             *
             * It had been narrowed to pagePath when it became a card -- one
             * metric by one dimension -- and that left its row link naming a
             * pageTitle column the grid no longer drew. The path is back in the
             * query and out of the columns, which is the shape that makes the
             * link work: the title is what a reader picks, the path is what the
             * page detail report reads. So only the page size is restated.
             */
            /*
             * Top Pages shows TITLES, which is the dimension the controller
             * had -- but ONE of them, where the controller had the path
             * alongside it and hidden.
             *
             * A card shows one metric against one dimension; that is what makes
             * it a card rather than a narrow table, and it is enforced for
             * shipped cards by ReportDefinitionFormatTest. So the hidden path
             * goes, and the row link goes with it: `document` is reached by
             * pagePath and nothing else, and there is no longer a pagePath in
             * the result set to reach it with. "View Full Report" still leads
             * to the full Top Pages report.
             *
             * The link had been broken anyway since the card was narrowed to
             * pagePath alone -- linkColumn named a pageTitle column the grid no
             * longer drew.
             */
            'toppages' => array(
                // "Top" says the same thing the sort already does. Every card
                // on this report is a ranking; naming them all "Top X" made the
                // word furniture.
                'title'                => array( 'Top Pages', 'Pages' ),
                'query.dimensions'     => array( 'pageTitle,pagePath', 'pageTitle' ),
                'excludeColumns'       => array( array( 'pagePath' ), null ),
                'link'                 => array(
                    array(
                        'linkColumn'   => 'pageTitle',
                        'template'     => array(
                            'do'       => 'base.report',
                            'pagePath' => '%s',
                            'reportId' => 'document',
                        ),
                        'valueColumns' => 'pagePath',
                    ),
                    null,
                ),
                'query.resultsPerPage' => array( 25, null ),
            ),
            'toppagetypes' => array(
                'title'                => array( 'Top Page Types', 'Page Types' ),
                'query.resultsPerPage' => array( 25, null ),
            ),
        ),

        'ReportActionTracking' => array(

            'actionsByGroup' => array( 'query.resultsPerPage' => array( 5, null ) ),

            /*
             * A trend CARD names its own metrics -- it does not take the report
             * metric set. These are the set, stated: the same three the widget
             * was already drawing, now said out loud because the type requires
             * it.
             */
            'trend' => array(
                'query.metrics' => array( null, 'actions,uniqueActions,actionsValue' ),
            ),

            /*
             * Actions by Name became a card, and a card shows ONE dimension. So
             * the group goes -- and the row link with it: action-detail is
             * constrained on the name AND the group, and a card with a single
             * dimension has no second value to carry.
             */
            'actionsByName' => array(
                'query.dimensions'     => array( 'actionGroup,actionName', 'actionName' ),
                'query.resultsPerPage' => array( 5, null ),
                'link'                 => array(
                    array(
                        'linkColumn'   => 'actionName',
                        'template'     => array(
                            'actionGroup' => '%s',
                            'actionName'  => '%s',
                            'do'          => 'base.report',
                            'reportId'    => 'action-detail',
                        ),
                        'valueColumns' => array( 'actionName', 'actionGroup' ),
                    ),
                    null,
                ),
            ),
        ),

        'ReportVisitors' => array(

            'browserTypes' => array( 'query.resultsPerPage' => array( 10, null ) ),

            /*
             * A trend CARD names its own metrics. These are the report's set
             * minus `visits` -- this report is about visitORS, and the visit
             * count belongs to the reports that are about visits.
             *
             * chartMetric is NOT here: the controller already charted
             * uniqueVisitors, and it still does. Only the metrics moved from
             * the report's set onto the widget.
             */
            'trend' => array(
                'query.metrics' => array(
                    null, 'uniqueVisitors,newVisitors,repeatVisitors,visitDuration' ),
            ),
        ),

        'ReportEcommerce' => array(
            'productName' => array( 'query.resultsPerPage' => array( 5, null ) ),
        ),
    );

    /**
     * Put a deliberately restated value back to what the record has.
     *
     * @return array{config: array, problems: array<int, string>}
     */
    public static function undoRestating( string $class, array $config ): array
    {
        $expected = self::RESTATED[ $class ] ?? array();

        if ( ! $expected ) {

            return array( 'config' => $config, 'problems' => array() );
        }

        $problems = array();
        $seen     = array();

        foreach ( (array) ( $config['widgets'] ?? array() ) as $i => $widget ) {

            $id = (string) ( ( (array) $widget )['id'] ?? '' );

            if ( ! isset( $expected[ $id ] ) ) {

                continue;
            }

            foreach ( $expected[ $id ] as $path => $pair ) {

                list( $was, $is ) = $pair;

                $now = self::atPath( $config['widgets'][ $i ], $path );

                if ( $now !== $is ) {

                    $problems[] = sprintf(
                        '%s:%s is listed as restating "%s" to %s, but it is %s -- either it '
                      . 'changed again or the allowance is stale',
                        $class, $id, $path, json_encode( $is ), json_encode( $now ) );

                    continue;
                }

                self::setAtPath( $config['widgets'][ $i ], $path, $was );

                $seen[] = $id . '.' . $path;
            }
        }

        foreach ( $expected as $id => $paths ) {

            foreach ( array_keys( $paths ) as $path ) {

                if ( ! in_array( $id . '.' . $path, $seen, true ) ) {

                    $problems[] = sprintf( '%s lists a restatement of "%s" on widget "%s", '
                        . 'which its definition does not contain', $class, $path, $id );
                }
            }
        }

        return array( 'config' => $config, 'problems' => $problems );
    }

    /** The value at a dotted path, or null when nothing is there. */
    private static function atPath( array $widget, string $path )
    {
        $node = $widget;

        foreach ( explode( '.', $path ) as $step ) {

            if ( ! is_array( $node ) || ! array_key_exists( $step, $node ) ) {

                return null;
            }

            $node = $node[ $step ];
        }

        return $node;
    }

    /**
     * Set the value at a dotted path; a null value REMOVES the key, which is
     * how a record that has a key and a definition that does not are made
     * comparable in both directions.
     */
    private static function setAtPath( array &$widget, string $path, $value ): void
    {
        $steps = explode( '.', $path );
        $last  = array_pop( $steps );
        $node  = &$widget;

        foreach ( $steps as $step ) {

            if ( ! isset( $node[ $step ] ) || ! is_array( $node[ $step ] ) ) {

                $node[ $step ] = array();
            }

            $node = &$node[ $step ];
        }

        if ( $value === null ) {

            unset( $node[ $last ] );

        } else {

            $node[ $last ] = $value;

            ksort( $node );
        }
    }

    /**
     * Reconcile a deliberately relaid-out report with the record.
     *
     * Takes both sides because a move is a difference between them, not
     * something one side can be normalised into on its own.
     *
     * What it allows: a listed widget in a different position, with a different
     * colspan/rowspan. What it still catches: a widget added or removed, a
     * listed widget that did not actually move, an UNLISTED widget that moved,
     * and any other difference in any widget.
     *
     * @return array{expected: array, actual: array, problems: array<int, string>}
     */
    public static function normaliseLayout( string $class, array $expected, array $actual ): array
    {
        $moved = self::RELAID_OUT[ $class ] ?? array();

        if ( ! $moved ) {

            return array( 'expected' => $expected, 'actual' => $actual, 'problems' => array() );
        }

        $problems = array();

        $index = static function ( array $config ) {

            $byId = array();

            foreach ( (array) ( $config['widgets'] ?? array() ) as $widget ) {

                $byId[ (string) ( ( (array) $widget )['id'] ?? '' ) ] = (array) $widget;
            }

            return $byId;
        };

        $expectedById = $index( $expected );
        $actualById   = $index( $actual );

        $ids = static function ( array $config ) {

            return array_map( static function ( $widget ) {

                return (string) ( ( (array) $widget )['id'] ?? '' );

            }, (array) ( $config['widgets'] ?? array() ) );
        };

        $expectedIds = $ids( $expected );
        $actualIds   = $ids( $actual );

        if ( array_diff( $expectedIds, $actualIds ) || array_diff( $actualIds, $expectedIds ) ) {

            $problems[] = sprintf( '%s adds or removes widgets; a relayout moves them, it does '
                . 'not change which ones there are (recorded: %s; now: %s)',
                $class, implode( ', ', $expectedIds ), implode( ', ', $actualIds ) );

            return array( 'expected' => $expected, 'actual' => $actual, 'problems' => $problems );
        }

        /*
         * Everything NOT listed must still be in its recorded order relative to
         * the others -- otherwise "one widget moved" would excuse the whole
         * report being shuffled.
         */
        $without = static function ( array $list ) use ( $moved ) {

            return array_values( array_diff( $list, $moved ) );
        };

        if ( $without( $expectedIds ) !== $without( $actualIds ) ) {

            $problems[] = sprintf( '%s reorders widgets that are not listed as moved '
                . '(recorded: %s; now: %s)', $class,
                implode( ', ', $without( $expectedIds ) ), implode( ', ', $without( $actualIds ) ) );
        }

        foreach ( $moved as $id ) {

            if ( ! isset( $expectedById[ $id ], $actualById[ $id ] ) ) {

                $problems[] = sprintf( '%s lists "%s" as relaid out, but it is not in both '
                    . 'the record and the definition', $class, $id );

                continue;
            }

            $samePlace = array_search( $id, $expectedIds, true ) === array_search( $id, $actualIds, true );
            $sameSpan  = ( $expectedById[ $id ]['colspan'] ?? null ) === ( $actualById[ $id ]['colspan'] ?? null )
                      && ( $expectedById[ $id ]['rowspan'] ?? null ) === ( $actualById[ $id ]['rowspan'] ?? null );

            if ( $samePlace && $sameSpan ) {

                $problems[] = sprintf( '%s lists "%s" as relaid out, but it is exactly where '
                    . 'and how the record has it -- the allowance is stale', $class, $id );
            }
        }

        /*
         * Now make the two comparable: the listed widgets take the record's
         * position and span, so the assertion that follows is about everything
         * else they say.
         */
        foreach ( array_keys( $actualById ) as $id ) {

            if ( ! in_array( $id, $moved, true ) ) {

                continue;
            }

            foreach ( array( 'colspan', 'rowspan' ) as $key ) {

                if ( array_key_exists( $key, $expectedById[ $id ] ) ) {

                    $actualById[ $id ][ $key ] = $expectedById[ $id ][ $key ];

                } else {

                    unset( $actualById[ $id ][ $key ] );
                }
            }

            /*
             * ...in the record's key order. assertSame() compares arrays key
             * for key IN ORDER, and putting a key back puts it at the end --
             * which fails on ordering alone while every value matches. The
             * snapshot writes each widget's keys sorted, so sorting is what
             * restores the order rather than an arbitrary reshuffle.
             */
            ksort( $actualById[ $id ] );
        }

        $reordered = array();

        foreach ( $expectedIds as $id ) {

            $reordered[] = $actualById[ $id ];
        }

        $actual['widgets'] = $reordered;

        return array( 'expected' => $expected, 'actual' => $actual, 'problems' => $problems );
    }

    public static function reportNames(): array
    {
        $names = array();

        foreach ( glob( OWA_DIR . 'modules/Base/Controller/Report*.php' ) as $file ) {

            $name = basename( $file, '.php' );

            if ( in_array( $name, self::NOT_A_REPORT, true ) ) {
                continue;
            }

            if ( in_array( $name, self::PREFETCHING, true ) ) {
                continue;
            }

            $names[] = $name;
        }

        foreach ( self::CONVERTED as $class ) {
            $names[] = $class;
        }

        $names = array_values( array_unique( $names ) );

        sort( $names );

        return $names;
    }

    /** The report id a converted report is registered under, or '' if it is not one. */
    public static function idForConverted( string $name ): string
    {
        $id = array_search( $name, self::CONVERTED, true );

        return $id === false ? '' : (string) $id;
    }

    /** Request parameters a controller reads, in source order. */
    public static function paramsFor( string $name ): array
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/' . $name . '.php' );

        preg_match_all( "/getParam\(\s*'([a-zA-Z_]+)'\s*\)/", $src, $m );
        $params = $m[1];

        /*
         * Three controllers name the parameter through a variable --
         *
         *     $dim_name  = 'productSku';
         *     $dim_value = $this->getParam( $dim_name );
         *
         * -- which a literal-string match cannot see. Missing one is not
         * harmless: the controller then reads a parameter that was never
         * supplied and urlencode(null) deprecates, silently on a machine where
         * deprecations are not fatal. Resolved by looking up the variable's
         * literal assignment; anything more dynamic than that is caught by the
         * diagnostics guard in snapshot() instead.
         */
        // Single-quoted on purpose. In a double-quoted PHP string \$ collapses to
        // a bare $, which the regex engine then reads as end-of-subject -- so the
        // pattern silently matches nothing and the parameter goes on being missed.
        preg_match_all( '/getParam\(\s*\$([a-zA-Z_]+)\s*\)/', $src, $vars );

        foreach ( array_unique( $vars[1] ) as $var ) {

            if ( preg_match( '/\$' . $var . '\s*=\s*\'([a-zA-Z_]+)\'\s*;/', $src, $lit ) ) {
                $params[] = $lit[1];
            }
        }

        $params = array_values( array_unique( $params ) );
        sort( $params );

        return $params;
    }

    /**
     * Run one report's action() and return a normalised snapshot.
     *
     * Values are normalised rather than captured raw so the fixture says the
     * same thing on any machine: an object becomes its class name, because its
     * contents depend on a database, while its PRESENCE is part of the
     * contract.
     */
    public static function snapshot( string $name ): array
    {
        /*
         * A converted report has no controller to run, so it is run from its
         * definition instead. Dispatching here rather than in the tests keeps
         * "what this report declares" one question with one answer, whichever
         * way the report happens to be implemented.
         */
        $convertedId = self::idForConverted( $name );

        if ( $convertedId !== '' ) {

            return self::snapshotConfigured( $convertedId );
        }

        $class  = '\\OWA\\Module\\Base\\Controller\\' . $name;
        $params = self::paramsFor( $name );

        $controller = new $class( array_fill_keys( $params, self::SENTINEL ) );

        return array( 'params' => $params ) + self::observe( $controller );
    }

    /**
     * Run a controller's action() and record both what it declared and anything
     * it complained about.
     *
     * Split out from snapshot() so a test can hand it a deliberately noisy
     * object and prove the recording works. Without that, "no report raises a
     * diagnostic" is only as true as this method is honest -- and a guard that
     * cannot be shown to fire is a claim, not a guard.
     *
     * @return array{diagnostics: array<int,string>, config: array}
     */
    public static function observe( object $controller ): array
    {
        /*
         * Diagnostics are part of the snapshot, not noise to be swallowed.
         *
         * These controllers had never been executed by a test before this
         * harness, and the first CI run surfaced three deprecations and a
         * warning that had been there all along. Recording them means a report
         * cannot start warning -- or keep warning -- without the fixture
         * saying so.
         */
        $diagnostics = array();

        set_error_handler( static function ( $no, $msg, $file, $line ) use ( &$diagnostics ) {
            $diagnostics[] = basename( (string) $file ) . ':' . $line . ' ' . $msg;
            return true;
        } );

        try {
            $controller->action();
        } finally {
            restore_error_handler();
        }

        $data = array();

        foreach ( (array) $controller->data as $key => $value ) {
            $data[ $key ] = self::normalise( $value );
        }

        ksort( $data );

        return array(
            'diagnostics' => $diagnostics,
            'config'      => $data,
        );
    }

    /** @param mixed $value */
    private static function normalise( $value )
    {
        if ( is_object( $value ) ) {
            return '<object:' . get_class( $value ) . '>';
        }

        if ( is_array( $value ) ) {

            $out = array();

            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::normalise( $v );
            }

            ksort( $out );

            return $out;
        }

        return $value;
    }

    /** @return array<string, array> every in-scope report, keyed by name */
    public static function captureAll(): array
    {
        $all = array();

        foreach ( self::reportNames() as $name ) {

            $snapshot = self::snapshot( $name );

            /*
             * `deprecated` is stripped here for the same reason the assertion
             * strips it from actual: this file is the PRE-CONVERSION record,
             * and no controller ever declared the key. Without this, a
             * regeneration bakes it into the baseline and the very next run
             * fails -- the golden claiming a key the comparison has just
             * removed. Regenerating is supposed to be the way OUT of a
             * failure, not a way into one.
             */
            unset( $snapshot['config']['deprecated'] );

            $all[ $name ] = $snapshot;
        }

        return $all;
    }

    /** Absolute path to a converted report's definition file. */
    public static function definitionPath( string $id ): string
    {
        return OWA_DIR . 'modules/Base/reports/' . $id . '.json';
    }

    /**
     * Run a converted report from its JSON and return the same shape snapshot()
     * returns for a controller, so the two are directly comparable.
     */
    public static function snapshotConfigured( string $id ): array
    {
        $definition = json_decode(
            (string) file_get_contents( self::definitionPath( $id ) ), true );

        $definition = (array) $definition;

        /*
         * A definition DECLARES the parameters it reads, so they can be
         * supplied without parsing anything -- which is what paramsFor() had to
         * do against a controller, variable-named getParam() calls included.
         *
         * Sorted, because the fixture records them sorted and the two have to
         * be comparable.
         */
        $params = array_keys( (array) ( $definition['params'] ?? array() ) );
        sort( $params );

        $controller = new \OWA\Core\ConfiguredReport(
            array_fill_keys( $params, self::SENTINEL ) );

        $controller->setDefinition( $definition );

        return array( 'params' => $params ) + self::observe( $controller );
    }

    public static function goldenPath(): string
    {
        return OWA_DIR . 'tests/fixtures/report-characterization.json';
    }
}
