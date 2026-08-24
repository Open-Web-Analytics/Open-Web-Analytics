<?php

namespace OWA\Tests;

/**
 * Captures what a report actually EMITS, so the widget conversion can be shown
 * to preserve it.
 *
 * The declaration harness records what a report configures. That was enough to
 * move reports from controllers to JSON, because the subview and template were
 * not changing. Rendering them as widgets changes the template, and a report's
 * declared configuration says nothing about what a template does with it.
 *
 * What a report emits is: the API queries its widgets will issue, and the
 * commands they will run on the results. Those two things ARE the report --
 * everything else is markup around them.
 *
 * URLs are PARSED, never compared as strings. A substring test for
 * 'siteId=' also matches 'owa_siteId=', and a report that quietly dropped a
 * parameter would still contain every other one.
 */
final class ReportRenderHarness
{
    /**
     * Request parameters every snapshot is taken with.
     *
     * Fixed so the fixture says the same thing on any machine: the period
     * decides the date range inside the API links, and the site decides the
     * constraint.
     */
    public const REQUEST = array(
        'siteId' => '1',
        'period' => 'last_thirty_days',
    );

    /**
     * Query parameters whose value depends on who is asking rather than on the
     * report.
     *
     * The nonce is derived from the current user_id, so it differs between
     * machines and between users. Its PRESENCE is part of the contract -- an
     * API link without one is refused -- so it is normalised rather than
     * dropped, and asserted separately.
     */
    private const VOLATILE = array( 'nonce' );

    /**
     * Render one report and record what it emits.
     *
     * @param string $id a registered report id
     * @param array $extra request parameters beyond REQUEST (a detail report's own)
     * @return array
     */
    /**
     * Three metric sets: the default one, e-commerce, and a goal group.
     *
     * METRIC SETS, not "tabs". A tab is how the interface happens to present
     * these today and is expected to change; what a report actually has is
     * several metric sets over one dimension, and only one of them is being
     * looked at. Naming the recording after the affordance would bake a
     * transient UI decision into a fixture that outlives it -- and into the
     * vocabulary of everything built on top.
     *
     * They are not configuration either: ReportController::pre() derives them
     * per site from enableEcommerceReporting and the site's active goal
     * groups, so the test site has exactly one and the branches that matter
     * are never reached by a recording taken against it.
     *
     * Written into $data['tabs'] below because that is what the CURRENT
     * template calls the key. That name is the thing being replaced, not the
     * thing being recorded.
     */
    public const METRIC_SETS = array(
        'site_usage' => array(
            'label'       => 'Site Usage',
            'metrics'     => 'visits,pagesPerVisit,visitDuration,bounceRate,uniqueVisitors',
            'chartMetric' => 'visits',
        ),
        'ecommerce' => array(
            'label'       => 'e-commerce',
            'metrics'     => 'visits,transactions,transactionRevenue,revenuePerVisit,revenuePerTransaction,ecommerceConversionRate',
            'chartMetric' => 'transactions',
        ),
        'goal_group_1' => array(
            'label'       => 'Goal Group One',
            'metrics'     => 'visits,goal1Completions,goalValueAll',
            'chartMetric' => 'visits',
        ),
    );

    /**
     * @param string $id a registered report id
     * @param array $extra request parameters beyond REQUEST (a detail report's own)
     * @param array|null $metricSets metric sets to render with, in place of the site's own
     * @return array
     */
    public static function snapshot( string $id, array $extra = array(), array $metricSets = null ): array
    {
        $params = self::REQUEST + $extra + array( 'reportId' => $id );

        $data = (array) ( new \OWA\Module\Base\Controller\Report( $params ) )->doAction();

        if ( empty( $data['subview'] ) ) {

            return array( 'error' => 'did not render: ' . ( $data['view'] ?? '(no view)' ) );
        }

        if ( $metricSets !== null ) {

            $data['metricSets'] = $metricSets;

            /*
             * The older templates read a differently-shaped array under
             * 'tabs'. Derived from the same input rather than written out
             * twice, so a snapshot cannot hand the two renderers different
             * sets. Both keys go when those templates do.
             */
            $data['tabs']      = \OWA\Core\MetricSets::toLegacyTabs( $metricSets );
            $data['tabs_json'] = json_encode( $data['tabs'] );
        }

        /*
         * The whole view, not just the subview.
         *
         * Rendering the subview alone throws: report_dimensionDetail.php reads
         * $view->dom_id, which the SUBVIEW never sets -- the main view does,
         * while assembling it. Driving the real entry point means the harness
         * cannot be wrong about the wiring in a way production is not.
         */
        $html = (string) \OWA\Core\CoreAPI::displayView( $data );

        return array(
            'subview'   => $data['subview'],
            'queries'   => self::queriesIn( $html ),
            'control'   => self::controlIn( $html ),
            'explorers' => self::explorersIn( $html ),
            'commands'  => self::commandsIn( $html ),
        );
    }

    /**
     * Every API query the rendered report will issue, in document order.
     *
     * A LIST, not a map keyed by variable name. The tabbed templates declare
     * `var dimurl` once per tab, so keying by name silently kept only the last
     * one: a three-tab report emits six queries and this recorded two. The
     * fixture looked complete and was missing two thirds of the report.
     *
     * @return array<int, array>
     */
    public static function queriesIn( string $html ): array
    {
        $out = array();

        if ( ! preg_match_all( "/var\s+(\w+)\s*=\s*'([^']*api[^']*)'/", $html, $m ) ) {

            return $out;
        }

        foreach ( $m[1] as $i => $name ) {

            $url = html_entity_decode( $m[2][ $i ], ENT_QUOTES );

            parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

            foreach ( self::VOLATILE as $key ) {

                if ( isset( $query[ $key ] ) ) {
                    $query[ $key ] = '<' . $key . '>';
                }
            }

            ksort( $query );

            $out[] = array( 'var' => $name, 'query' => $query );
        }

        return $out;
    }

    /**
     * Whether the code at an offset sits behind a `//` on its own line.
     *
     * Only line comments: a block comment around a push would be unusual, and
     * treating `/*` as a comment start would misread the `*` inside a jqote
     * tag, which every trend title contains.
     */
    private static function isCommentedOut( string $html, int $offset ): bool
    {
        $lineStart = (int) strrpos( substr( $html, 0, $offset ), "\n" );

        $before = substr( $html, $lineStart, $offset - $lineStart );

        return strpos( $before, '//' ) !== false;
    }

    /**
     * How a report lets you move between its metric sets, and who loads them.
     *
     * Added after a conversion removed the control entirely and this recording
     * saw NOTHING -- every query, command and binding was byte-identical,
     * because a control issues no query. The reports rendered every set
     * stacked on the page with no way to switch, and 1413 unit tests passed on
     * it. Eight browser tests caught it.
     *
     * `loads` is the other half and matters as much: a set that is registered
     * with the control is loaded BY it, only when looked at. A report that both
     * registers sets and loads them directly would query every set up front.
     *
     * @return array
     */
    public static function controlIn( string $html ): array
    {
        preg_match_all( '/id="(tab_[a-zA-Z0-9_]+)"/', $html, $panels );

        return array(
            'container' => strpos( $html, 'id="report-tabs"' ) !== false,
            'panels'    => $panels[1],
            'registered'=> substr_count( $html, '.addTab(' ),
            'built'     => substr_count( $html, 'createTabs()' ),
            // Direct loads: present when nothing else owns loading, absent when
            // the control does.
            // Both conventions are in use and both are fine: the widget template
            // sets the url up front and calls load() with nothing, while the
            // older bespoke templates pass the url straight to load().
            'loads'      => preg_match_all( '/\w+\.load\(\s*\)/', $html ),
            // Any arguments, not just one: report_actionTracking passes a
            // second -- load(aurl, 'grid') -- and a single-argument pattern
            // read that explorer as never loading at all.
            'loadsWithUrl' => preg_match_all( '/\w+\.load\(\s*\w[^)]*\)/', $html ),
            /*
             * An explorer is told its URL up front, and whoever loads it calls
             * load() with no argument -- the control does exactly that.
             *
             * Recorded because an explorer with no URL loads nothing, silently:
             * it is constructed, registered, and its queries are all present in
             * the page. The grid simply stays empty. That is what happened when
             * the control was restored without this, and only the browser saw
             * it.
             */
            'urls'      => substr_count( $html, '.setDataLoadUrl(' ),
        );
    }

    /**
     * Which container each result-set explorer is bound to, in document order.
     *
     * A resultSetExplorer renders into the element it was CONSTRUCTED with, and
     * refreshGrid takes no target of its own -- so a grid moved to the wrong
     * container emits an identical command list and an identical query, and the
     * report simply renders nothing.
     *
     * A list for the same reason the queries are: the tabbed templates rebind
     * `var dim` once per tab.
     *
     * @return array<int, array>
     */
    public static function explorersIn( string $html ): array
    {
        preg_match_all(
            "/(?:var\s+)?(\w+)\s*=\s*new\s+OWA\.resultSetExplorer\(\s*'([^']*)'/",
            $html, $m );

        $out = array();

        foreach ( $m[1] as $i => $receiver ) {

            $out[] = array( 'var' => $receiver, 'container' => $m[2][ $i ] );
        }

        return $out;
    }

    /**
     * The render commands queued against the result sets, in order, with what
     * each one draws into.
     *
     * Recorded as `receiver.command -> target`, because all three parts can
     * break independently: the command says what is drawn, the receiver says
     * which query's results it is drawn from, and the target says where it
     * lands. A widget that draws the right chart from the right data into a
     * container nobody created is invisible, with nothing in the console.
     *
     * Order is kept: makeAreaChart before makeMetricBoxes draws a different
     * page from the reverse.
     *
     * @return array<int, string>
     */
    public static function commandsIn( string $html ): array
    {
        if ( ! preg_match_all( '/(\w+)\.asyncQueue\.push\(\s*\[(.*?)\]\s*\)\s*;/s',
                               $html, $m, PREG_OFFSET_CAPTURE ) ) {

            return array();
        }

        $out = array();

        foreach ( $m[1] as $i => $receiverMatch ) {

            /*
             * Skip anything commented out.
             *
             * report_traffic.php (since deleted, when traffic became
             * configuration) had a makeMetricBoxes call left behind under
             * `//`, and this recorded it as a live command -- so the fixture
             * asserted behaviour that never runs, and the conversion would
             * have been held to reproducing it. The template is gone; the
             * guard stays, because the next recording can be misled the
             * same way.
             */
            if ( self::isCommentedOut( $html, $receiverMatch[1] ) ) {

                continue;
            }

            $receiver = $receiverMatch[0];

            $args = $m[2][ $i ][0];

            // First quoted string is the command name.
            preg_match( "/^\s*'([a-zA-Z]+)'/", $args, $name );

            /*
             * The element it renders into is a TRAILING argument, when there is
             * one at all -- refreshGrid takes none, and makeAreaChart is called
             * both ways.
             *
             * It has to be the last top-level argument, not merely the last
             * quoted string anywhere in the call. `['makeAreaChart',
             * [{x:'date',y:'pageViews'}]]` has no target, and reading the last
             * quoted string recorded 'pageViews' -- an axis metric written down
             * as a container. A conversion that changed the charted metric
             * would then have shown up as a container change.
             */
            $target = preg_match( "/,\s*'([a-zA-Z0-9_.#-]*)'\s*$/", $args, $trailing )
                ? $trailing[1]
                : '';

            $out[] = $receiver . '.' . ( $name[1] ?? '?' ) . ( $target !== '' ? ' -> ' . $target : '' );
        }

        return $out;
    }

    public static function goldenPath(): string
    {
        return OWA_DIR . 'tests/fixtures/report-render.json';
    }

    /**
     * The reports this harness covers, with the parameters each needs.
     *
     * Read from the definitions rather than listed: a parameterised report
     * declares what it reads, so the value can be supplied without knowing
     * anything else about it.
     *
     * @return array<string, array>
     */
    public static function coveredReports(): array
    {
        $out = array();

        foreach ( (array) glob( OWA_DIR . 'modules/Base/reports/*.json' ) as $file ) {

            $id  = basename( $file, '.json' );
            $def = json_decode( (string) file_get_contents( $file ), true );

            $extra = array();

            foreach ( array_keys( (array) ( $def['params'] ?? array() ) ) as $name ) {

                // The same fixed point the declaration harness uses, so a value
                // that gets lowercased on the way in still reads back equal.
                $extra[ $name ] = ReportCharacterizationHarness::SENTINEL;
            }

            $out[ $id ] = $extra;
        }

        ksort( $out );

        return $out;
    }

    /**
     * One representative report per template that repeats itself per metric
     * set, recorded with all three.
     *
     * The repeat lives in the TEMPLATE, not in the report, so pinning it once
     * per template pins it for all 29 reports that use those templates.
     * Recording a variant of every one would triple the fixture to say the
     * same thing 29 times.
     */
    /** How a multi-metric-set snapshot is named in the fixture. */
    public const MULTI_SUFFIX = ' (3 metric sets)';

    public const MULTI_METRIC_SET = array(
        'browsers'    => 'base.reportDimension',
        'host-detail' => 'base.reportDimensionDetail',
    );

    /** @return array<string, array> */
    public static function captureAll(): array
    {
        $all = array();

        foreach ( self::coveredReports() as $id => $extra ) {

            $all[ $id ] = self::snapshot( $id, $extra );
        }

        foreach ( self::MULTI_METRIC_SET as $id => $subview ) {

            $extra = self::coveredReports()[ $id ] ?? array();

            $all[ $id . self::MULTI_SUFFIX ] = self::snapshot( $id, $extra, self::METRIC_SETS );
        }

        return $all;
    }
}
