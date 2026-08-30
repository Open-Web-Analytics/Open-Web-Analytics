<?php

namespace OWA\Tests;

/**
 * A recording of the metric and dimension catalog, exactly as it is registered
 * today.
 *
 * WHY THIS EXISTS
 * ---------------
 * The catalog is about to be changed so that two schema generations can be
 * registered under one name -- `visits` computed from the v1 star and `visits`
 * computed from the v2 event table, told apart by their entity. That work is
 * behaviour-preserving for the single-generation case, and this harness is what
 * makes "behaviour-preserving" checkable rather than asserted.
 *
 * It deliberately records the catalog INCLUDING two defects, because a
 * characterization suite that quietly corrects what it finds cannot prove
 * anything about the change that corrects it:
 *
 *   1. REGISTRATION-TIME LOSS. registerDimension() loops $entity_names but the
 *      normalized branch writes $this->dimensions[$dim_name], overwriting itself
 *      on each turn, so only the last entity survives. Three dimensions are
 *      registered against seven entities and keep one.
 *
 *   2. READ-TIME LOSS. CoreAPI::getAllDimensions() flattens the correctly
 *      entity-keyed denormalizedDimensions with $dims[$k] = $dedim, so 223
 *      entity-entries collapse to 59 names -- again keeping the last.
 *
 * Both are recorded as counts below. When they are fixed the fixture changes,
 * and the diff is the evidence that the fix did what it claimed.
 *
 * Values are normalised so the fixture says the same thing on any machine and
 * in any environment: entries are sorted, and only the declarative keys are
 * kept. Nothing here touches the database -- registration is pure PHP -- so this
 * runs in the configless CI environment where most of the suite skips.
 */
final class CatalogCharacterizationHarness
{
    /** Declarative keys of a dimension registration; anything else is scaffolding. */
    private const DIMENSION_KEYS = array(
        'name', 'entity', 'column', 'family', 'label', 'description',
        'foreign_key_name', 'data_type', 'denormalized',
    );

    /** Declarative keys of a metric implementation. */
    private const METRIC_KEYS = array( 'name', 'class', 'label', 'group' );

    /**
     * The whole catalog, in a shape that is stable across machines.
     *
     * @return array<string,mixed>
     */
    public static function snapshot(): array
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();

        $normalized   = self::readProperty( $service, 'dimensions' );
        $denormalized = self::readProperty( $service, 'denormalizedDimensions' );
        $metrics      = self::readProperty( $service, 'metrics' );

        return array(
            'counts'                  => self::counts( $normalized, $denormalized, $metrics ),
            'metrics'                 => self::normaliseMetrics( $metrics ),
            'dimensionsNormalized'    => self::normaliseFlatDimensions( $normalized ),
            'dimensionsDenormalized'  => self::normaliseNestedDimensions( $denormalized ),
            'accessorGetAllDimensions'=> self::normaliseFlatDimensions(
                                            (array) \OWA\Core\CoreAPI::getAllDimensions() ),
            'relatedDimensions'       => self::relatedDimensions(),
        );
    }

    /**
     * The numbers that the coming change is expected to move.
     *
     * Kept as their own block so a reviewer can see the shape of the catalog
     * without reading 200 entries, and so a change to the totals is visible in
     * a diff even when individual entries are untouched.
     *
     * @return array<string,int>
     */
    public static function counts( array $normalized, array $denormalized, array $metrics ): array
    {
        $denormalizedEntries = 0;

        foreach ( $denormalized as $byEntity ) {

            $denormalizedEntries += count( (array) $byEntity );
        }

        $implementations = 0;

        foreach ( $metrics as $list ) {

            $implementations += count( (array) $list );
        }

        return array(
            'metricNames'                  => count( $metrics ),
            'metricImplementations'        => $implementations,
            'dimensionNamesNormalized'     => count( $normalized ),
            /*
             * Equal to dimensionNamesNormalized today, and that equality IS the
             * registration-time defect: a name registered against seven entities
             * contributes one entry. When the registry becomes entity-keyed this
             * number rises and the two stop matching.
             */
            'dimensionEntriesNormalized'   => self::countFlatEntries( $normalized ),
            'dimensionNamesDenormalized'   => count( $denormalized ),
            'dimensionEntriesDenormalized' => $denormalizedEntries,
            /*
             * The read-side view. Lower than dimensionEntriesDenormalized
             * because getAllDimensions() keeps one entry per name.
             */
            'accessorDimensionNames'       => count( (array) \OWA\Core\CoreAPI::getAllDimensions() ),
        );
    }

    /**
     * How many (name, entity) pairs a flat registry actually holds.
     *
     * Written to tolerate BOTH shapes on purpose: name => entry today, and
     * name => entity => entry after the change. Without that the harness would
     * have to be rewritten by the same commit it is meant to be judging, which
     * would leave nothing independent to judge with.
     */
    public static function countFlatEntries( array $registry ): int
    {
        $entries = 0;

        foreach ( $registry as $entry ) {

            $entries += self::isEntityKeyed( $entry ) ? count( $entry ) : 1;
        }

        return $entries;
    }

    /**
     * True when an entry is a map of entity name => registration rather than a
     * registration itself.
     *
     * A registration always carries a 'name'; a map of them never does.
     */
    public static function isEntityKeyed( $entry ): bool
    {
        return is_array( $entry ) && ! array_key_exists( 'name', $entry );
    }

    /** @return array<string,mixed> */
    private static function normaliseFlatDimensions( array $registry ): array
    {
        $out = array();

        foreach ( $registry as $name => $entry ) {

            $out[ $name ] = self::isEntityKeyed( $entry )
                ? self::normaliseNestedEntry( (array) $entry )
                : self::pick( (array) $entry, self::DIMENSION_KEYS );
        }

        ksort( $out );

        return $out;
    }

    /** @return array<string,mixed> */
    private static function normaliseNestedDimensions( array $registry ): array
    {
        $out = array();

        foreach ( $registry as $name => $byEntity ) {

            $out[ $name ] = self::normaliseNestedEntry( (array) $byEntity );
        }

        ksort( $out );

        return $out;
    }

    /** @return array<string,mixed> */
    private static function normaliseNestedEntry( array $byEntity ): array
    {
        $entities = array();

        foreach ( $byEntity as $entity => $entry ) {

            $entities[ $entity ] = self::pick( (array) $entry, self::DIMENSION_KEYS );
        }

        ksort( $entities );

        return $entities;
    }

    /** @return array<string,array<int,array>> */
    private static function normaliseMetrics( array $metrics ): array
    {
        $out = array();

        foreach ( $metrics as $name => $implementations ) {

            $rows = array();

            foreach ( (array) $implementations as $implementation ) {

                $row = self::pick( (array) $implementation, self::METRIC_KEYS );

                /*
                 * params carries the entity and column for a configured metric,
                 * and the formula and children for a calculated one. It is the
                 * part that says WHERE a number comes from, so it is the part
                 * this change is most likely to disturb.
                 */
                $row['params'] = self::normaliseParams(
                    (array) ( $implementation['params'] ?? array() ) );

                $rows[] = $row;
            }

            /* Registration order is not part of the contract; content is. */
            usort( $rows, static function ( array $a, array $b ): int {

                return strcmp( json_encode( $a ), json_encode( $b ) );
            } );

            $out[ $name ] = $rows;
        }

        ksort( $out );

        return $out;
    }

    /** @return array<string,mixed> */
    private static function normaliseParams( array $params ): array
    {
        ksort( $params );

        foreach ( $params as $key => $value ) {

            if ( is_array( $value ) ) {

                sort( $value );

                $params[ $key ] = $value;
            }
        }

        return $params;
    }

    /** @return array<string,mixed> */
    private static function pick( array $entry, array $keys ): array
    {
        $out = array();

        foreach ( $keys as $key ) {

            if ( array_key_exists( $key, $entry ) ) {

                $out[ $key ] = $entry[ $key ];
            }
        }

        return $out;
    }

    /** Read a public-but-undeclared registry off the service. */
    private static function readProperty( object $service, string $name ): array
    {
        $reflection = new \ReflectionObject( $service );

        if ( ! $reflection->hasProperty( $name ) ) {

            return array();
        }

        $property = $reflection->getProperty( $name );

        $property->setAccessible( true );

        return (array) $property->getValue( $service );
    }

    /** Entities a report is commonly built on. */
    public const RELATED_ENTITIES = array(
        'base.session', 'base.request', 'base.action_fact', 'base.click', 'base.domstream',
    );

    /**
     * Which dimensions each entity can actually reach.
     *
     * Recorded because ResultSetManager::getAllRelatedDimensions() reads the
     * dimension registry as a PUBLIC PROPERTY rather than through an accessor,
     * so changing the registry's shape changes what it sees without any
     * accessor being involved. Nothing covered it, and a shape change silently
     * halved its answer -- 305 dimensions to 153 -- while every unit test and
     * both accessors stayed byte-identical. The reports that broke did so by
     * dropping constraints, which is the failure this codebase already knows
     * to be silent.
     *
     * @return array<string,array<string,array<int,string>>>
     */
    public static function relatedDimensions(): array
    {
        $manager = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'resultSetManager' );

        $out = array();

        foreach ( self::RELATED_ENTITIES as $entityName ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $entityName );

            $families = (array) $manager->getAllRelatedDimensions( $entity );

            $normalised = array();

            foreach ( $families as $family => $dimensions ) {

                $names = array();

                foreach ( (array) $dimensions as $dimension ) {

                    $names[] = (string) ( $dimension['name'] ?? '' );
                }

                sort( $names );

                $normalised[ $family ] = $names;
            }

            ksort( $normalised );

            $out[ $entityName ] = $normalised;
        }

        return $out;
    }

    /** Absolute path to the recorded catalog. */
    public static function fixturePath(): string
    {
        return __DIR__ . '/fixtures/catalog.json';
    }
}
