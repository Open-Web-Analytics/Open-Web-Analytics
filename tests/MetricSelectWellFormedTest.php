<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The SELECT of every registered metric, built as a report query builds it:
 * bound to a Property with its currency, while a query is pending on the shared
 * database object.
 *
 * An aggregate metric gives one well-formed expression over its own table's
 * columns. A calculated one names registered children. Building any of them
 * leaves the pending query as it was.
 */
final class MetricSelectWellFormedTest extends TestCase
{
    /** @return array<string, \OWA\Core\Metric> name => metric, every implementation of every metric */
    private function metrics( string $propertyId, ?string $currency ): array
    {
        $out = [];

        foreach ( (array) \OWA\Core\CoreAPI::getAllMetrics() as $name => $implementations ) {

            foreach ( (array) $implementations as $i => $implementation ) {

                $metric = \OWA\Core\CoreAPI::metricFactory(
                    $implementation['class'], $implementation['params'] ?? [] );

                if ( ! $metric->isCalculated() ) {

                    $metric->setPropertyId( $propertyId, $currency );
                }

                $out[ $name . '#' . $i ] = $metric;
            }
        }

        $this->assertGreaterThan( 40, count( $out ), 'the registry is loaded' );

        return $out;
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function properties(): array
    {
        return [
            'no Property'           => [ '', null ],
            'a USD Property'        => [ '7001', 'USD' ],
            'a JPY Property'        => [ '7002', 'JPY' ],
            'a KWD Property'        => [ '7003', 'KWD' ],
        ];
    }

    /**
     * @dataProvider properties
     */
    public function testEveryAggregateIsOneWellFormedExpression( string $propertyId, ?string $currency ): void
    {
        $kinds = [];

        foreach ( $this->metrics( $propertyId, $currency ) as $key => $metric ) {

            if ( $metric->isCalculated() ) {

                continue;
            }

            [ $sql, $alias ] = $metric->getSelect();

            $this->assertIsString( $sql, "$key has no expression" );
            $this->assertSame( $metric->getName(), $alias, $key );
            $this->assertStringNotContainsString( '%', $sql, "$key: an unfilled sprintf placeholder" );
            $this->assertSame( 0, substr_count( $sql, "'" ) % 2, "$key: unbalanced quotes in $sql" );

            // Parentheses balance outside quoted literals.
            $bare  = (string) preg_replace( "/'[^']*'/", "''", $sql );
            $depth = 0;

            foreach ( str_split( $bare ) as $char ) {

                $depth += $char === '(' ? 1 : ( $char === ')' ? -1 : 0 );
                $this->assertGreaterThanOrEqual( 0, $depth, "$key: a ) before its ( in $sql" );
            }

            $this->assertSame( 0, $depth, "$key: unbalanced parentheses in $sql" );
            $this->assertSame( substr_count( $bare, 'CASE' ), substr_count( $bare, 'END' ), "$key: a CASE without END in $sql" );

            $this->assertMatchesRegularExpression( '/^(COALESCE\()?(count|sum|round|avg)\(/i', $sql,
                "$key: not an aggregate: $sql" );

            // Every column it reads is a column of its own table, under its alias.
            $entity = \OWA\Core\CoreAPI::entityFactory( (string) $metric->getEntityName() );
            $prefix = preg_quote( (string) $metric->getTableAlias(), '/' );

            preg_match_all( '/\b([a-z_][a-z0-9_]*)\.([a-z_][a-z0-9_]*)\b/i', $bare, $refs, PREG_SET_ORDER );

            $this->assertNotEmpty( $refs, "$key reads no column: $sql" );

            foreach ( $refs as [ , $table, $column ] ) {

                $this->assertMatchesRegularExpression( "/^$prefix$/", $table, "$key: $table.$column is not its table's alias" );
                $this->assertNotNull( $entity->getProperty( $column ), "$key: $column is not a column of {$metric->getEntityName()}" );
            }

            $kinds[ (string) $metric->type ] = true;
        }

        foreach ( [ 'count', 'distinct_count', 'sum' ] as $kind ) {

            $this->assertArrayHasKey( $kind, $kinds, "no $kind metric was built" );
        }
    }

    public function testEveryCalculatedMetricNamesRegisteredChildren(): void
    {
        $registered = (array) \OWA\Core\CoreAPI::getAllMetrics();
        $seen       = 0;

        foreach ( $this->metrics( '', null ) as $key => $metric ) {

            if ( ! $metric->isCalculated() ) {

                continue;
            }

            $seen++;

            $this->assertNotEmpty( $metric->getChildMetrics(), $key );

            foreach ( $metric->getChildMetrics() as $child ) {

                $this->assertArrayHasKey( $child, $registered, "$key names $child, which is not registered" );
            }
        }

        $this->assertGreaterThan( 0, $seen );
    }

    /**
     * Property ids no other case uses: a settings lookup is cached per
     * Property, and a cached one would not reach the database object.
     *
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function unseenProperties(): array
    {
        return [
            'no Property'    => [ '', null ],
            'a USD Property' => [ '7101', 'USD' ],
            'a JPY Property' => [ '7102', 'JPY' ],
        ];
    }

    /**
     * getSelect() runs while the report query is being built on the shared
     * database object. Nothing it does may touch that query: a Property
     * settings lookup there loaded the Property through it and cleared it.
     *
     * @dataProvider unseenProperties
     */
    public function testBuildingASelectLeavesThePendingQueryAlone( string $propertyId, ?string $currency ): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $saved = $db->_sqlParams;

        try {
            $db->_sqlParams = [];
            $db->selectFrom( 'owa_metric_select_probe', 'event' );
            $db->where( 'event.site_id', 'probe' );
            $db->groupBy( 'event.page_path' );

            $pending = $db->_sqlParams;

            foreach ( $this->metrics( $propertyId, $currency ) as $key => $metric ) {

                if ( ! $metric->isCalculated() ) {

                    $metric->getSelect();
                }

                $this->assertSame( $pending, $db->_sqlParams, "building $key changed the pending query" );
            }
        } finally {
            $db->_sqlParams = $saved;
        }
    }
}
