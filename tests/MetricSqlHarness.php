<?php

namespace OWA\Tests;

/**
 * The SQL every registered metric resolves to.
 *
 * WHY THIS EXISTS
 * ---------------
 * Metrics are registered two ways: 40 as class references and 6 as
 * configuration. Converting the former to the latter is mechanical -- the
 * classes are declarations in class clothing, and no metric class in the tree
 * contains SQL -- but "mechanical" is a claim, and this is what makes it
 * checkable.
 *
 * The existing catalog recording cannot do it. That records each metric's
 * IMPLEMENTATION -- its class name and params -- so a conversion changes it by
 * design, and the diff proves nothing about whether the numbers moved. What has
 * to hold is the resolved expression: COUNT(DISTINCT session_id) must still be
 * COUNT(DISTINCT session_id) once the class that produced it is a config entry.
 *
 * So this records behaviour rather than shape, and the two recordings answer
 * different questions on purpose:
 *
 *     catalog.json     what is registered, and how
 *     metric-sql.json  what it computes
 *
 * Nothing else pins this today. A conversion verified only against the catalog
 * recording would be verified against the shape of the code it just rewrote --
 * which is the mistake of regenerating a fixture from changed code and calling
 * it evidence.
 */
final class MetricSqlHarness
{
    /**
     * Every metric's resolved select, keyed by name.
     *
     * A calculated metric has no select of its own -- it is arithmetic over
     * other metrics' results -- so its formula and children are recorded
     * instead. That distinction is itself worth pinning: it is the reason
     * calculated metrics need no SQL renderer and port to another store for
     * free.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function snapshot(): array
    {
        $out = array();

        foreach ( (array) \OWA\Core\CoreAPI::getAllMetrics() as $name => $implementations ) {

            $rows = array();

            foreach ( (array) $implementations as $implementation ) {

                $rows[] = self::describe( $implementation );
            }

            /* Registration order is not part of the contract; content is. */
            usort( $rows, static function ( array $a, array $b ): int {

                return strcmp( json_encode( $a ), json_encode( $b ) );
            } );

            $out[ (string) $name ] = $rows;
        }

        ksort( $out );

        return $out;
    }

    /**
     * One implementation, described by what it computes.
     *
     * @return array<string,mixed>
     */
    public static function describe( array $implementation ): array
    {
        $metric = \OWA\Core\CoreAPI::metricFactory(
            $implementation['class'], $implementation['params'] ?? array() );

        if ( $metric->isCalculated() ) {

            $children = (array) $metric->getChildMetrics();

            sort( $children );

            return array(
                'kind'     => 'calculated',
                'formula'  => (string) $metric->getFormula(),
                'children' => $children,
            );
        }

        /*
         * getSelect() answers array( expression, alias ). The expression is the
         * part that must survive a conversion; the alias is the column name the
         * result set is keyed by, so a change there would rename a field in
         * every report that uses it.
         */
        $select = (array) $metric->getSelect();

        return array(
            /*
             * No aggregation type recorded: the expression already states it
             * (COUNT, COUNT(DISTINCT ...), SUM), and there is no getter for it
             * anyway. Recording the rendered expression rather than the
             * declared type is the point -- the type is what a metric SAYS, the
             * expression is what the database receives.
             */
            'kind'       => 'aggregate',
            'entity'     => (string) $metric->getEntityName(),
            'expression' => isset( $select[0] ) ? (string) $select[0] : null,
            'alias'      => isset( $select[1] ) ? (string) $select[1] : null,
        );
    }

    public static function fixturePath(): string
    {
        return __DIR__ . '/fixtures/metric-sql.json';
    }
}
