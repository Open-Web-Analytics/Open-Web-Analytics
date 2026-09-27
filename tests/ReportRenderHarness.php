<?php

namespace OWA\Tests;

/**
 * Reads what a rendered report EMITS: the API queries its widgets issue, the
 * explorers they build, and the commands they run on the results. Those are the
 * report; everything else is markup around them.
 *
 * Parsers only. It used to also record every report into a golden fixture, to
 * prove the controller-to-JSON conversion preserved what each controller
 * rendered. The conversion is finished, and a report is configuration like the
 * dimension and metric registries, which are not snapshotted either -- so the
 * recording went and the parsers stayed for ReportDefinitionFormatTest.
 *
 * URLs are PARSED, never compared as strings. A substring test for
 * 'siteId=' also matches 'owa_siteId=', and a report that quietly dropped a
 * parameter would still contain every other one.
 */
final class ReportRenderHarness
{
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
}
