<?php

namespace OWA\Tests;

/**
 * Reads what a rendered report EMITS: the API queries its widgets issue, the
 * explorers they build, and the commands they run on the results -- read from
 * the widget descriptions report_widgets.php hands owa.reportWidgets.js. Those
 * are the report; everything else is markup around them.
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
     * The widget descriptions the report hands owa.reportWidgets.js, in
     * document order: one per widget, each naming the global its explorer is
     * published as, the element it renders into, its URL, options and queued
     * commands, and a broken-out trend's grid as `breakdown`.
     *
     * @return list<array<string, mixed>>
     */
    public static function widgetsIn( string $html ): array
    {
        preg_match_all( '~<script type="application/json" data-ui="report-widget:config">(.*?)</script>~s', $html, $m );

        $out = array();

        foreach ( $m[1] as $json ) {

            $out[] = (array) json_decode( $json, true );
        }

        return $out;
    }

    /**
     * Each widget's explorer and, after it, a broken-out trend's grid: the
     * explorers the page builds, in the order it builds them.
     *
     * @return list<array<string, mixed>>
     */
    private static function explorerDescriptions( string $html ): array
    {
        $out = array();

        foreach ( self::widgetsIn( $html ) as $widget ) {

            $out[] = $widget;

            if ( ! empty( $widget['breakdown'] ) ) {

                $out[] = $widget['breakdown'];
            }
        }

        return $out;
    }

    /**
     * Every API query the rendered report will issue, in document order, by
     * the global its URL is published as (`<name>url`).
     *
     * A LIST, not a map keyed by name. A tabbed report has a set of widgets per
     * tab, and keying by name once silently kept only the last of a repeated
     * one: a three-tab report emits six queries and this recorded two.
     *
     * @return array<int, array>
     */
    public static function queriesIn( string $html ): array
    {
        $out = array();

        foreach ( self::explorerDescriptions( $html ) as $explorer ) {

            parse_str( (string) parse_url( (string) $explorer['url'], PHP_URL_QUERY ), $query );

            foreach ( self::VOLATILE as $key ) {

                if ( isset( $query[ $key ] ) ) {
                    $query[ $key ] = '<' . $key . '>';
                }
            }

            ksort( $query );

            $out[] = array( 'var' => $explorer['name'] . 'url', 'query' => $query );
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
     * @return array<int, array>
     */
    public static function explorersIn( string $html ): array
    {
        $out = array();

        foreach ( self::explorerDescriptions( $html ) as $explorer ) {

            $out[] = array( 'var' => $explorer['name'], 'container' => $explorer['container'] );
        }

        return $out;
    }

    /**
     * Where a command's target element is among its arguments: the commands
     * that draw into an element they are given.
     */
    private const TARGET_ARGUMENT = array( 'makeAreaChart' => 2, 'makeMetricBoxes' => 1 );

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
        $out = array();

        foreach ( self::explorerDescriptions( $html ) as $explorer ) {

            foreach ( (array) ( $explorer['queue'] ?? array() ) as $command ) {

                $name   = (string) $command[0];
                $at     = self::TARGET_ARGUMENT[ $name ] ?? null;
                $target = $at !== null && is_string( $command[ $at ] ?? null ) ? $command[ $at ] : '';

                $out[] = $explorer['name'] . '.' . $name . ( $target !== '' ? ' -> ' . $target : '' );
            }
        }

        return $out;
    }
}
