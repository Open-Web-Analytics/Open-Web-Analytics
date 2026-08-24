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
    public static function snapshot( string $id, array $extra = array() ): array
    {
        $params = self::REQUEST + $extra + array( 'reportId' => $id );

        $data = (array) ( new \OWA\Module\Base\Controller\Report( $params ) )->doAction();

        if ( empty( $data['subview'] ) ) {

            return array( 'error' => 'did not render: ' . ( $data['view'] ?? '(no view)' ) );
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
            'commands'  => self::commandsIn( $html ),
        );
    }

    /**
     * Every API query the rendered report will issue, by the variable it is
     * assigned to.
     *
     * @return array<string, array>
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

            $out[ $name ] = $query;
        }

        ksort( $out );

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

    /** @return array<string, array> */
    public static function captureAll(): array
    {
        $all = array();

        foreach ( self::coveredReports() as $id => $extra ) {

            $all[ $id ] = self::snapshot( $id, $extra );
        }

        return $all;
    }
}
