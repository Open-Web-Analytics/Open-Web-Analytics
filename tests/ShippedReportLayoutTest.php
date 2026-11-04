<?php

use PHPUnit\Framework\TestCase;
use OWA\Core\ReportGrid;
use OWA\Module\Base\Classes\Cube\CampaignStep;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * How the shipped report definitions lay out and what they ask for, read from
 * the JSON files rather than a list kept here, so a new report is held to the
 * same rules the day it is added.
 */
final class ShippedReportLayoutTest extends TestCase
{
    /** @return array<string, array> report id => definition */
    private static function definitions(): array
    {
        $out = array();

        foreach ( (array) glob( OWA_DIR . 'modules/*/reports/*.json' ) as $file ) {

            $out[ basename( $file, '.json' ) ] = json_decode( (string) file_get_contents( $file ), true );
        }

        return $out;
    }

    /**
     * Every row is full at the twelve-column width: a widget that ends a row
     * short leaves a hole beside it, as Visitors' half + quarter and Content's
     * links block alone on the last row did.
     */
    public function testEveryRowOfAShippedReportIsFull(): void
    {
        $ragged = array();

        foreach ( self::definitions() as $id => $definition ) {

            $row = 0;
            $titles = array();

            foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

                $span = ReportGrid::colspan( $widget );

                if ( $row + $span > ReportGrid::COLUMNS ) {

                    $ragged[] = "$id: " . implode( ' + ', $titles ) . " ($row of " . ReportGrid::COLUMNS . ')';
                    $row = 0;
                    $titles = array();
                }

                $row += $span;
                $titles[] = $widget['title'] ?? $widget['id'] ?? $widget['type'];

                if ( $row === ReportGrid::COLUMNS ) {

                    $row = 0;
                    $titles = array();
                }
            }

            if ( $row !== 0 ) {

                $ragged[] = "$id: " . implode( ' + ', $titles ) . " ($row of " . ReportGrid::COLUMNS . ')';
            }
        }

        $this->assertSame( array(), $ragged, 'rows that end short: ' . implode( '; ', $ragged ) );
    }

    /**
     * A share chart sits in a row of share charts. It draws one bar per value
     * of a dimension with a handful of values -- new or returning, device type
     * -- so it is a few rows tall, while a card or a trend fills its height on
     * any real data. Beside each other the short one leaves a hole, as
     * Visitors' Visitor Types (three rows) did beside Countries (five).
     *
     * Reports where a mixed row is the layout that was asked for, each with
     * why; a new one does not belong here without one.
     */
    public function testAShareChartSharesItsRowOnlyWithShareCharts(): void
    {
        $asked = array(
            // Events, Visitor Types and Channels as one row of thirds.
            'dashboard' => true,
        );

        $mixed = array();
        $pies  = 0;

        foreach ( self::definitions() as $id => $definition ) {

            if ( isset( $asked[ $id ] ) ) {
                continue;
            }

            foreach ( self::rows( $definition ) as $row ) {

                $types = array_map( static function ( $w ) { return $w['type'] ?? ''; }, $row );
                $share = count( array_keys( $types, 'pie', true ) );
                $pies += $share;

                if ( $share > 0 && $share < count( $row ) ) {

                    $mixed[] = "$id: " . implode( ' + ', array_map( static function ( $w ) {
                        return ( $w['title'] ?? $w['id'] ?? '?' ) . ' (' . ( $w['type'] ?? '?' ) . ')';
                    }, $row ) );
                }
            }
        }

        $this->assertGreaterThan( 2, $pies, 'too few share charts checked for this to prove anything' );
        $this->assertSame( array(), $mixed, 'rows that mix share charts with cards: ' . implode( '; ', $mixed ) );
    }

    /**
     * A definition's widgets in the rows they lay out in at twelve columns.
     *
     * @return array<int, array<int, array>>
     */
    private static function rows( array $definition ): array
    {
        $rows = array();
        $row  = array();
        $used = 0;

        foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

            $span = ReportGrid::colspan( $widget );

            if ( $row && $used + $span > ReportGrid::COLUMNS ) {

                $rows[] = $row;
                $row = array();
                $used = 0;
            }

            $row[] = $widget;
            $used += $span;
        }

        if ( $row ) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A grid is an explorer and draws its whole control bar, so narrowed it
     * clips its own columns: Model Comparison's half-width tables hid Revenue
     * at every width. The custom report editor refuses one; a shipped
     * definition is held to the same.
     */
    public function testNoShippedGridIsNarrowed(): void
    {
        $narrowed = array();

        foreach ( self::definitions() as $id => $definition ) {

            foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

                if ( ( $widget['type'] ?? '' ) === 'grid' && ReportGrid::colspan( $widget ) < ReportGrid::COLUMNS ) {

                    $narrowed[] = "$id." . ( $widget['id'] ?? '?' );
                }
            }
        }

        $this->assertSame( array(), $narrowed );
    }

    /** Dom Clicks drew "Name Attributes" from the same query as "Dom IDs". */
    public function testNoReportDrawsTheSameQueryTwice(): void
    {
        $twice = array();

        foreach ( self::definitions() as $id => $definition ) {

            $seen = array();

            foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

                $key = json_encode( array( $widget['type'] ?? '', $widget['query'] ?? null, $widget['constraints'] ?? null ) );

                if ( isset( $seen[ $key ] ) ) {

                    $twice[] = "$id: " . $seen[ $key ] . ' and ' . ( $widget['title'] ?? $widget['id'] );
                }

                $seen[ $key ] = $widget['title'] ?? $widget['id'];
            }
        }

        $this->assertSame( array(), $twice );
    }

    /** Pages asked for pageViews twice. */
    public function testNoReportNamesAMetricTwice(): void
    {
        $twice = array();

        foreach ( self::definitions() as $id => $definition ) {

            $lists = array( 'report' => $definition['metrics'] ?? '' );

            foreach ( (array) ( $definition['widgets'] ?? array() ) as $widget ) {

                $lists[ $widget['id'] ?? '?' ] = $widget['query']['metrics'] ?? '';
            }

            foreach ( $lists as $where => $list ) {

                $metrics = array_filter( explode( ',', $list ) );

                if ( count( $metrics ) !== count( array_unique( $metrics ) ) ) {

                    $twice[] = "$id.$where: $list";
                }
            }
        }

        $this->assertSame( array(), $twice );
    }

    /**
     * Campaigns are campaigns: the placeholders the cube files an untagged
     * session under -- (direct), (organic), (referral) -- mirror the medium, so
     * a Campaigns chart that kept them was the Channels chart again. Each
     * placeholder CampaignStep can write is matched by the exclusion, so a new
     * one fails here rather than appearing as a campaign.
     */
    public function testTheCampaignViewsLeaveOutEveryPlaceholder(): void
    {
        $definitions = self::definitions();

        $constraints = array(
            'campaigns'      => $definitions['campaigns']['settings']['constraints'],
            'traffic.Campaigns' => null,
        );

        foreach ( $definitions['traffic']['widgets'] as $widget ) {

            if ( ( $widget['title'] ?? '' ) === 'Campaigns' ) {

                $constraints['traffic.Campaigns'] = $widget['constraints'];
            }
        }

        $placeholders = array_merge( array_values( CampaignStep::PLACEHOLDERS ), array( CampaignStep::FALLBACK ) );

        foreach ( $constraints as $where => $parts ) {

            $this->assertIsArray( $parts, "$where has no constraints" );

            $exclusion = null;

            foreach ( $parts as $part ) {

                if ( $part['dimension'] === 'attributedCampaign' && ( $part['operator'] ?? '' ) === '!~' ) {

                    $exclusion = $part['value'];
                }
            }

            $this->assertNotNull( $exclusion, "$where does not exclude the placeholders" );

            /*
             * No backslash: the pattern reaches MySQL inside a string literal,
             * which takes `\(` as an escaped `(` -- so `^\((organic)\)$` matched
             * the bare word and excluded nothing, while preg_match below agreed
             * with it. A character class needs no escape.
             */
            $this->assertStringNotContainsString( '\\', $exclusion, "$where escapes with a backslash" );

            foreach ( $placeholders as $placeholder ) {

                $this->assertSame( 1, preg_match( '/' . $exclusion . '/', $placeholder ), "$where keeps $placeholder" );
            }

            // ...and a real campaign is still one.
            $this->assertSame( 0, preg_match( '/' . $exclusion . '/', 'spring_sale' ), "$where drops a real campaign" );
            $this->assertSame( 0, preg_match( '/' . $exclusion . '/', 'organic' ), "$where drops a campaign named organic" );
        }
    }
}
