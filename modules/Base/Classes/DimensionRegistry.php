<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The dimensions a query of one Property may name: the ones the modules
 * register, which every Property has, and that Property's applied custom
 * dimensions.
 *
 * THE ONE SEAM. Everything that offers, validates or resolves a dimension name
 * asks this, naming the Property it is about: the query engine
 * (ResultSetManager, with its own Property), the custom report and funnel
 * editors and their validation, a funnel step's conditions (EventPredicate),
 * and CoreAPI::getAllDimensions().
 *
 * PER PROPERTY, because a custom dimension is a column of one Property's cube
 * (Cube\Dimensions). Offered for another cube, `cd_plan` would pass validation
 * and then lose its breakdown to MySQL's "Unknown column", which the result set
 * swallows (config/dimensions.php).
 *
 * NEVER IN THE PROCESS-WIDE REGISTRY. Service's registries are read as the
 * modules loaded them, and the custom dimensions sit beside them keyed by
 * Property, so an answer for B cannot carry A's however many Properties one
 * process has served, and a list built before anything names a Property is
 * not missing them.
 *
 * forProperty( null ) is the module vocabulary alone, for a caller that is
 * about no Property; each such caller says why.
 *
 * Memoised per Property for the process: one indexed read of the
 * registrations per Property. Cube\Dimensions forgets a Property when one of
 * its registrations changes state or is removed, so a dimension applied earlier
 * in the same process is not missed.
 *
 * An entry is a registration as config/dimensions.php declares it (name,
 * entity, column, label, data_type, family and more); the keys vary by
 * module, so it is name => value of any type.
 *
 * @phpstan-type DimensionEntry array<string, mixed>
 */
class DimensionRegistry {

    /** @var self[] property id ('' for none) => its registry */
    private static $byProperty = [];

    /** @var string the Property, or '' */
    private $propertyId;

    /** @var array<string, DimensionEntry> name => entry, the Property's applied custom dimensions, oldest first */
    private $custom;

    /**
     * @param string                         $property_id
     * @param array<string, DimensionEntry>  $custom
     */
    private function __construct( $property_id, array $custom ) {

        $this->propertyId = (string) $property_id;
        $this->custom     = $custom;
    }

    /**
     * The dimensions of one Property, or of none.
     *
     * @param  int|string|null $property_id  null or '' for the module vocabulary alone
     * @return self
     */
    public static function forProperty( $property_id ) {

        $key = (string) $property_id;

        if ( ! isset( self::$byProperty[ $key ] ) ) {

            self::$byProperty[ $key ] = new self( $key, $key === '' ? [] : self::customOf( $key ) );
        }

        return self::$byProperty[ $key ];
    }

    /**
     * Drop what is memoised, for one Property or for all of them.
     *
     * @param int|string|null $property_id
     * @return void
     */
    public static function forget( $property_id = null ) {

        if ( $property_id === null ) {

            self::$byProperty = [];

            return;
        }

        unset( self::$byProperty[ (string) $property_id ] );
    }

    /**
     * A Property's applied custom dimensions, as registry entries.
     *
     * Applied only: a pending or failed registration names a column the cube
     * does not have yet.
     *
     * @param  string $property_id
     * @return array<string, DimensionEntry> name => entry
     */
    private static function customOf( $property_id ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $out     = [];

        foreach ( Cube\Dimensions::forProperty( $property_id ) as $row ) {

            $row = (array) $row;

            if ( (string) ( $row['state'] ?? '' ) !== \OWA\Module\Base\Entity\CustomDimension::STATE_APPLIED ) {

                continue;
            }

            $dimension = Cube\Dimensions::asDimension( $row );

            // The cd_ prefix already rules a clash out; this keeps a module's
            // dimension from ever being replaced if that changes.
            if ( isset( $service->denormalizedDimensions[ $dimension['name'] ] ) ) {

                continue;
            }

            $out[ $dimension['name'] ] = $dimension;
        }

        return $out;
    }

    /** @return string the Property, or '' */
    public function propertyId() {

        return $this->propertyId;
    }

    /**
     * Every dimension, one entry per name.
     *
     * The modules' registry is keyed name => entity => registration, and this
     * flattens it by letting the last entity win. The custom dimensions come
     * last; a name of theirs is never a module's (customOf()).
     *
     * Pass $entities to scope the answer: a dimension is offered only if it is
     * defined on one of them, and a name defined on none is absent.
     *
     * @param  string[]|null $entities entity names to restrict the answer to
     * @return array<string, DimensionEntry> name => entry
     */
    public function all( $entities = null ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $custom  = [];

        foreach ( $this->custom as $name => $dimension ) {

            $custom[ $name ][ $dimension['entity'] ] = $dimension;
        }

        $dims = [];

        foreach ( [ $service->denormalizedDimensions, $custom ] as $registry ) {

            foreach ( $registry as $name => $entity_dims ) {

                foreach ( $entity_dims as $entity => $dim ) {

                    if ( $entities !== null && ! in_array( $entity, (array) $entities, true ) ) {

                        continue;
                    }

                    $dims[ $name ] = $dim;
                }
            }
        }

        return $dims;
    }

    /**
     * Whether a name is a dimension here, on any entity.
     *
     * @param  string $name
     * @return bool
     */
    public function has( $name ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $name    = (string) $name;

        return isset( $this->custom[ $name ] )
            || isset( $service->denormalizedDimensions[ $name ] );
    }

    /**
     * A dimension that is a column (or an expression) of this entity's table.
     *
     * @param  string $name
     * @param  string $entity an entity name
     * @return DimensionEntry|null
     */
    public function denormalized( $name, $entity ) {

        $dim = \OWA\Core\CoreAPI::serviceSingleton()->getDenormalizedDimension( $name, $entity );

        if ( $dim ) {

            return $dim;
        }

        $custom = $this->custom[ (string) $name ] ?? null;

        return $custom && $custom['entity'] === $entity ? $custom : null;
    }

    /**
     * Every denormalized dimension of one entity, modules' first.
     *
     * @param  string $entity an entity name
     * @return array<string, DimensionEntry> name => entry
     */
    public function onEntity( $entity ) {

        $out = [];

        foreach ( \OWA\Core\CoreAPI::serviceSingleton()->denormalizedDimensions as $name => $by_entity ) {

            if ( isset( $by_entity[ $entity ] ) ) {

                $out[ $name ] = $by_entity[ $entity ];
            }
        }

        foreach ( $this->custom as $name => $dimension ) {

            if ( $dimension['entity'] === $entity ) {

                $out[ $name ] = $dimension;
            }
        }

        return $out;
    }

    /**
     * The Property's applied custom dimensions, as registry entries
     * (Cube\Dimensions::asDimension()), oldest first.
     *
     * @return list<DimensionEntry>
     */
    public function custom() {

        return array_values( $this->custom );
    }
}
