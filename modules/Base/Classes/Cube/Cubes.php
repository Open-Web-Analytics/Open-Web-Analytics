<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The cubes: one reporting table per Property, and the map between them.
 *
 * There is no owa_event. A Property's cube is owa_event_<property id>, and
 * everything that needs to go from one to the other -- a name to a Property, a
 * Property to the site ids whose rows belong in it, a Property to a cube that
 * may not exist yet -- comes through here so the answer is the same everywhere.
 *
 * WHY PER PROPERTY. The cube is what reports read and what a registered custom
 * dimension adds a column to, and both of those are the Property's business:
 * GA registers custom definitions on the property for the same reason. Sharing
 * one cube would force one namespace on every Property in the installation --
 * cv3 meaning a different thing per site, which is the v1 failure custom
 * dimensions exist to end -- and would share InnoDB's row budget out between
 * them, so one Property could exhaust the rest. Split, each has its own.
 *
 * RAW IS NOT SPLIT. owa_event_raw is written by ingest on the hot path and read
 * by nothing but a build, so splitting it would multiply the write path's table
 * count for no reader. Nor is owa_visitor_acquisition: a visitor is shared
 * across Properties by one cookie, and splitting it would give one person a
 * first touch per Property, which is a different claim from the one it makes.
 *
 * A CUBE IS CREATED ON FIRST DATA, BY THE BUILD. Not when the Property is
 * created: a cube is seventy-odd partitions and about 2.7 seconds, and this
 * installation has 217 Properties of which one has ever collected anything.
 * The build is where the creation belongs -- it runs on a schedule, it holds a
 * per-cube lock, and it is already asking which partitions hold rows -- and
 * keeping it out of ingest is the point: "create it on the first event" puts a
 * 2.7-second CREATE TABLE inside a beacon request, with every concurrent first
 * event of that Property racing to do it.
 */
class Cubes {

    /**
     * What a cube's name is made of.
     *
     * owa_event_raw is not a cube and cannot be mistaken for one: the suffix
     * has to be digits.
     */
    const PREFIX = 'event_';

    /** @var array<string,string> site id => Property id, for this request */
    private static $property_of_site = array();

    /**
     * The cube table for a Property.
     *
     * @param int|string $property_id
     * @return string  table name, or '' if that is not a Property id
     */
    public static function tableFor( $property_id ) {

        if ( ! ctype_digit( (string) $property_id ) || (string) $property_id === '0' ) {

            return '';
        }

        return \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . self::PREFIX . $property_id;
    }

    /**
     * The Property a cube table belongs to.
     *
     * @param string $table
     * @return string  the id as written, or '' if that is not a cube
     */
    public static function propertyIdFor( $table ) {

        $prefix = \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . self::PREFIX;

        if ( strpos( (string) $table, $prefix ) !== 0 ) {

            return '';
        }

        $id = substr( (string) $table, strlen( $prefix ) );

        return ctype_digit( $id ) ? $id : '';
    }

    /**
     * The entity for a Property's cube: the shape, bound to that table.
     *
     * @param int|string $property_id
     * @return \OWA\Module\Base\Entity\Event|null
     */
    public static function entityFor( $property_id ) {

        if ( ! self::tableFor( $property_id ) ) {

            return null;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );

        $entity->bindToProperty( $property_id );

        return $entity;
    }

    /**
     * Every cube that exists in the database, oldest Property id first.
     *
     * Read from the server rather than from the Property list, because what
     * the partition commands and the build have to act on is the tables that
     * are there -- including one whose Property has since been deleted, which
     * nothing else would name.
     *
     * @return array  property id => table name
     */
    public static function existing() {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $prefix = \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . self::PREFIX;
        $cubes  = array();

        foreach ( (array) $db->get_results( sprintf(
                OWA_SQL_SHOW_TABLE, $db->prepare( $prefix ) . '%' ) ) as $row ) {

            $table = reset( $row );
            $id    = self::propertyIdFor( $table );

            if ( $id !== '' ) {

                $cubes[ $id ] = $table;
            }
        }

        ksort( $cubes, SORT_STRING );

        return $cubes;
    }

    /**
     * The name the one installation-wide cube had, before the split.
     *
     * owa_event existed between schema 35 and 41. Nothing creates it now, and
     * Update041 drops it -- but the updates that gave it its columns still have
     * to be able to name it, because a fresh installation replays all of them
     * in order before reaching the one that removes it.
     */
    const PRE_SPLIT = 'event';

    /** @return string  the pre-split cube's table name */
    public static function preSplitTable() {

        return \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . self::PRE_SPLIT;
    }

    /** @return \OWA\Module\Base\Entity\Event  the shape bound to it */
    public static function preSplitEntity() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );

        $entity->bindToTable( self::PRE_SPLIT );

        return $entity;
    }

    /**
     * EVERY table of the cube shape that exists, per Property or not.
     *
     * What a schema update has to act on: adding a column to "the cube" means
     * adding it to all of them, and during the upgrade that introduces the
     * split it means the pre-split one too. The partition commands use
     * existing() instead -- owa_event is on its way out and there is no point
     * maintaining a lead on it.
     *
     * @return string[]
     */
    public static function allTables() {

        $tables = array_values( self::existing() );
        $legacy = self::preSplitTable();

        if ( \OWA\Core\CoreAPI::dbSingleton()->tableExists( $legacy ) ) {

            $tables[] = $legacy;
        }

        return $tables;
    }

    /**
     * The site ids whose raw rows belong in a Property's cube.
     *
     * Read at build time rather than stamped on the raw row, so a profile moved
     * between Properties moves its history with it on the next rebuild instead
     * of leaving it in a cube nobody looks at. A build is convergent, so that
     * costs one rebuild rather than a migration.
     *
     * @param int|string $property_id
     * @return string[]
     */
    public static function siteIds( $property_id ) {

        if ( ! ctype_digit( (string) $property_id ) ) {

            return array();
        }

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName();
        $ids  = array();

        foreach ( (array) $db->get_results( sprintf(
                'SELECT site_id FROM %s WHERE property_id = %s',
                $site, (string) (int) $property_id ) ) as $row ) {

            $ids[] = $row['site_id'];
        }

        return $ids;
    }

    /**
     * The Property a Profile reports into, or '' when it has none.
     *
     * The reverse of siteIds(), and the direction reporting needs: a query
     * names a site, and the cube it must read belongs to that site's Property.
     * Memoised for the request because every metric and dimension of one report
     * asks the same question, and the answer cannot change under a request.
     *
     * A Profile with no Property has no cube to read, and says so with '' --
     * the caller then leaves the entity unbound, and getTableName() throws
     * rather than inventing a table.
     *
     * @param string $site_id
     * @return string the Property id, or ''
     */
    public static function propertyIdForSite( $site_id ) {

        $site_id = (string) $site_id;

        if ( $site_id === '' ) {

            return '';
        }

        if ( isset( self::$property_of_site[ $site_id ] ) ) {

            return self::$property_of_site[ $site_id ];
        }

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName();

        $row = $db->get_row( sprintf( "SELECT property_id FROM %s WHERE site_id = '%s'",
            $site, $db->prepare( $site_id ) ) );

        return self::$property_of_site[ $site_id ] =
            ( $row && ! empty( $row['property_id'] ) ) ? (string) $row['property_id'] : '';
    }

    /**
     * The Properties with raw rows in a date range.
     *
     * The range is bounded so the scan is partition-pruned: asking which
     * Properties have EVER collected would read the whole of raw, and what a
     * build needs is which of them have collected in the window it is about to
     * rebuild.
     *
     * A site id present in raw but not in owa_site belongs to no Property and
     * so to no cube. Counted rather than dropped silently -- it is either a
     * deleted profile or a beacon quoting an id nothing issued, and both are
     * worth saying out loud once.
     *
     * @param int $from yyyymmdd
     * @param int $to   yyyymmdd, inclusive
     * @return array ['properties' => string[], 'orphan_rows' => int]
     */
    public static function collecting( $from, $to ) {

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $raw  = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName();

        $rows = (array) $db->get_results( sprintf(
            'SELECT s.property_id AS property_id, COUNT(*) AS n FROM %s e '
          . '%s %s s ON s.site_id = e.site_id '
          . 'WHERE e.yyyymmdd >= %d AND e.yyyymmdd <= %d GROUP BY s.property_id',
            $raw, OWA_SQL_JOIN_LEFT_OUTER, $site, (int) $from, (int) $to ) );

        $properties = array();
        $orphans    = 0;

        foreach ( $rows as $row ) {

            $id = (string) $row['property_id'];

            if ( $id === '' || $id === '0' ) {

                $orphans += (int) $row['n'];

                continue;
            }

            $properties[] = $id;
        }

        sort( $properties, SORT_STRING );

        return array( 'properties' => $properties, 'orphan_rows' => $orphans );
    }

    /**
     * Create a Property's cube.
     *
     * Partitioned in the shape cmd=partition-rotate maintains, because
     * Db::createTable() reads the entity: the front of the lead daily, the rest
     * at the default granularity. A cube created monthly would rewrite a whole
     * month on every build until the first rotate ran.
     *
     * @param int|string $property_id
     * @return bool
     */
    public static function create( $property_id ) {

        $entity = self::entityFor( $property_id );

        if ( ! $entity ) {

            return false;
        }

        return $entity->createTable() !== false;
    }
}

?>
