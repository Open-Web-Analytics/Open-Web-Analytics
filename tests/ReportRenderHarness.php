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
     * A tab set with all three kinds of tab in it.
     *
     * Tabs are not configuration: ReportController::pre() builds them per site
     * from enableEcommerceReporting and the site's active goal groups. So the
     * recording taken against the test site has exactly one tab, and the
     * ecommerce and goal-group branches of the tabbed templates -- the loop
     * that makes a tabbed report tabbed -- are not exercised by it at all.
     *
     * Supplied directly rather than by creating a site with goals. The template
     * consumes $view->tabs, so handing it a tab set drives precisely the code
     * whose behaviour has to be preserved, deterministically, without writing
     * rows to anyone's database or leaking a generated site id into the
     * recording. How a SITE turns into a tab set is a different question with
     * its own answer, and does not belong in a rendering fixture.
     */
    public const TABS = array(
        'site_usage' => array(
            'tab_label'        => 'Site Usage',
            'metrics'          => 'visits,pagesPerVisit,visitDuration,bounceRate,uniqueVisitors',
            'sort'             => 'visits-',
            'trendchartmetric' => 'visits',
        ),
        'ecommerce' => array(
            'tab_label'        => 'e-commerce',
            'metrics'          => 'visits,transactions,transactionRevenue,revenuePerVisit,revenuePerTransaction,ecommerceConversionRate',
            'sort'             => 'transactionRevenue-',
            'trendchartmetric' => 'transactions',
        ),
        'goal_group_1' => array(
            'tab_label'        => 'Goal Group One',
            'metrics'          => 'visits,goal1Completions,goalValueAll',
            'sort'             => 'goalValueAll-',
            'trendchartmetric' => 'visits',
        ),
    );

    /**
     * @param string $id a registered report id
     * @param array $extra request parameters beyond REQUEST (a detail report's own)
     * @param array|null $tabs a tab set to render with, in place of the site's own
     * @return array
     */
    public static function snapshot( string $id, array $extra = array(), array $tabs = null ): array
    {
        $params = self::REQUEST + $extra + array( 'reportId' => $id );

        $data = (array) ( new \OWA\Module\Base\Controller\Report( $params ) )->doAction();

        if ( empty( $data['subview'] ) ) {

            return array( 'error' => 'did not render: ' . ( $data['view'] ?? '(no view)' ) );
        }

        if ( $tabs !== null ) {

            $data['tabs']      = $tabs;
            $data['tabs_json'] = json_encode( $tabs );
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
        if ( ! preg_match_all( '/(\w+)\.asyncQueue\.push\(\s*\[(.*?)\]\s*\)\s*;/s', $html, $m ) ) {

            return array();
        }

        $out = array();

        foreach ( $m[1] as $i => $receiver ) {

            $args = $m[2][ $i ];

            // First quoted string is the command name.
            preg_match( "/^\s*'([a-zA-Z]+)'/", $args, $name );

            // Last quoted string is the element it renders into, when there is
            // one -- refreshGrid takes none and renders into its own container.
            preg_match_all( "/'([a-zA-Z0-9_.#-]*)'/", $args, $quoted );

            $target = '';

            if ( ! empty( $quoted[1] ) ) {

                $last = end( $quoted[1] );

                if ( $last !== ( $name[1] ?? '' ) ) {
                    $target = $last;
                }
            }

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
     * One representative tabbed report per tabbed template, rendered with a
     * full tab set.
     *
     * The per-tab loop lives in the TEMPLATE, not in the report, so pinning it
     * once per template pins it for all 29 tabbed reports. Recording a
     * three-tab variant of every one of them would triple the fixture to say
     * the same thing 29 times.
     */
    public const MULTI_TAB = array(
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

        foreach ( self::MULTI_TAB as $id => $subview ) {

            $extra = self::coveredReports()[ $id ] ?? array();

            $all[ $id . ' (3 tabs)' ] = self::snapshot( $id, $extra, self::TABS );
        }

        return $all;
    }
}
