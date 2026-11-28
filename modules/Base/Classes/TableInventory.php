<?php
namespace OWA\Module\Base\Classes;

/**
 * The tables in OWA's database, by what the running code makes of them.
 *
 * A table is IN USE when an entity of a module present owns it: one in the
 * module class's ENTITIES, whether or not the module is active, since a
 * deactivated module's tables are still its own. The modules are not built
 * to ask: each module directory's class is read for its NAME and ENTITIES,
 * and each entity for its table name and its backup group
 * (Entity::getBackupGroup()). The cubes are in use too, but owned by no
 * entity: one table per Property, named owa_event_<id>, with build tables
 * named after it. A cube is in use while its Property's row exists, archived
 * or not.
 *
 * Everything else under the prefix is OLD: a 1.x table no v2 update renamed
 * or dropped, a table of a module no longer installed, a table nothing has
 * created since, the cube of a Property whose row is gone. The 1.x tracking
 * tables are kept apart (Migration\V1Tables), because cmd=v1-drop drops those
 * and records that it did.
 *
 * Read only while the schema is current: before a pending update runs, a
 * table it is about to rename (Update076's goal tables) has no entity yet.
 */
class TableInventory {

    const APPLICATION = \OWA\Core\Entity::BACKUP_APPLICATION;
    const DATA        = \OWA\Core\Entity::BACKUP_DATA;
    const CUBE        = 'cube';
    const V1          = 'v1';
    const OLD         = 'old';

    /** @var array<string, string>|null unprefixed table => backup group, from the entities */
    private static $owned = null;

    /** @var \OWA\Core\Db */
    private $db;

    /** @var string */
    private $prefix;

    /** @var array<string, string> */
    private $owned_here;

    /**
     * @param \OWA\Core\Db               $db
     * @param string                     $prefix the tables' prefix; a test names its own
     * @param array<string, string>|null $owned  in place of owned(), for a test
     */
    public function __construct( $db, $prefix = \OWA\Core\Entity::TABLE_PREFIX, $owned = null ) {

        $this->db         = $db;
        $this->prefix     = (string) $prefix;
        $this->owned_here = $owned ?? self::owned();
    }

    /**
     * The tables present, sorted, under each of APPLICATION, DATA, CUBE, V1
     * and OLD. CUBE holds the cubes and build tables of the Properties whose
     * rows exist; those of any other Property are OLD.
     *
     * @return array<string, list<string>>
     */
    public function classify() {

        $out        = [ self::APPLICATION => [], self::DATA => [], self::CUBE => [], self::V1 => [], self::OLD => [] ];
        $tables     = $this->db->listTables( $this->prefix );
        $properties = $this->properties( $tables );

        foreach ( $tables as $name ) {

            $table    = substr( $name, strlen( $this->prefix ) );
            $property = $this->cubeProperty( $name );

            if ( $property !== null ) {

                $out[ $properties === null || isset( $properties[ $property ] ) ? self::CUBE : self::OLD ][] = $name;

            } elseif ( isset( $this->owned_here[ $table ] ) ) {

                $out[ $this->owned_here[ $table ] ][] = $name;

            } elseif ( in_array( $table, Migration\V1Tables::all(), true ) ) {

                $out[ self::V1 ][] = $name;

            } else {

                $out[ self::OLD ][] = $name;
            }
        }

        return $out;
    }

    /**
     * Whether $name is a cube or one of its build tables, under this prefix.
     *
     * @param  string $name a table name, prefixed
     * @return bool
     */
    public function isCube( $name ) {

        return $this->cubeProperty( $name ) !== null;
    }

    /**
     * The Property id a cube or build table is named for; null for any other
     * table.
     *
     * @param  string $name
     * @return string|null
     */
    private function cubeProperty( $name ) {

        $cube = '/^' . preg_quote( $this->prefix . Cube\Cubes::PREFIX, '/' ) . '([0-9]+)/';

        return preg_match( $cube, (string) $name, $m ) ? ( ltrim( $m[1], '0' ) ?: '0' ) : null;
    }

    /**
     * The ids of the Property rows, archived or not, as keys; null when the
     * Property table is not among $tables or cannot be read, and then every
     * cube is taken to be in use.
     *
     * @param  list<string> $tables the tables under the prefix
     * @return array<string, true>|null
     */
    private function properties( array $tables ) {

        $table = $this->prefix . substr( \OWA\Core\CoreAPI::entityFactory( 'base.property' )->getTableName(),
            strlen( \OWA\Core\Entity::TABLE_PREFIX ) );

        if ( ! in_array( $table, $tables, true ) ) {

            return null;
        }

        $errors = $this->db->queryErrorCount();
        $rows   = $this->db->get_results( sprintf( 'SELECT id FROM `%s`', $table ) );

        if ( $this->db->queryErrorCount() !== $errors ) {

            return null;
        }

        $ids = [];

        foreach ( (array) $rows as $row ) {

            $ids[ (string) $row['id'] ] = true;
        }

        return $ids;
    }

    /**
     * Every present module's entities' tables, unprefixed, with the backup
     * group each declares. No module is built: each is read from its class.
     *
     * @return array<string, string> APPLICATION or DATA
     */
    public static function owned() {

        return self::$owned ??= self::ownedBy( self::moduleClasses() );
    }

    /**
     * The module class of every module directory, active or not, that
     * declares its own NAME (Core\Module::declaresName()).
     *
     * @return list<class-string<\OWA\Core\Module>>
     */
    public static function moduleClasses() {

        $classes = [];

        foreach ( (array) glob( \OWA\Core\Env::modulesDir() . '*/Module.php' ) as $file ) {

            $class = 'OWA\\Module\\' . basename( dirname( (string) $file ) ) . '\\Module';

            if ( \OWA\Core\Module::declaresName( $class ) ) {

                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The tables the ENTITIES of $classes own, unprefixed, with the backup
     * group each entity declares. Each entity is built by the entity factory;
     * the modules are not built.
     *
     * @param  list<class-string<\OWA\Core\Module>> $classes
     * @return array<string, string> APPLICATION or DATA
     */
    public static function ownedBy( array $classes ) {

        $owned = [];

        foreach ( $classes as $class ) {

            foreach ( (array) $class::ENTITIES as $entity ) {

                $e     = \OWA\Core\CoreAPI::entityFactory( $class::NAME . '.' . $entity );
                $table = substr( $e->getTableName(), strlen( \OWA\Core\Entity::TABLE_PREFIX ) );

                $owned[ $table ] = $e->getBackupGroup() === self::DATA ? self::DATA : self::APPLICATION;
            }
        }

        ksort( $owned );

        return $owned;
    }
}
