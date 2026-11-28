<?php
namespace OWA\Core;

/**
 * OWA Core API
 * 
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 * @phpstan-import-type Bindings from \OWA\Core\Db
 * @phpstan-import-type RestRoute from \OWA\Module\Base\Classes\Service
 * @phpstan-import-type MetricDefinition from \OWA\Module\Base\Classes\Service
 * @phpstan-import-type DimensionEntry from \OWA\Module\Base\Classes\DimensionRegistry
 * @phpstan-type Scope array{type: string, id: string}
 * @phpstan-type SettingRows array{effective: array<string, mixed>, own: array<string, mixed>}
 */

class CoreAPI {

    /** @var array<string, bool>  site id => is it registered. See isSiteRegistered(). */
    protected static $registered_sites = [];

    /** @var array<string, SettingRows>  chain key => {effective, own} maps. See settingRowsForChain(). */
    protected static $setting_row_cache = [];

    /** @var array<string, array<string, mixed>>  "scope:id" => that scope's OWN rows, keyed module|name. */
    protected static $setting_scope_cache = [];

    /** @var array<string, list<Scope>>  "scope:id" => the scope chain to walk. */
    protected static $setting_chain_cache = [];

    /**
     * How the scopes outrank each other.
     *
     * A fixed order, not a per-setting one, and deliberately NOT a column on
     * owa_setting: the rank is a property of the scope, and the scope is
     * already in the row -- storing it again would be a second copy written on
     * every insert that never varies. Which scopes may hold a row at all DOES
     * vary per setting, and that is enforced at write, not here.
     *
     * @var array<string, int>
     */
    protected static $setting_scope_rank = [
        'install'      => 1,
        'organization' => 2,
        'property'     => 3,
        'profile'      => 4,
    ];

    /*
     * The per-process singletons and memos. forgetSingletons() clears them.
     */

    /** @var \OWA\Core\Db|null dbSingleton() */
    private static $db = null;

    /** @var \OWA\Module\Base\Classes\Settings|null configSingleton() */
    private static $config = null;

    /** @var \OWA\Module\Base\Classes\Error|null errorSingleton() */
    private static $error = null;

    /** @var \OWA\Module\Base\Classes\Service|null serviceSingleton() */
    private static $service = null;

    /** @var \OWA\Module\Base\Classes\Cache|null cacheSingleton() */
    private static $cache = null;

    /** @var \OWA\Core\RequestContainer|null requestContainerSingleton() */
    private static $request = null;

    /** @var bool whether handleRequest() has logged the request's param names */
    private static $request_logged = false;

    /** @var array<string, object> queue name => the queue getEventQueue() built */
    private static $event_queues = [];

    /** @var array<string, string[]>|null scheme => its defined SALT_CONSTANTS' values, read by getSalt() */
    private static $salts = null;

    /** @var array<string, true> module directories getPresentModules() has warned about */
    private static $warned_modules = [];

    /**
     * The driver name to actually load for a configured db_type.
     *
     * db_type = 'mysql' means "MySQL", and PDO is used to reach it wherever
     * pdo_mysql is available, else mysqli -- so a host that has mysqli but NOT
     * pdo_mysql keeps working. That happens in containers and hand-built PHP,
     * where extensions are enabled one at a time.
     *
     * Anything explicit is honoured as written:
     *
     *   'mysql'      MySQL, preferring PDO, falling back to mysqli   (default)
     *   'mysqli'     force the mysqli driver
     *   'pdo_mysql'  force MySQL over PDO
     *   'pdo'        friendly alias for pdo_mysql
     *   'pdo_pgsql'  Postgres over PDO, once a PgsqlDialect exists
     *
     * A third-party driver's type is passed through untouched.
     *
     * @param string    $type
     * @param bool|null $pdo_available whether pdo_mysql is loaded; null asks PHP
     * @return string
     */
    public static function resolveDbDriver($type, $pdo_available = null) {

        // Injectable so the fallback can be tested on a machine that has
        // pdo_mysql, which is every machine that runs the suite.
        if ( $pdo_available === null ) {

            $pdo_available = extension_loaded('pdo_mysql');
        }

        if ( $type === 'mysql' ) {

            // Both arms must name a driver the loader can find: the caller
            // turns this into the class owa_db_<type>. The legacy driver's own
            // token is 'mysql' -- 'mysqli' is only an alias accepted from
            // configuration, and naming it here produced owa_db_mysqli, which
            // does not exist, so an installation without pdo_mysql could not
            // load a driver at all.
            return $pdo_available ? 'pdo_mysql' : 'mysql';
        }

        // 'mysqli' names the legacy driver, whose class is owa_db_mysql.
        if ( $type === 'mysqli' ) {

            return 'mysql';
        }

        /*
         * 'pdo' is a CONFIGURATION alias for the PDO driver, whose token is
         * pdo_mysql. Normalised here rather than left to be turned into a
         * class owa_db_pdo that does not exist.
         */
        if ( $type === 'pdo' ) {

            return 'pdo_mysql';
        }

        return $type;
    }

    /**
     * Load the DB driver class for a given database type.
     *
     * THIRD-PARTY DB-DRIVER SEAM
     * --------------------------
     * OWA's bundled drivers (e.g. "mysql") live in the OWA\Core\Db namespace
     * and autoload via Composer. A third party can add support for another
     * database WITHOUT patching OWA core: a class `owa_db_<type>` extending
     * \OWA\Core\Db that an autoloader can load (a module's composer.json
     * `autoload`, merged by `composer install`), then `db_type` set to `<type>`.
     *
     * @param  string $type a db_type, as resolveDbDriver() takes it
     * @return bool false when no driver class is loadable
     */
    public static function setupStorageEngine($type) {

        $type = self::resolveDbDriver($type);



        if ( $type ) {
            $connection_class = "owa_db_" . $type;

            /*
             * A bundled driver's class follows from its token -- pdo_mysql ->
             * OWA\Core\Db\PdoMysql -- and Composer autoloads it, which runs the
             * file's define() of the OWA_DTD_* column-type constants.
             */
            $nsClass = self::namespacedClass( 'OWA\\Core\\Db', $type );

            // A third-party driver is a class owa_db_<type> that an
            // autoloader can load, such as one a module's composer.json
            // declares.
            if ( $nsClass === null && ! class_exists( $connection_class ) ) {

                \OWA\Core\CoreAPI::error( sprintf( 'No database driver for %s: no class %s is loadable.', $type, $connection_class ) );

                return false;
            }

        } else {
            
            \OWA\Core\CoreAPI::error("$type is not a supported database.");

            return false;
        }

        return true;

    }
    /**
     * @return \OWA\Core\Db
     */
    public static function dbSingleton() {

        if (!isset(self::$db)) {

            self::$db = \OWA\Core\CoreAPI::dbFactory();
        }

        return self::$db;
    }

    /**
     * A new connection object, connected on first use. dbSingleton() is the
     * one OWA shares; a caller that needs a connection of its own (cmd=backup
     * streams rows on one) asks here, with $persistent false so PDO does not
     * hand back the shared persistent connection.
     *
     * @param  bool|null $persistent null: the db_make_persistant_connections setting
     * @return \OWA\Core\Db|void nothing when the storage engine could not be set up
     */
    public static function dbFactory( $persistent = null ) {

        $db_type = \OWA\Core\CoreAPI::getSetting('base', 'db_type');
        $ret = \OWA\Core\CoreAPI::setupStorageEngine($db_type);

         if (!$ret) {
             \OWA\Core\CoreAPI::error(sprintf('Failed to initialize db type %s. Exiting.', $db_type));
             return;
        } else {
            /*
             * CONVENTION FIRST, then the plugin's name.
             *
             * A bundled driver's class follows from its token -- pdo_mysql ->
             * OWA\Core\Db\PdoMysql -- so it needs nothing registered. A
             * third-party driver keeps its own name, owa_db_<type>.
             *
             * The same resolution as setupStorageEngine(), which runs first and
             * is what loaded the class. The two must agree: if this one picked
             * a different class the constants would come from one driver and
             * the connection from another.
             */
            $driver = self::resolveDbDriver($db_type);
            $connection_class = 'owa_db_'.$driver;
            $connection_class = self::namespacedClass('OWA\\Core\\Db', $driver)
                ?? $connection_class;
            $db = new $connection_class(
                \OWA\Core\CoreAPI::getSetting('base','db_host'),
                \OWA\Core\CoreAPI::getSetting('base','db_port'),
                \OWA\Core\CoreAPI::getSetting('base','db_name'),
                \OWA\Core\CoreAPI::getSetting('base','db_user'),
                \OWA\Core\CoreAPI::getSetting('base','db_password'),
                true, // open_new_connection: no driver reads it
                $persistent ?? \OWA\Core\CoreAPI::getSetting('base','db_make_persistant_connections')
            );

            return $db;
        }
    }

    /**
     * @return \OWA\Module\Base\Classes\Settings
     */
    public static function configSingleton() {

        if( ! isset( self::$config ) ) {


            self::$config = new \OWA\Module\Base\Classes\Settings();
        }

        return self::$config;
    }

    /**
     * @return \OWA\Module\Base\Classes\Error
     */
    public static function errorSingleton() {

        if( ! self::$error ) {


            self::$error = new \OWA\Module\Base\Classes\Error();

        }

        return self::$error;
    }

    /**
     * A setting's value, optionally resolved from a scope upwards.
     *
     * With no scope it means exactly what it has always meant: the install-wide
     * value, or the code default. Every existing call site keeps that.
     *
     * With a scope it walks
     *
     *     profile -> property -> organization -> install -> code default
     *
     * and stops at the first level holding a ROW. A row is the answer even when
     * its value is false; only a MISSING row means "ask my parent". That
     * distinction is the whole reason the scoped values are rows rather than
     * another serialized blob -- a blob says "not set" by omitting a key, so it
     * cannot tell "inherit" from "override to off".
     *
     * $inherit = false reads one level and stops. The Property and Profile
     * settings screens need it to show whether a value is set HERE or coming
     * from above, and to offer clearing it back to inherited; without it every
     * field shows a value and none of them can be unset.
     *
     * The tail delegates to the install path rather than reimplementing it, so
     * install semantics -- including its deliberate default-equivalence pruning
     * -- cannot drift between the two.
     *
     * @param string $module
     * @param string $name
     * @param string $scopeType organization, property or profile
     * @param string $scopeId
     * @param bool   $inherit   walk upwards, or read this level only
     * @return mixed
     */
    public static function getSetting($module, $name, $scopeType = '', $scopeId = '', $inherit = true) {

        $s = \OWA\Core\CoreAPI::configSingleton();

        // A server without a database (a collector) holds no scoped settings,
        // so every scope answers the install's.
        if ( ! $scopeType || ! $scopeId || ! \OWA\Core\ServerRole::current()->hasDatabase() ) {

            return $s->get($module, $name);
        }

        /*
         * A Profile resolves in one query rather than three -- the walk to its
         * Property and Organization and the settings themselves come back
         * together. Every other scope takes the generic path; only the Profile
         * has a hierarchy to walk.
         */
        $resolved = $scopeType === 'profile'
            && ! isset( self::$setting_chain_cache[ 'profile:' . $scopeId ] )
                ? \OWA\Core\CoreAPI::resolveProfileChain( $scopeId )
                : null;

        if ( $resolved ) {

            $chain = $resolved['chain'];
            $rows  = $resolved['rows'];

        } else {

            $chain = \OWA\Core\CoreAPI::settingScopeChain( $scopeType, $scopeId );
            $rows  = \OWA\Core\CoreAPI::settingRowsForChain( $chain );
        }

        $key = $module . '|' . $name;

        if ( ! $inherit ) {

            /*
             * One level, and null when it holds nothing rather than falling
             * through: the caller is asking whether this scope OWNS a value,
             * not what the effective value is.
             */
            $own = $scopeType . '|' . $key;

            return array_key_exists( $own, $rows['own'] ) ? $rows['own'][ $own ] : null;
        }

        if ( array_key_exists( $key, $rows['effective'] ) ) {

            return $rows['effective'][ $key ];
        }

        $s = \OWA\Core\CoreAPI::configSingleton();
        return $s->get($module, $name);
    }

    /**
     * An entity's table name.
     *
     * One place rather than a factory call at each use. entityFactory() builds
     * a class name at runtime, so static analysis cannot know what comes back
     * and every caller is an unresolvable method call on an unknown class --
     * which is noise wherever it appears and would be repeated per query
     * otherwise.
     *
     * @param  string $entity e.g. base.setting
     * @return string
     */
    protected static function tableOf( $entity ) {

        return \OWA\Core\CoreAPI::entityFactory( $entity )->getTableName();
    }

    /**
     * Rows for a statement, as arrays.
     *
     * get_results() lives on the driver rather than on Db, so the same applies
     * here: one call site instead of one per query.
     *
     * @param  string   $sql
     * @param  Bindings $params
     * @return array<Row>
     */
    protected static function rowsFor( $sql, array $params = [] ) {

        return (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( $sql, $params );
    }

    /**
     * Every stored setting for a scope chain, in ONE query.
     *
     * Returns both answers the chain can be asked for:
     *
     *   effective  module|name        => the winning value
     *   own        scope|module|name  => the value that scope itself holds
     *
     * The winner is decided by the ORDER BY, and the first row seen for a
     * module|name is it. Written as a CASE rather than MySQL's FIELD() because
     * FIELD() does not exist in SQLite or Postgres -- see the dialect layer.
     *
     * One query for the whole chain, rather than one per key per level: a
     * report screen reads a dozen settings for the same Profile, and the
     * previous shape charged an entity load for each of them at each of up to
     * three levels.
     *
     * @param  list<Scope> $chain from settingScopeChain()
     * @return SettingRows
     */
    public static function settingRowsForChain( $chain ) {

        $cache_key = '';

        foreach ( $chain as $scope ) {

            $cache_key .= $scope['type'] . ':' . $scope['id'] . '|';
        }

        if ( isset( self::$setting_row_cache[ $cache_key ] ) ) {

            return self::$setting_row_cache[ $cache_key ];
        }

        $predicates = [];
        $ranks      = [];
        $params     = [];

        foreach ( $chain as $scope ) {

            $type = (string) $scope['type'];

            $predicates[] = '( scope_type = ? AND scope_id = ? )';
            $params[]     = $type;
            $params[]     = (string) $scope['id'];

            $ranks[ $type ] = isset( self::$setting_scope_rank[ $type ] )
                ? self::$setting_scope_rank[ $type ] : 0;
        }

        $when = '';

        foreach ( $ranks as $type => $rank ) {

            $when    .= sprintf( ' WHEN ? THEN %d', $rank );
            $params[] = (string) $type;
        }

        $sql = sprintf(
            'SELECT scope_type, module, name, value FROM %s WHERE %s'
          . ' ORDER BY CASE scope_type%s ELSE 0 END DESC',
            self::tableOf( 'base.setting' ),
            implode( ' OR ', $predicates ),
            $when );

        return self::$setting_row_cache[ $cache_key ] =
            self::indexSettingRows( $chain, self::rowsFor( $sql, $params ) );
    }

    /**
     * Turn rows into the two answers a chain can be asked for, and fill the
     * caches.
     *
     * Shared by the generic chain query and the joined Profile one, so the two
     * cannot disagree about what a row means or which caches it populates.
     * Rows must arrive ordered by scope rank, narrowest first: the first row
     * seen for a module|name is the effective one.
     *
     * @param  list<Scope> $chain from settingScopeChain()
     * @param  array<Row>  $rows  scope_type, module, name, value
     * @return SettingRows
     */
    protected static function indexSettingRows( $chain, $rows ) {

        $result = [ 'effective' => [], 'own' => [] ];

        /*
         * Seeded empty for every scope in the chain, before any row is seen. A
         * scope that turns out to hold nothing has still been ASKED, and
         * getScopedSettingRow() has to be able to tell that from "not looked at
         * yet" or it will go and ask again for each key.
         */
        foreach ( $chain as $scope ) {

            $scope_key = $scope['type'] . ':' . $scope['id'];

            if ( ! isset( self::$setting_scope_cache[ $scope_key ] ) ) {

                self::$setting_scope_cache[ $scope_key ] = [];
            }
        }

        foreach ( $rows as $row ) {

            if ( ! isset( $row['name'] ) ) {

                // A LEFT JOIN row for a Profile with no settings at all.
                continue;
            }


            $key = $row['module'] . '|' . $row['name'];

            /*
             * allowed_classes false for the same reason the install rows are
             * read that way: a row holds a scalar or an array of them, and a
             * tampered-with one must not be able to instantiate a class.
             */
            $value = unserialize( (string) $row['value'], [ 'allowed_classes' => false ] );

            $result['own'][ $row['scope_type'] . '|' . $key ] = $value;

            foreach ( $chain as $scope ) {

                if ( $scope['type'] === $row['scope_type'] ) {

                    self::$setting_scope_cache[ $scope['type'] . ':' . $scope['id'] ][ $key ] = $value;
                }
            }

            if ( ! array_key_exists( $key, $result['effective'] ) ) {

                $result['effective'][ $key ] = $value;
            }
        }

        return $result;
    }


    /**
     * A Profile's whole chain AND its settings, in ONE query.
     *
     * settingScopeChain() finds the parents by loading the site row and then
     * the property row -- two queries to fetch two foreign keys -- and
     * settingRowsForChain() then makes a third for the settings themselves.
     * Three queries on the first scoped read of every request that touches a
     * site, which is every tracking hit and every report.
     *
     * The join does the walk and the fetch together. LEFT JOINs throughout, so
     * a Profile with no Property, no Organization or no stored settings still
     * comes back with its chain -- an unparented Profile is a real thing, and
     * treating one as an error would be worse than the query being longer.
     *
     * Falls back to the generic path if the site is not found, because then
     * there is no chain to build and the caller should see the same answer it
     * saw before.
     *
     * @param  string $siteId
     * @return array{chain: list<Scope>, rows: SettingRows}|null null when the site is unknown
     */
    protected static function resolveProfileChain( $siteId ) {

        $rank   = 'CASE st.scope_type';
        $params = [ (string) $siteId ];

        foreach ( self::$setting_scope_rank as $type => $value ) {

            $rank    .= sprintf( ' WHEN ? THEN %d', $value );
            $params[] = (string) $type;
        }

        $rank .= ' ELSE 0 END';

        $sql = sprintf(
            'SELECT s.property_id, p.organization_id,'
          . ' st.scope_type, st.module, st.name, st.value'
          . ' FROM %s s'
          . ' LEFT JOIN %s p ON p.id = s.property_id'
          . ' LEFT JOIN %s st ON ('
          . "     ( st.scope_type = 'profile'      AND st.scope_id = s.site_id )"
          . "  OR ( st.scope_type = 'property'     AND st.scope_id = s.property_id )"
          . "  OR ( st.scope_type = 'organization' AND st.scope_id = p.organization_id ) )"
          . ' WHERE s.site_id = ?'
          . ' ORDER BY %s DESC',
            self::tableOf( 'base.site' ),
            self::tableOf( 'base.property' ),
            self::tableOf( 'base.setting' ),
            $rank );

        $rows = self::rowsFor( $sql, $params );

        if ( ! $rows ) {

            /*
             * No Profile row, so no Property and no Organization either -- the
             * foreign key lives on the Profile. The chain is therefore known
             * already, and memoizing it here stops the fallback re-asking for a
             * site that is not there.
             *
             * The fallback still runs, because settings stored against a
             * site_id whose Profile has since been deleted are unreachable from
             * a join that starts at the Profile, and the generic query reads
             * them directly. Returning an empty result here instead would
             * change what those installs see.
             */
            self::$setting_chain_cache[ 'profile:' . $siteId ] =
                [ [ 'type' => 'profile', 'id' => (string) $siteId ] ];

            return null;
        }

        $chain = [ [ 'type' => 'profile', 'id' => (string) $siteId ] ];

        $propertyId = (string) ( $rows[0]['property_id'] ?? '' );

        if ( $propertyId !== '' ) {

            $chain[] = [ 'type' => 'property', 'id' => $propertyId ];

            $organizationId = (string) ( $rows[0]['organization_id'] ?? '' );

            if ( $organizationId !== '' ) {

                $chain[] = [ 'type' => 'organization', 'id' => $organizationId ];
            }
        }

        $cache_key = '';

        foreach ( $chain as $scope ) {

            $cache_key .= $scope['type'] . ':' . $scope['id'] . '|';
        }

        self::$setting_chain_cache[ 'profile:' . $siteId ] = $chain;

        return [
            'chain' => $chain,
            'rows'  => self::$setting_row_cache[ $cache_key ]
                = self::indexSettingRows( $chain, $rows ),
        ];
    }

    /**
     * The scopes to consult, most specific first.
     *
     * A missing ancestor is SKIPPED, not an error. An unparented Profile is a
     * real thing -- one created before the hierarchy migration, or by a path
     * that assigns no Property -- so the walk has to tolerate gaps rather than
     * assume every Profile has a Property and every Property an Organization.
     *
     * @param  string $scopeType organization, property or profile
     * @param  string $scopeId
     * @return list<Scope>
     */
    public static function settingScopeChain( $scopeType, $scopeId ) {

        /*
         * Memoized because the tracking path reads several scoped settings
         * per event, and each read would otherwise re-walk the hierarchy --
         * loading a site row and a property row every time. A request cannot
         * see the hierarchy change under it, so caching for its lifetime is
         * safe. See settingCacheFlush() for the write side.
         */
        $key = $scopeType . ':' . $scopeId;

        if ( isset( self::$setting_chain_cache[ $key ] ) ) {

            return self::$setting_chain_cache[ $key ];
        }

        $chain = [ [ 'type' => $scopeType, 'id' => $scopeId ] ];

        if ( $scopeType === 'profile' ) {

            $site = \OWA\Module\Base\Entity\Site::forSiteId( $scopeId );

            $scopeId   = $site->get( 'property_id' );
            $scopeType = 'property';

            if ( ! $scopeId ) {

                self::$setting_chain_cache[ $key ] = $chain;
                return $chain;
            }

            $chain[] = [ 'type' => 'property', 'id' => $scopeId ];
        }

        if ( $scopeType === 'property' ) {

            $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
            $property->load( $scopeId );

            $organizationId = $property->get( 'organization_id' );

            if ( $organizationId ) {

                $chain[] = [ 'type' => 'organization', 'id' => $organizationId ];
            }
        }

        self::$setting_chain_cache[ $key ] = $chain;

        return $chain;
    }

    /**
     * One scope's stored value, or null when it holds none.
     *
     * null means NO ROW. A stored false comes back as false, which is the
     * distinction the blob could not make.
     *
     * Reads one scope, so it asks for a chain of one. The result is cached and
     * shared with the chain walk, which means a screen that has already
     * resolved a Profile's effective settings answers this from memory.
     *
     * @param  string $scopeType
     * @param  string $scopeId
     * @param  string $module
     * @param  string $name
     * @return mixed
     */
    public static function getScopedSettingRow( $scopeType, $scopeId, $module, $name ) {

        $scope_key = $scopeType . ':' . $scopeId;

        if ( ! isset( self::$setting_scope_cache[ $scope_key ] ) ) {

            \OWA\Core\CoreAPI::settingRowsForChain(
                [ [ 'type' => $scopeType, 'id' => $scopeId ] ] );
        }

        $key = $module . '|' . $name;

        /*
         * array_key_exists, not isset: a stored null and a missing row are
         * different answers here and isset() cannot tell them apart.
         */
        $own = isset( self::$setting_scope_cache[ $scope_key ] )
            ? self::$setting_scope_cache[ $scope_key ]
            : [];

        return array_key_exists( $key, $own ) ? $own[ $key ] : null;
    }

    /**
     * Drop the scoped-setting caches, and the tracking memos built from them
     * (TrackingEventHelpers::forget()).
     *
     * Called on every scoped write. Blunt on purpose: a write is rare and a
     * stale read is a wrong answer, so the whole map goes rather than trying
     * to reason about which inheriting scopes an edit could have changed.
     *
     * @return void
     */
    public static function settingCacheFlush() {

        self::$setting_row_cache   = [];
        self::$setting_scope_cache = [];
        self::$setting_chain_cache = [];

        \OWA\Module\Base\Classes\TrackingEventHelpers::forget();
    }

    /**
     * Store one setting at one scope.
     *
     * Deliberately does NOT apply the default-equivalence pruning that
     * persistSetting() applies install-wide. That rule keeps an install
     * tracking code defaults, and it is right there. At a scoped level it is
     * wrong and silently so: a Profile set to false, where false is also the
     * code default, would have its row dropped and would then INHERIT whatever
     * its Property says -- so the override would appear to save and not take.
     *
     * @param  string $scopeType
     * @param  string $scopeId
     * @param  string $module
     * @param  string $name
     * @param  mixed  $value
     * @return bool false when the setting may not be stored at that scope
     */
    public static function setScopedSetting( $scopeType, $scopeId, $module, $name, $value ) {

        /*
         * A setting is stored at the levels it declared, and nowhere else.
         *
         * Refused at the WRITE rather than filtered at the read, because a row
         * that exists at a scope nothing should inherit from is a value that
         * looks saved and never takes effect -- and because the read path has
         * no business knowing which levels are legitimate.
         *
         * A setting no module declares is refused at every scope, as
         * persistSetting() refuses it install-wide.
         */
        if ( ! \OWA\Core\CoreAPI::configSingleton()->mayWriteAtScope( $module, $name, $scopeType ) ) {

            $scopes = \OWA\Core\CoreAPI::configSingleton()->scopesFor( $module, $name );

            \OWA\Core\CoreAPI::notice( sprintf(
                'Refusing to store %s.%s at %s scope: %s.',
                $module, $name, $scopeType,
                $scopes === null ? 'no module declares it' : 'it is declared for ' . implode( ', ', $scopes ) ) );

            return false;
        }

        $problem = \OWA\Core\CoreAPI::configSingleton()->valueProblem( $module, $name, $value );

        if ( $problem !== null ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Refusing to store %s.%s at %s scope: %s', $module, $name, $scopeType, $problem ) );

            return false;
        }

        $value = \OWA\Core\CoreAPI::configSingleton()->normalizedValue( $module, $name, $value );

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );
        $id      = $setting->makeId( $scopeType, $scopeId, $module, $name );

        $setting->load( $id );

        $setting->set( 'scope_type', $scopeType );
        $setting->set( 'scope_id', $scopeId );
        $setting->set( 'module', $module );
        $setting->set( 'name', $name );
        $setting->set( 'value', serialize( $value ) );

        if ( $setting->wasPersisted() ) {

            \OWA\Core\CoreAPI::settingCacheFlush();

            return $setting->update();
        }

        $setting->set( 'id', $id );
        $setting->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

        /*
         * 1, as an install row is written and as the column defaults. Left
         * unset, the entity writes 0, and Update043's rollback and re-apply,
         * which drops the column and adds it back, turns the row into 1.
         */
        $setting->set( 'autoload', 1 );

        \OWA\Core\CoreAPI::settingCacheFlush();

        return $setting->create();
    }

    /**
     * Clear a scope's own value, so it inherits again.
     *
     * The counterpart to setScopedSetting: without a way to REMOVE a row there
     * is no way back to inheriting once a value has been set here, and the
     * screens could only ever add overrides.
     *
     * @param  string $scopeType
     * @param  string $scopeId
     * @param  string $module
     * @param  string $name
     * @return bool
     */
    public static function clearScopedSetting( $scopeType, $scopeId, $module, $name ) {

        $setting = \OWA\Core\CoreAPI::entityFactory( 'base.setting' );

        \OWA\Core\CoreAPI::settingCacheFlush();

        return $setting->delete( $setting->makeId( $scopeType, $scopeId, $module, $name ) );
    }

    /**
     * @param  string $module
     * @param  string $name
     * @param  mixed  $value
     * @param  bool   $persist store it, not only set it for this request
     * @return void
     */
    public static function setSetting($module, $name, $value, $persist = false) {

        $s = \OWA\Core\CoreAPI::configSingleton();

        if ($persist === true) {
            $s->persistSetting($module, $name, $value);
        } else {
            $s->setSetting($module, $name, $value);
        }

    }

    /**
     * @param  string $module
     * @param  string $name
     * @param  mixed  $value
     * @return bool true when the value is now the setting's; false when it is
     *              refused (Settings::persistSetting())
     */
    public static function persistSetting($module, $name, $value) {

        return (bool) \OWA\Core\CoreAPI::configSingleton()->persistSetting($module, $name, $value);
    }

    /**
     * @param  string $site_id
     * @param  string $name a base setting
     * @return mixed nothing for no site id or a site that does not exist
     */
    public static function getSiteSetting($site_id, $name) {

        // No site id means there is no row to look up. load() would reach
        // getByColumn(), which throws on an empty value, so the absence of a
        // parameter would surface as an uncaught exception rather than as the
        // "no setting" answer every caller already handles.
        if ( ! $site_id ) {

            return;
        }

        $site = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );

        /*
         * A site id that names no site still answers "no setting", not the
         * install-wide value. Without this guard the scope walk would find no
         * row anywhere and fall through to the install default, so an event
         * naming an unknown site would silently pick up install settings.
         */
        if ( ! $site->wasPersisted() ) {

            return;
        }

        /*
         * Reads the scoped store, NOT owa_site.settings: the screens write there.
         *
         * It inherits: a Profile with no value of its own takes its Property's, then
         * its Organization's, then the install's.
         */
        return \OWA\Core\CoreAPI::getSetting( 'base', $name, 'profile', $site_id );
    }

    /**
     * Is this site id one this installation actually knows about?
     *
     * Tracking takes a site id from the request. An event naming a site that
     * does not exist would be recorded and never read, since reporting is
     * entered through a site -- and such ids occur: the documentation
     * placeholder pasted into live tracking code, a select-box label, a site id
     * truncated.
     *
     * MEMOISED, because a queue worker processes many events per process and the
     * answer cannot change within one. A single request pays one lookup at most.
     *
     * @param string $site_id
     * @return bool
     */
    public static function isSiteRegistered( $site_id ) {

        return self::siteRegistration( $site_id ) === true;
    }

    /**
     * Whether a site id is registered: true, false, or null when the database
     * could not answer.
     *
     * A lookup that fails would read as no row, and an outage would make every
     * site look unregistered. Null is not
     * memoised: the next beacon asks again.
     *
     * @param string $site_id
     * @return bool|null
     */
    public static function siteRegistration( $site_id ) {

        $site_id = (string) $site_id;

        // An id no site could have is not looked up, or remembered.
        if ( ! self::isSiteIdShaped( $site_id ) ) {

            return false;
        }

        // A server without a database (a collector) has none to ask, and the
        // ingest that drains its queue checks the site.
        if ( ! \OWA\Core\ServerRole::current()->hasDatabase() ) {

            return null;
        }

        if ( ! array_key_exists( $site_id, self::$registered_sites ) ) {

            $site = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );

            if ( ! $site->wasPersisted() && ! self::databaseAnswers() ) {

                return null;
            }

            /*
             * An ARCHIVED Profile is not registered.
             *
             * Archiving is how a Profile is removed, and removing it has to
             * mean it stops observing -- the tag is still on the page and will
             * keep firing, so anything short of rejecting here would leave a
             * site quietly collecting after someone had deleted it. Same gate
             * as an unknown site, for the same reason: nothing downstream
             * should have to know.
             */
            self::$registered_sites[ $site_id ] =
                $site->wasPersisted() && ! $site->isArchived();
        }

        return self::$registered_sites[ $site_id ];
    }

    /** The longest site id: the width of the site_id column. */
    const SITE_ID_MAX_LENGTH = 255;

    /**
     * Whether a string could be a site id: 1 to SITE_ID_MAX_LENGTH bytes, with
     * no whitespace or control characters.
     *
     * @param string $site_id
     * @return bool
     */
    public static function isSiteIdShaped( $site_id ) {

        return is_string( $site_id )
            && strlen( $site_id ) <= self::SITE_ID_MAX_LENGTH
            && preg_match( '/^[^\x00-\x20\x7F]+$/', $site_id ) === 1;
    }

    /**
     * A site id as written to a log line: at most 64 characters, with anything
     * other than printable ASCII shown as "?".
     *
     * @param mixed $site_id
     * @return string
     */
    public static function siteIdForLog( $site_id ) {

        $id  = is_scalar( $site_id ) ? (string) $site_id : gettype( $site_id );
        $out = preg_replace( '/[^\x21-\x7E]/', '?', substr( $id, 0, 64 ) );

        return strlen( $id ) > 64 ? $out . '...' : $out;
    }

    /**
     * Whether the database is reachable and runs a query.
     *
     * @return bool
     */
    private static function databaseAnswers() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        return $db && $db->isConnectionEstablished() && $db->query( 'SELECT 1' ) !== false;
    }

    /**
     * Test seam: forget which site ids have been checked.
     *
     * @return void
     */
    public static function forgetRegisteredSites() {

        self::$registered_sites = [];
    }

    /**
     * Drop every per-process singleton and memo, so the next call builds it
     * again: the singletons here, the setting caches, and the memos of the
     * classes below.
     *
     * For tests, and for a long-running process that has to see changed
     * config files or stored state. Nothing on a request path calls it: within
     * one, each accessor answers the same instance.
     *
     * All at once, because they depend on each other: the service's modules
     * attach their handlers to the event dispatcher, and TrackingEventHelpers
     * records which filters it has attached there. Dropping one of the three
     * without the others would attach handlers twice, or not at all.
     *
     * What it leaves is unbooted: a fresh configSingleton() holds the config
     * file and the defaults, and a fresh serviceSingleton() has no modules
     * loaded. The caller boots again (new \owa()) before handling anything.
     *
     * @return void
     */
    public static function forgetSingletons() {

        self::$db             = null;
        self::$config         = null;
        self::$error          = null;
        self::$service        = null;
        self::$cache          = null;
        self::$request        = null;
        self::$request_logged = false;
        self::$event_queues   = [];
        self::$salts          = null;
        self::$warned_modules = [];
        \OWA\Core\Extensions::forget();

        self::settingCacheFlush();
        self::forgetRegisteredSites();

        \OWA\Core\Auth::forgetInstance();
        \OWA\Core\Module::forgetSettingsRegistry();
        \OWA\Core\Template::forgetAssetsBuilt();
        \OWA\Module\Base\Classes\EventDispatch::forgetInstance();
        \OWA\Module\Base\Classes\TrackingEventHelpers::forgetAll();
        \OWA\Module\Base\Classes\GoalVocabulary::forgetDescriptions();
        \OWA\Module\Base\Classes\TrackerDefaults::forget();
        \OWA\Module\Base\Classes\Beacon\Compat::forget();
        \OWA\Module\Base\Classes\Cube\Dimensions::forgetCapacity();
        \OWA\Module\Base\Classes\Cube\Cubes::forgetExistence();
        \OWA\Module\Base\Entity\GoalEventDefinition::forgetProperties();
    }

    /**
     * Test seam: answer for a site id without asking the database.
     *
     * @param  string $site_id
     * @param  bool   $registered
     * @return void
     */
    public static function rememberSiteRegistration( $site_id, $registered ) {

        self::$registered_sites[ (string) $site_id ] = (bool) $registered;
    }

    /**
     * @return list<int|string>
     */
    public static function getAllRoles() {

        $caps = \OWA\Core\CoreAPI::getSetting('base', 'capabilities');
        return array_keys($caps);
    }

    /**
     * The roles a user account may have: every role but 'everyone', which is
     * the role of a visitor who is not signed in (ServiceUser).
     *
     * @return list<int|string>
     */
    public static function getAssignableRoles() {

        return array_values( array_diff( \OWA\Core\CoreAPI::getAllRoles(), [ 'everyone' ] ) );
    }

    /**
     * The assignable role with the fewest capabilities, the last of them
     * when several tie, as the capabilities setting lists admin first: what
     * the Add user form starts at.
     *
     * @return string '' when no role can be assigned
     */
    public static function leastPrivilegedRole() {

        $least = '';
        $count = PHP_INT_MAX;

        foreach ( self::getAssignableRoles() as $role ) {

            $n = count( (array) self::getCapabilities( $role ) );

            if ( $n <= $count ) {

                $least = (string) $role;
                $count = $n;
            }
        }

        return $least;
    }

    /**
     * Capabilities OWA no longer has. A role configuration that still names
     * one is not an error: getCapabilities() leaves the name out.
     * view_reports_ecommerce: view_reports covers the e-commerce reports.
     */
    const RETIRED_CAPABILITIES = [ 'view_reports_ecommerce' ];

    /**
     * @param  mixed $role
     * @return mixed the role's entry in the capabilities setting, less any
     *               RETIRED_CAPABILITIES; array() for a role it does not name
     */
    public static function getCapabilities($role) {
        $caps = \OWA\Core\CoreAPI::getSetting('base', 'capabilities');
        // A null role (no user) names no entry; as a key, null is deprecated.
        if ($role !== null && array_key_exists($role, $caps)) {
            return is_array( $caps[ $role ] )
                ? array_values( array_diff( $caps[ $role ], self::RETIRED_CAPABILITIES ) )
                : $caps[ $role ];
        } else {
            return [];
        }
    }

    /**
     * @return \OWA\Module\Base\Classes\ServiceUser
     */
    public static function getCurrentUser() {
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        return $s->getCurrentUser();
    }

    /**
     * Whether the current user's role holds a capability (ServiceUser::isCapable()).
     *
     * @param  string $capability
     * @return boolean
     */
    public static function isCurrentUserCapable($capability) {

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        \OWA\Core\CoreAPI::debug("Current User Role: ".$cu->getRole());
        \OWA\Core\CoreAPI::debug("Current User Authentication: ".$cu->isAuthenticated());
        $ret = $cu->isCapable($capability);
        \OWA\Core\CoreAPI::debug("Is current User capable: ".$ret);
        return $ret;
    }

    /**
     * @return bool
     */
    public static function isCurrentUserAuthenticated() {

        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        return $cu->isAuthenticated();
    }
    
    /**
     * @return \OWA\Module\Base\Classes\Service
     */
    public static function serviceSingleton() {

        if(empty(self::$service)) {


            self::$service = new \OWA\Module\Base\Classes\Service();

        }

        return self::$service;
    }

    /**
     * @return \OWA\Module\Base\Classes\Cache
     */
    public static function cacheSingleton() {

        if ( empty ( self::$cache ) ) {

            self::$cache = new \OWA\Module\Base\Classes\Cache();
        }

        return self::$cache;
    }
    

    /**
     * @return \OWA\Core\RequestContainer
     */
    public static function requestContainerSingleton() {

        if(!isset(self::$request)):


            self::$request = new \OWA\Core\RequestContainer();

        endif;

        return self::$request;

    }

    /**
     * @param  string      $modulefile   <module>.<name>
     * @param  string|null $class_suffix the sub-namespace: Controller, View
     * @param  mixed       $params       the constructor's argument
     * @return object
     */
    public static function moduleFactory($modulefile, $class_suffix = null, $params = '') {

        $class = self::moduleClass( $modulefile, $class_suffix );

        if ( $class === null ) {

            throw self::noModuleClass( $modulefile, $class_suffix );
        }

        $obj = new $class( $params );
        $obj->module = explode( '.', (string) $modulefile )[0];

        return $obj;
    }

    /**
     * The class a <module>.<name> resolves to with this suffix -- the module's
     * own OWA\Module\<Module>\<Suffix>\<Name>, or for Base one of Core's -- or
     * null when there is none.
     *
     * @param  string      $modulefile   <module>.<name>
     * @param  string|null $class_suffix the sub-namespace: Controller, View
     * @return string|null
     * @throws \OWA\Core\Exception\InvalidAction when either half is not a bare identifier
     */
    public static function moduleClass( $modulefile, $class_suffix ) {
        /*
         * Both halves name a class -- OWA\Module\<Module>\<Suffix>\<Action> --
         * and class_exists() hands that name to the autoloader, which maps it
         * onto a file path, so the name is validated here rather than trusted.
         *
         * RequestContainer sanitizes params through Sanitize::cleanInput(), but
         * that is HTML/encoding oriented and does not constrain a path.
         *
         * Require a bare identifier for each half. A module or action name has
         * never legitimately contained a separator, and a positive match is used
         * rather than blacklist replacement.
         */
        $segments = explode( '.', (string) $modulefile );

        if ( count( $segments ) !== 2 ) {

            \OWA\Core\CoreAPI::notice( 'Refusing to resolve action: expected <module>.<action>.' );

            throw new \OWA\Core\Exception\InvalidAction( 'Invalid action.' );
        }

        list( $module, $file ) = $segments;

        foreach ( [ 'module' => $module, 'action' => $file ] as $part => $value ) {

            // D: without it $ also matches before a trailing newline.
            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $value ) ) {

                \OWA\Core\CoreAPI::notice(
                    sprintf( 'Refusing to resolve action: %s segment is not a bare identifier.', $part )
                );

                throw new \OWA\Core\Exception\InvalidAction( 'Invalid action.' );
            }
        }

        if ( ! $class_suffix ) {

            return null;
        }

        $psr4 = '\\' . \OWA\Core\Env::moduleNamespace( $module )
              . '\\' . $class_suffix . '\\' . ucfirst( $file );

        if ( class_exists( $psr4 ) ) {

            return $psr4;
        }

        /*
         * Base's views that live in Core -- restApi, adminPage, mail, cli are
         * OWA\Core\View\RestApi and the rest. Base only: another module naming
         * one of these means its own.
         */
        $core = '\\OWA\\Core\\' . $class_suffix . '\\' . ucfirst( $file );

        if ( $module === 'base' && class_exists( $core ) ) {

            return $core;
        }

        return null;
    }

    /**
     * @param  string      $modulefile
     * @param  string|null $class_suffix
     * @return \Exception
     */
    private static function noModuleClass( $modulefile, $class_suffix ) {

        list( $module, $file ) = explode( '.', (string) $modulefile );

        return new \Exception( sprintf(
            'No %s class for %s.%s. A class must be PSR-4 autoloadable; the pre-PSR-4 '
          . 'owa_* file convention was removed in v2.0 (UPGRADING.md).', $class_suffix, $module, $file ) );
    }

    /**
     * The view named $name: its module's View class, or -- when the module
     * ships none and the controller declared a template for the name
     * (Controller::setView(), setSubview()) -- a plain View that renders it.
     *
     * @param string $name  <module>.<view>
     * @param array<mixed> $data  the controller's data
     * @return \OWA\Core\View
     */
    public static function viewFactory( $name, $data = [] ) {

        $class = self::moduleClass( $name, 'View' );

        if ( $class === null ) {

            if ( empty( $data['templates'][ $name ] ) ) {

                throw self::noModuleClass( $name, 'View' );
            }

            $class = \OWA\Core\View::class;
        }

        $view = new $class();
        $view->module = explode( '.', (string) $name )[0];
        $view->name   = (string) $name;

        return $view;
    }

    /**
     * @param  string      $module
     * @param  string      $sub_directory
     * @param  string      $file
     * @param  string|null $class_suffix
     * @param  mixed       $params the constructor's argument
     * @return object
     */
    public static function moduleGenericFactory($module, $sub_directory, $file, $class_suffix = null, $params = '') {

        /*
         * By convention first. Handlers and filters are registered with the
         * lowercase directory names -- 'handlers', 'classes' -- so those are
         * read as their PSR-4 directories.
         */
        $dirs = [ 'handlers' => 'Handler', 'classes' => 'Classes', 'filters' => 'Filter' ];
        $dir  = $dirs[ strtolower( (string) $sub_directory ) ] ?? $sub_directory;

        $nsClass = \OWA\Core\Lib::conventionalClass(
            \OWA\Core\Env::moduleDir( $module ) . $dir,
            '', $file, (string) $class_suffix );

        if ( $nsClass !== null ) {

            return new $nsClass( $params );
        }

        throw new \Exception( sprintf(
            'No class %s in module %s (%s). A class must be PSR-4 autoloadable; the pre-PSR-4 '
          . 'owa_* file convention was removed in v2.0 (UPGRADING.md).', $file, $module, $dir ) );
    }

    /**
     * Produces Module Classes (module.php)
     *
     * @return Object module class object
     * @param  string $module
     */
    public static function moduleClassFactory($module) {

        // Callers pass either the lowercase runtime name ('maxmind_geoip', from
        // getActiveModules) or the on-disk dir name ('MaxmindGeoip', from a
        // directory scan). moduleDirName() is idempotent on its own output, so
        // it normalises both to the PascalCase PSR-4 segment, from which the
        // module class FQCN (OWA\Module\<Seg>\Module) is directly derivable.
        $class = \OWA\Core\Env::moduleNamespace( $module ) . '\\Module';

        if ( class_exists( $class ) ) {

            // A module is known by its NAME, read before it is built.
            if ( ! \OWA\Core\Module::declaresName( $class ) ) {

                throw new \Exception( \OWA\Core\Module::undeclaredNameProblem( $class ) );
            }

            return new $class( [] );
        }

        throw new \Exception( sprintf(
            'Module %s has no %s class. A module is a PascalCase directory with a PSR-4 '
          . 'Module class; the pre-PSR-4 layout (a lowercase directory and module.php) was '
          . 'removed in v2.0 (UPGRADING.md).', $module, $class ) );
    }


    /**
     * @param  string     $module
     * @param  string|int $filename an update's class basename (Update017) or its sequence (17)
     * @return \OWA\Core\Update
     */
    public static function updateFactory($module, $filename) {

        // NAMESPACE-FIRST. Update classes are PSR-4: UpdateNNN.php declares
        // OWA\Module\<Module>\Update\UpdateNNN, which Composer autoloads with no
        // require. The legacy name built below ('owa_base_Update011_update')
        // has not existed since the PSR-4 relocation, so resolving it first is
        // what makes updates loadable at all.
        // Callers name the update two different ways. Module::update()
        // passes the PSR-4 class basename it just read off disk ('Update017'),
        // while the updatesApply CLI's apply=/rollback= arguments are written
        // by a human as 'base.17' and arrive here as a bare sequence. Only the
        // first resolved, so targeted apply and rollback both fell through to
        // the legacy branch and died looking for owa_base_17_update.php -- a
        // filename that has not existed since the PSR-4 relocation.
        $basename = preg_match( '/^\d+$/', (string) $filename )
            ? sprintf( 'Update%03d', (int) $filename )
            : $filename;

        $namespaced = '\\' . \OWA\Core\Env::moduleNamespace( $module )
                    . '\\Update\\' . $basename;

        if ( ! class_exists( $namespaced ) ) {

            throw new \Exception( sprintf(
                'No update %s in module %s. An update is a PSR-4 class; the pre-PSR-4 '
              . 'updates/<seq>.php convention was removed in v2.0 (UPGRADING.md).', $basename, $module ) );
        }

        $obj = new $namespaced();

        $obj->module_name = $module;
        if (!$obj->schema_version) {
            // Derive the sequence from the filename for updates that do not declare
            // one. OWA's own all do; a module's may not. Take the digits, so
            // 'Update004.php' and '4.php' both give 4.
            $obj->schema_version = (int) preg_replace( '/\D/', '', (string) $filename );
        }
        return $obj;
    }

    /**
     * @param  string       $subview <module>.<view>
     * @param  array<mixed> $data    the controller's data
     * @return \OWA\Core\View
     */
    public static function subViewFactory( $subview, $data = [] ) {

        $subview = self::viewFactory( $subview, $data );
        $subview->is_subview = true;

        return $subview;
    }

    /**
     * @param  string       $module
     * @param  string       $class
     * @param  array<mixed> $params the constructor's argument
     * @param  string       $class_ns
     * @return object
     */
    public static function supportClassFactory($module, $class, $params = [],$class_ns = 'owa_') {

        // Base's support classes that live in Core -- 'template' is
        // OWA\Core\Template. Base only: another module naming one means its own.
        $core = 'OWA\\Core\\' . ucfirst( (string) $class );

        if ( $module === 'base' && preg_match( '/^[A-Za-z0-9_]+$/', (string) $class )
             && ! class_exists( 'OWA\\Module\\Base\\Classes\\' . ucfirst( (string) $class ) )
             && class_exists( $core ) ) {

            return new $core( $params );
        }

        $obj = \OWA\Core\Lib::factory(\OWA\Core\Env::moduleDir($module) . 'Classes/', $class_ns, $class, $params);

        return $obj;
    }

    /**
     * Convienence method for generating entities
     *
     * @param mixed $entity_name
     * @return \OWA\Core\Entity
     */
    public static function entityFactory($entity_name) {

        /* SETUP STORAGE ENGINE */

        // Must be called before any entities are created

        // The driver defines the OWA_DTD_* column types. OWA_DB_TYPE
        // defaults to mysql, as db_type does: a server without database
        // settings (a collector) still builds entities.
        if (!defined('OWA_DTD_INT')) {
            \OWA\Core\CoreAPI::setupStorageEngine(defined('OWA_DB_TYPE') && OWA_DB_TYPE ? OWA_DB_TYPE : 'mysql');
        }




        /*
         * BY CONVENTION, so a new entity needs nothing registered. The
         * convention is the same one moduleDirName() uses for module
         * directories: snake_case to PascalCase, under the module's own Entity
         * namespace. base.custom_report -> OWA\Module\Base\Entity\CustomReport.
         */
        $nsClass = self::namespacedEntityClass($entity_name);

        if ($nsClass !== null) {

            $entity = new $nsClass();
            $entity->name = $entity_name;

            return $entity;
        }

        throw new \Exception( sprintf(
            'No entity class for %s. An entity is OWA\\Module\\<Module>\\Entity\\<Name>; the '
          . 'pre-PSR-4 owa_* file convention was removed in v2.0 (UPGRADING.md).', $entity_name ) );

    }

    /**
     * The PSR-4 class a `module.entity_name` refers to, or null.
     *
     * Null when the class does not exist, so the caller falls back rather than
     * fataling on a name that only the legacy path can resolve.
     *
     * @param  string $entity_name e.g. 'base.custom_report'
     * @return string|null         e.g. 'OWA\Module\Base\Entity\CustomReport'
     */
    /*
     * Public: a name-to-class lookup with no side effects, for a caller that
     * needs the class without building the entity. In core, entityFactory()
     * is the only caller.
     */
    public static function namespacedEntityClass($entity_name) {

        if (strpos((string) $entity_name, '.') === false) {

            return null;
        }

        list($module, $name) = explode('.', $entity_name, 2);

        if ($module === '' || $name === '') {

            return null;
        }

        return self::namespacedModuleClass($module, 'Entity', $name);
    }

    /**
     * The PSR-4 class a module's `<kind>` of `<name>` refers to, or null.
     *
     * base + Controller + customReports -> OWA\Module\Base\Controller\CustomReports
     * base + Entity     + custom_report -> OWA\Module\Base\Entity\CustomReport
     *
     * @param  string $module registered module name, e.g. 'base'
     * @param  string $kind   the sub-namespace: Entity, Controller, View
     * @param  string $name   registered name, snake_case or camelCase
     * @return string|null
     */
    private static function namespacedModuleClass($module, $kind, $name) {

        if ((string) $module === '' || (string) $name === '') {

            return null;
        }

        return self::namespacedClass(
            \OWA\Core\Env::moduleNamespace($module) . '\\' . $kind,
            $name );
    }

    /**
     * The class $name refers to inside $namespace, or null when there is none.
     *
     * The transform is the one Lib::moduleDirName() applies to module
     * directories: snake_case to PascalCase, and a name that is already camel
     * only gains its capital. It is the convention the tree is laid out by, so
     * a class that follows it needs nothing registered anywhere.
     *
     * Null rather than the name when the class does not exist, so every caller
     * falls back to its legacy path instead of fataling on a name only that
     * path can resolve.
     *
     * @param  string $namespace e.g. 'OWA\Core\Db'
     * @param  string $name      e.g. 'pdo_mysql'
     * @return string|null
     */
    private static function namespacedClass($namespace, $name) {

        $pascal = str_replace('_', '', ucwords((string) $name, '_'));

        if ($pascal === '') {

            return null;
        }

        $class = $namespace . '\\' . $pascal;

        return class_exists($class) ? $class : null;
    }

    /**
     * Convienence method for generating metrics
     *
     * A name without a dot is a registered metric (pageViews): its class and
     * declared params, with $params over them. Registered more than once, the
     * last registration is the one built, as ResultSetManager::loadMetric()
     * keeps the last.
     *
     * @param string $metric_name a class id (base.configurableMetric) or a registered metric name
     * @param array<mixed> $params
     * @return mixed
     */
    public static function metricFactory($metric_name, $params = []) {

        if ( strpos( $metric_name, '.' ) === false ) {

            $registered = \OWA\Core\CoreAPI::serviceSingleton()->getMetricClasses( $metric_name ) ?: [];
            $definition = end( $registered );

            if ( ! is_array( $definition ) || empty( $definition['class'] ) ) {

                throw new \Exception( sprintf( 'No metric is registered as %s.', $metric_name ) );
            }

            $metric_name = $definition['class'];
            $params      = array_merge( $definition['params'] ?? [], $params );
        }


        // By convention first, as entities are: base.configurableMetric is
        // OWA\Module\Base\Metric\ConfigurableMetric.
        $parts = explode( '.', (string) $metric_name );

        if ( count( $parts ) === 2 && preg_match( '/^[A-Za-z0-9_]+$/', $parts[1] ) ) {

            $nsClass = \OWA\Core\Env::moduleNamespace( $parts[0] )
                     . '\\Metric\\' . ucfirst( $parts[1] );

            if ( class_exists( $nsClass ) ) {

                return new $nsClass( $params );
            }
        }

        throw new \Exception( sprintf(
            'No metric class for %s. A metric is OWA\\Module\\<Module>\\Metric\\<Name>; the '
          . 'pre-PSR-4 owa_* file convention was removed in v2.0 (UPGRADING.md).', $metric_name ) );
    }

    /**
     * The registered title for a settings screen, or '' if it has none.
     *
     * One lookup for both consumers: the nav renders it as the link, and the
     * page renders it as its heading. See Module::registerSettingsPage().
     *
     * @param string $action  a dotted action id, e.g. 'base.optionsGeneral'
     * @return string
     */
    public static function settingsPageTitle( $action ) {

        if ( ! $action ) {

            return '';
        }

        foreach ( (array) \OWA\Core\CoreAPI::getAdminPanels() as $items ) {

            foreach ( (array) $items as $item ) {

                if ( ( $item['do'] ?? '' ) === $action ) {

                    return (string) ( $item['anchortext'] ?? '' );
                }
            }
        }

        return '';
    }

    /**
     * Returns a consolidated list of admin/options panels from all active modules
     *
     * @return array<mixed> group => its panels, through the nav_settings filter
     */
    public static function getAdminPanels() {

        $panels = [];

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ($service->modules as $k => $v) {
            $v->registerAdminPanels();
            $module_panels = $v->getAdminPanels();
            if ($module_panels) {
                foreach ($module_panels as $key => $value) {

                    $panels[$value['group']][] = $value;
                }
            }
        }

        // nav_settings: every module's panels, grouped, before anything renders them.
        return (array) \OWA\Core\CoreAPI::filter( 'nav_settings', $panels );
    }

    /**
     * The report registry, built on first use.
     *
     * Lazy on purpose, as the report nav is (getGroupNavigation()): Module::__construct()
     * runs on every request including every tracker beacon, so registration
     * must not live there. The guard makes it idempotent -- a request that
     * renders two reports registers once.
     *
     * @return array<mixed> definitions keyed by report id
     */
    public static function getReportRegistry() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ( $service->modules as $module ) {

            if ( empty( $module->reports_registered ) ) {

                $module->registerReports();
                $module->reports_registered = true;
            }
        }

        $map = $service->getMap( 'reports' );

        return is_array( $map ) ? $map : [];
    }

    /**
     * One report's definition, or false.
     *
     * @param  string $id
     * @return mixed|false
     */
    public static function getReportDefinition( $id ) {

        if ( ! $id ) {
            return false;
        }

        $registry = \OWA\Core\CoreAPI::getReportRegistry();

        return $registry[ $id ] ?? false;
    }

    /**
     * @param  string $group_name
     * @param  string $sortby the key to sort entries by
     * @return mixed the group's entries, sorted, through the nav_reports filter; null when no module registered the group
     */
    public static function getGroupNavigation($group_name, $sortby ='order') {

        $links = [];

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ($service->modules as $k => $v) {

            // If the module does not have nav links, register them. needed in case this function is called twice on
            // same view.
            if ( empty( $v->nav_links ) ) {

                $v->registerNavigation();
            }

            $module_nav = $v->getNavigationLinks();

            if ( $module_nav ) {

                //loop through returned nav array
                foreach ( $module_nav as $group => $nav_links ) {

                    foreach ( $nav_links as $subgroup => $link ) {

                        // check to see if group exists
                        if ( array_key_exists( $group, $links ) ) {

                            // check to see if subgroup is already present in the main array
                            if ( array_key_exists( $subgroup, $links[ $group ] ) ) {

                                $existing = $links[ $group ][ $subgroup ];

                                /*
                                 * A module that only adds a link to another
                                 * module's subgroup contributes 'subgroup' and
                                 * nothing else. Whichever module is loaded
                                 * first, the entry keeps the defining module's
                                 * label, link and order, and gains the links.
                                 */
                                $merged = array_merge(
                                    (array) ( $existing['subgroup'] ?? [] ),
                                    (array) ( $link['subgroup'] ?? [] ) );

                                $links[ $group ][ $subgroup ] = $existing + (array) $link;

                                if ( $merged ) {

                                    $links[ $group ][ $subgroup ]['subgroup'] = $merged;
                                }
                            } else {
                                // else populate the link
                                $links[$group][$subgroup] = $link;
                            }

                        } else {
                            $links[$group][$subgroup] = $link;
                        }
                    }

                }
            }
        }

        /*
         * nav_reports: the merged nav for this group, after every module has
         * contributed, so a filter can also remove or reorder another module's
         * entries. Sorted first, so a filter that reorders has the last word.
         */
        return \OWA\Core\CoreAPI::filter( 'nav_reports',
            isset( $links[ $group_name ] ) ? self::sortNavigation( $links[ $group_name ] ) : null, $group_name );
    }

    /**
     * A nav group's entries by their `order`, and each entry's links by theirs.
     *
     * STABLE: equal orders keep registration order. An entry registered with
     * no order follows the ordered ones, also in registration order, so a
     * module that never said where its entry goes does not jump to the top.
     *
     * @param  array<mixed> $entries name => link struct, or a list of link structs
     * @return array<mixed>
     */
    public static function sortNavigation( array $entries ) {

        $keyed = [];
        $i     = 0;

        foreach ( $entries as $key => $entry ) {

            if ( is_array( $entry ) && isset( $entry['subgroup'] ) && is_array( $entry['subgroup'] ) ) {

                $entry['subgroup'] = self::sortNavigation( $entry['subgroup'] );
            }

            $order = is_array( $entry ) && isset( $entry['order'] ) && is_numeric( $entry['order'] )
                ? (float) $entry['order'] : INF;

            $keyed[] = [ $order, $i++, $key, $entry ];
        }

        usort( $keyed, function ( $a, $b ) {

            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        } );

        $sorted = [];
        $list   = array_keys( $entries ) === range( 0, count( $entries ) - 1 );

        foreach ( $keyed as $row ) {

            if ( $list ) {

                $sorted[] = $row[3];

            } else {

                $sorted[ $row[2] ] = $row[3];
            }
        }

        return $sorted;
    }

    /**
     * @return list<int|string>
     */
    public static function getActiveModules() {

        $c = \OWA\Core\CoreAPI::configSingleton();

        $config = $c->config->get('settings');


        $active_modules = [];

        foreach ($config as $k => $module) {

            if ( isset($module['is_active']) && $module['is_active'] == true) {
                $active_modules[] = $k;
            }
        }

        return $active_modules;

    }
    
    /**
     * The module directories: each one under modules/ that holds a PSR-4
     * OWA\Module\<Dir>\Module class that declares its own NAME, then each
     * under extensions/ that holds an OWA\Extension\<Dir>\Module that does.
     *
     * Anything else there is skipped with a notice naming it, once per
     * process: a module class without its own NAME (Module::declaresName()),
     * and a module still in the pre-PSR-4 layout (a lowercase directory and
     * module.php), which loads nothing since v2.0. The notice is how an
     * administrator learns why it vanished. An extension with the directory
     * or the NAME of one of OWA's modules is refused (Core\Extensions).
     *
     * @return string[] directory names
     */
    public static function getPresentModules() {

        $modules = [];
        $names   = [];

        foreach ( \OWA\Core\Env::moduleRoots() as $prefix => $root ) {

            $path      = rtrim( $root, '/' );
            $extension = $prefix === \OWA\Core\Extensions::PREFIX;
            $where     = $extension ? 'extensions' : 'modules';

            if ( ! is_dir( $path ) ) {

                continue;
            }

            foreach ( scandir( $path ) as $dir ) {

                if ( $dir[0] === '.' || ! is_dir( $path . '/' . $dir ) ) {

                    continue;
                }

                $class = $prefix . $dir . '\\Module';

                if ( preg_match( '/^[A-Za-z0-9_]+$/', $dir ) && class_exists( $class ) ) {

                    if ( ! \OWA\Core\Module::declaresName( $class ) ) {

                        if ( ! isset( self::$warned_modules[ "$where/$dir" ] ) ) {

                            self::$warned_modules[ "$where/$dir" ] = true;

                            \OWA\Core\CoreAPI::notice( \OWA\Core\Module::undeclaredNameProblem( $class ) );
                        }

                        continue;
                    }

                    if ( $extension && in_array( $dir, $modules, true ) ) {

                        \OWA\Core\Extensions::refuse( $dir, sprintf( \OWA\Core\CoreAPI::t( 'extensions/%1$s is not loaded: OWA has a module of that name, modules/%1$s. Rename the extension.' ), $dir ) );

                        continue;
                    }

                    if ( $extension && isset( $names[ (string) $class::NAME ] ) ) {

                        \OWA\Core\Extensions::refuse( $dir, sprintf( \OWA\Core\CoreAPI::t( 'extensions/%1$s is not loaded: its %2$s %3$s is registered already, by the module %4$s. Rename it in the extension.' ),
                            $dir, \OWA\Core\CoreAPI::t( 'name' ), (string) $class::NAME, (string) $class::NAME ) );

                        continue;
                    }

                    $modules[]                      = $dir;
                    $names[ (string) $class::NAME ] = (string) $class::NAME;

                } elseif ( ! isset( self::$warned_modules[ "$where/$dir" ] ) ) {

                    self::$warned_modules[ "$where/$dir" ] = true;

                    \OWA\Core\CoreAPI::notice( sprintf(
                        '%s/%s is not loaded: a module is a PascalCase directory with an '
                      . '%s<Dir>\\Module class. The pre-PSR-4 layout was removed in v2.0 '
                      . '(UPGRADING.md).', $where, $dir, $prefix ) );
                }
            }
        }

        \OWA\Core\CoreAPI::debug( 'Modules present: ' . implode( ', ', $modules ) );

        return $modules;
    }

    /**
     * @return list<string>
     */
    public static function getModulesNeedingUpdates() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        return $service->getModulesNeedingUpdates();
    }

    /**
     * Invokes controller to perform controller
     *
     * @param  mixed        $action <module>.<action>, as the request named it
     * @param  array<mixed> $params the controller's params
     * @return mixed what runController() answers; actionNotResolved()'s page when no action is registered under the name
     */
    public static function performAction( $action, $params = [] ) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
            
        /*
         * The action registry is the only way in. A name no module registered
         * is answered as a missing page, and never turned into a class name, so
         * no class under a module's Controller/ directory is reachable unless
         * registered.
         *
         * Malformed (not <module>.<action>, each a bare identifier) is a 400,
         * since it was never a route anywhere; well-formed but unregistered is
         * a 404.
         */
        $action_map = is_string( $action ) && $action !== '' ? $service->getMapValue( 'actions', $action ) : null;

        if ( ! $action_map ) {

            return self::actionNotResolved( $action, self::isActionName( $action ) ? 404 : 400 );
        }

        $controller = \OWA\Core\Lib::simpleFactory( $action_map['class_name'], '', $params );

        return \OWA\Core\CoreAPI::runController( $controller );
    }
    
    /**
     * Whether an action's controller is run only in-process, by performAction()
     * from a handler or another controller (Controller::IN_PROCESS_ONLY).
     *
     * @param mixed $action
     * @return bool
     */
    public static function isInProcessOnlyAction( $action ) {

        $map   = is_string( $action ) && $action !== '' ? \OWA\Core\CoreAPI::serviceSingleton()->getMapValue( 'actions', $action ) : null;
        $class = is_array( $map ) ? (string) ( $map['class_name'] ?? '' ) : '';

        return $class !== '' && class_exists( $class ) && defined( $class . '::IN_PROCESS_ONLY' )
            && constant( $class . '::IN_PROCESS_ONLY' ) === true;
    }

    /**
     * Whether a name has an action name's shape: <module>.<action>, each a bare
     * identifier.
     *
     * @param mixed $action
     * @return bool
     */
    public static function isActionName( $action ) {

        return is_string( $action ) && (bool) preg_match( '/^[a-zA-Z0-9_]+\.[a-zA-Z0-9_]+$/D', $action );
    }

    /**
     * Answer a request whose action resolves to nothing.
     *
     * A name nothing answers to is a client error, not a server fault, so it
     * gets a 4xx and the ordinary error page rather than a 500 and a fatal in
     * the log. Which 4xx depends on how it failed: a malformed name was never a
     * route anywhere (400), a well-formed one simply is not present here (404).
     *
     * The action is recorded server-side, where an administrator can see what
     * was asked for, and deliberately kept out of the response: it is
     * request-supplied, and reflecting it would put attacker-chosen text on the
     * page.
     *
     * @param string     $action
     * @param int        $code
     * @param \Exception $exception
     * @return string|null  the rendered page, or null under the CLI
     */
    public static function actionNotResolved( $action, $code, $exception = null ) {

        self::notice( sprintf(
            'No controller for action "%s" (%d): %s',
            // As it arrived, but always a string: ?owa_do[]=x is an array.
            is_scalar( $action ) ? (string) $action : gettype( $action ),
            $code,
            $exception ? $exception->getMessage() : 'not registered'
        ) );

        // The CLI has already reported through notice(); rendering an HTML
        // error page into a terminal would help nobody.
        if ( defined( 'OWA_CLI' ) ) {

            return null;
        }

        if ( ! headers_sent() ) {

            http_response_code( $code );
        }

        return self::displayView(
            [ 'error_msg' => \OWA\Core\CoreAPI::t( 'The page could not be found.' ) ],
            'base.error'
        );
    }

    /**
     * @param  mixed $controller
     * @return mixed the rendered page or the redirect's answer; nothing when there is no view to show
     */
    public static function runController( $controller ) {
        
        if ( ! $controller || ! method_exists( $controller, 'doAction' ) ) {

            \OWA\Core\CoreAPI::debug("Class is not a controller. no doAction method found.");
            return;
        }

        // call the doAction method which is part of the abstract controller class
        // inherited by all other controller classes
        $data = $controller->doAction();

        // A page the controller rendered itself, as base.report's not-found
        // and cannot-be-drawn answers are (actionNotResolved()), is the response.
        if ( is_string( $data ) ) {

            return $data;
        }

        // Display view if controller calls for one.
        if ( ! empty( $data['view'] ) || ! empty( $data['action'] ) ) {

            // Redirect to a view
            if ( $data['view_method'] == 'redirect' ) {

                return \OWA\Core\Lib::redirectToView( $data );

            } else {

                return \OWA\Core\CoreAPI::displayView( $data );
            }

        } elseif( ! empty( $data['do'] ) ) {

            return \OWA\Core\Lib::redirectToView( $data );
        }
    }

    /**
     * The event types something has registered to process.
     *
     * READ OFF THE ROUTER, not a list. A module that processes a tracking event
     * registers for tracking.<name> (Module::addTrackingEventProcessor()), Base
     * for each event its property registry declares, so this is every name the
     * installation handles as its own -- and nothing that is not active.
     *
     * THIS IS ALSO THE RESERVED SET. A custom event may not take one of these
     * names: reusing one would put a site's own counts into a report measuring
     * something else. See isTrackingEventType().
     *
     * @return list<string> current names, without the tracking. prefix
     */
    public static function trackingEventTypes() {

        $names  = [];
        $prefix = self::TRACKING_DISPATCH_NAMESPACE . '.';
        $map    = (array) \OWA\Core\CoreAPI::serviceSingleton()->getMap( 'event_processors' );

        foreach ( array_keys( $map ) as $dispatch_name ) {

            $dispatch_name = (string) $dispatch_name;

            if ( strncmp( $dispatch_name, $prefix, strlen( $prefix ) ) === 0
                 && $dispatch_name !== self::anyTrackingEvent() ) {

                $names[] = substr( $dispatch_name, strlen( $prefix ) );
            }
        }

        return $names;
    }

    /**
     * The dispatch name a tracking event of this type is sent under.
     *
     * @param  string $event_type a current name
     * @return string tracking.<event_type>
     */
    public static function trackingDispatchName( $event_type ) {

        return self::TRACKING_DISPATCH_NAMESPACE . '.' . (string) $event_type;
    }

    /**
     * The namespace every tracking event is dispatched under.
     *
     * Not stored anywhere. owa_event_raw.event_type holds the name the TRACKER
     * set -- page_view, my_site_signup -- because that is the data and the
     * eventName dimension reads it.
     */
    const TRACKING_DISPATCH_NAMESPACE = 'tracking';

    /**
     * Every tracking event, for a handler that cannot enumerate them.
     *
     * @return string
     */
    public static function anyTrackingEvent() {

        return self::TRACKING_DISPATCH_NAMESPACE
            . \OWA\Module\Base\Classes\EventDispatch::NAMESPACE_WILDCARD;
    }

    /**
     * Whether an event type may be accepted from a tracker at all.
     *
     * TWO WAYS IN. A first-class name is one the property registry declares --
     * trackingEventTypes() derives that set, so the vocabulary and the gate cannot
     * drift. Anything else is admitted if it is a legal CUSTOM event name, because
     * the tracker can emit one with any name (trackCustomEvent) and a site's own
     * events have to reach the pipeline.
     *
     * A name starts with a letter, uses only letters, numbers and underscores,
     * and is at most 40 characters, case-sensitive. That is the same pattern a
     * custom PROPERTY name must match, so it is stated once.
     *
     * ADMISSION ONLY. This says nothing about routing: an internal event called
     * install_complete would pass the second test, which is exactly why the
     * dispatch key is set by logEvent() rather than inferred from a name.
     *
     * @param  string $event_type
     * @return bool
     */
    public static function isTrackingEventType( $event_type ) {

        $event_type = (string) $event_type;

        /*
         * A MATERIALIZED NAME IS NEVER ACCEPTED FROM A TRACKER. session_start and
         * first_visit are built at ingest from flags on the event that carried
         * them; a beacon naming one would be stored beside the real one, a
         * second session start with no session behind it. They also match the
         * custom-name pattern below, so the refusal has to come first.
         */
        if ( \OWA\Module\Base\Classes\TrackingEventHelpers::isMaterialized( $event_type ) ) {

            return false;
        }

        // A name something registered to process: Base's own, or a module's.
        if ( in_array( $event_type, \OWA\Core\CoreAPI::trackingEventTypes(), true ) ) {

            return true;
        }

        /*
         * NO `owa_` PREFIX CHECK HERE, deliberately. The tracker refuses that
         * prefix, and the server does not need to: a tracking event dispatches as
         * tracking.<name>, so a site's owa_x cannot collide with OWA's own
         * routing -- the namespace already separates them. Enforcing it here would
         * only add a way to LOSE a site's data, and it would fall hardest on a
         * tracker cached from before the rule existed.
         */
        return (bool) preg_match(
            \OWA\Module\Base\Classes\TrackingEventHelpers::CUSTOM_NAME_PATTERN,
            $event_type );
    }

    /**
     * Logs a tracking event to the event queue
     *
     * take an owa_event object as a message.
     *
     * @param string $event_type
     * @param \OWA\Module\Base\Classes\Event|array<string, mixed>|string $message an event, or the properties to build one from
     * @return mixed false when the event is not logged; TrackerIngest's answer when it is queued; performAction()'s otherwise
     */
    public static function logEvent( $event_type, $message = '') {

        \OWA\Core\CoreAPI::debug("Logging new event $event_type");

        // backwards compatibility with old style messages
        // @todo is this needed anymore?
        $class = \OWA\Module\Base\Classes\Event::class;

        if ( ! ( $message instanceof $class ) ) {
            
            $event = new \OWA\Module\Base\Classes\Event();
            $event->setProperties( $message );
            $event->setEventType( $event_type );
            
        } else {
            
            $event = $message;
        }

        /*
         * AN OLDER TRACKER'S SPELLINGS BECOME CURRENT HERE, first. The gate, the
         * dispatch name, the processor router and every handler after this see
         * only current names; the old ones are listed in conf/beacon_compat.php
         * and nowhere else.
         */
        \OWA\Module\Base\Classes\Beacon\Compat::apply( $event );
        $event_type = $event->getEventType();

        // Check to ensure that the event is in fact a tracking event
        if ( ! \OWA\Core\CoreAPI::isTrackingEventType( $event_type ) ) {
            
            \OWA\Core\CoreAPI::debug("Not logging. Event with $event_type is not a tracking event.");
            return false;
        }

        /*
         * NAME THE DISPATCH KEY, here and nowhere else.
         *
         * This is the only entry point that knows a tracker sent this, so it is
         * the only place that can say so -- and saying it once means nothing
         * downstream has to derive it. A handler that wants every tracking event
         * registers for the `tracking.` namespace, which is the only shape that
         * covers a site's own event names as well as OWA's.
         *
         * The name is already current (Compat::apply() above), so the dispatch
         * key is tracking.<current name> for every beacon, whatever tracker sent
         * it.
         */
        $event->setDispatchName( \OWA\Core\CoreAPI::trackingDispatchName( $event_type ) );
        
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        
        // Tracking Event processing STAGE 1
        // sets any necessary environmental properties from SERVER global
        $teh = \OWA\Module\Base\Classes\TrackingEventHelpers::getInstance();
        $event = \OWA\Module\Base\Classes\Ingest::at(
            \OWA\Module\Base\Classes\Ingest::EDGE_PRE, $event );

        $environmentals = $service->getMap( 'tracking_properties_environmental' );
        $teh->setTrackerProperties( $event, $environmentals );

        // do not log if the do not log property is set on the event.
        if ($event->get('do_not_log')) {
            return false;
        }

        /*
         * NO ROBOT CHECK HERE. A robot is a spam score now, decided after the
         * property stage (Classes\Spam\Score, from Handler\EventRawHandlers),
         * where geolocation and the parsed agent exist and a queued beacon is
         * judged with the headers stamped on it above. The exclusions below stay
         * at the edge: they are not spam signals, and refusing here keeps what
         * they refuse out of the queue.
         */

        /*
         * Excluded IPs: matched against the address the request came from,
         * before anonymize_ips masked it, since an exclusion names a full
         * address; and against the stored one, so a list written with masked
         * addresses still matches. The full address is read here and not kept.
         */
        $addresses = array_unique( array_filter( [
            \OWA\Module\Base\Classes\TrackingEventHelpers::requestIp(),
            (string) $event->get( 'ip_address' ),
        ], static fn ( $address ) => $address !== '' ) );

        foreach ( $addresses as $ip_address ) {

            if ( \OWA\Core\CoreAPI::isIpAddressExcluded( $ip_address, $event->get('site_id') ) ) {

                \OWA\Core\CoreAPI::debug("Not logging event. IP address found in exclusion list.");

                return false;
            }
        }

        // Refuse an event for a site this installation does not have.
        //
        // Such rows would be unreachable -- reporting is entered through a site, and
        // the site does not exist -- and tracking would be an unauthenticated write:
        // anyone could post events naming any site id and add rows indefinitely.
        //
        // Placed after the IP gate so a rejected request costs no lookup, and before
        // queueing so bad events cannot fill the queue. Nothing on this path creates
        // sites -- they come only from the admin UI, cmd=add-site and install.
        $site_id    = $event->getSiteId();
        $registered = \OWA\Core\CoreAPI::siteRegistration( $site_id );

        if ( $registered === false ) {

            // Debug, not notice: any anonymous request can name any id, and
            // each would add a line to the error log.
            \OWA\Core\CoreAPI::debug( sprintf(
                'Not logging event: site id "%s" is not registered on this installation. '
              . 'Check the site id in your tracking code against cmd=add-site or the Sites admin page.',
                \OWA\Core\CoreAPI::siteIdForLog( $site_id )
            ) );

            return false;
        }
        
        /*
         * The last point that can see the request. Everything after this either
         * goes to a file queue -- drained later, possibly elsewhere, with
         * $_SERVER long gone -- or straight to the processor.
         */
        $event = \OWA\Module\Base\Classes\Ingest::at(
            \OWA\Module\Base\Classes\Ingest::EDGE_POST, $event );

        // Queued: the drain ingests it.
        if ( \OWA\Module\Base\Classes\TrackerIngest::isQueued() ) {

            \OWA\Core\CoreAPI::debug( 'Queuing', $event );

            return \OWA\Module\Base\Classes\TrackerIngest::send( $event );
        }

        // Direct: ingested now; a beacon whose write failed is queued to retry a minute later.
        $processor_action = \OWA\Core\CoreAPI::getEventProcessor( $event );

        \OWA\Core\CoreAPI::debug('About to perform action: '.$processor_action);
        \OWA\Core\CoreAPI::debug( 'With', $event );

        // As it arrived: processing rewrites the properties, and the retry processes it again.
        $arrived = \OWA\Module\Base\Classes\TrackerIngest::envelope( $event );

        // The database could not say whether the site exists: retry a minute
        // later, when the drain asks again, rather than refuse it as unknown.
        if ( $registered === null ) {

            return \OWA\Module\Base\Classes\TrackerIngest::retryLater( $event, $arrived );
        }

        $result = \OWA\Core\CoreAPI::performAction( $processor_action, [ 'event' => $event ] );

        if ( $event->getStatus() === \OWA\Module\Base\Classes\Event::failed ) {

            \OWA\Module\Base\Classes\TrackerIngest::retryLater( $event, $arrived );
        }

        return $result;
    }

    /**
     * Displays a View without user authentication. Takes array of data as input
     *
     * @param array<mixed> $data
     * @param string $viewfile a specific view file to use
     * @return string
     *
     */
    public static function displayView($data, $viewfile = '') {

        if (empty($viewfile)):
            $viewfile = $data['view'];
        endif;

        $view = self::viewFactory( $viewfile, $data );

        $view->setData($data);
        return $view->assembleView($data);

    }

    /**
     * @param  string $name
     * @return mixed as the request holds it; false when absent
     */
    public static function getRequestParam($name) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        return $service->request->getParam($name);

    }

    /**
     * @return \OWA\Core\RequestContainer|null
     */
    public static function getRequest() {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request;
    }

    /**
     * Set a parameter on the current request. Nothing in OWA calls it; tests
     * use it to stage a request.
     *
     * @param  string $name
     * @param  mixed  $value
     * @return true
     */
    public static function setRequestParam($name, $value) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->setParam($name, $value);

    }

    /**
     * @param  string               $time_period
     * @param  array<string, mixed> $params
     * @return \OWA\Module\Base\Classes\TimePeriod
     */
    public static function makeTimePeriod($time_period, $params = []) {

        $period = new \OWA\Module\Base\Classes\TimePeriod();

        if ( ! array_key_exists('period', $params)) {
            $params['period'] = $time_period;
        }
        $period->setFromMap( $params );
        return $period;
    }

    /**
     * Factory method for producing validation objects
     *
     * @return Object
     * @param  string       $class_file
     * @param  array<mixed> $conf
     */
    public static function validationFactory($class_file, $conf = []) {

        // OWA's own validators by convention: 'required' is
        // OWA\Core\Validation\Required. Otherwise a class
        // owa_<name>Validation that an autoloader can load.
        $nsClass = 'OWA\\Core\\Validation\\' . ucfirst( (string) $class_file );

        if ( preg_match( '/^[A-Za-z0-9_]+$/', (string) $class_file ) && class_exists( $nsClass ) ) {

            return new $nsClass( $conf );
        }

        return \OWA\Core\Lib::factory( '', 'owa_', $class_file, $conf, 'Validation' );

    }

    /**
     * @param mixed  $msg      a string, or a value Lib::forLog() formats
     * @param mixed  $context  a value to append as one line; only formatted
     *                         when debug logging is on -- never print_r() it
     *                         into $msg, which builds it on every install
     * @return null
     */
    public static function debug( $msg, $context = null ) {

        $e = \OWA\Core\CoreAPI::errorSingleton();
        $e->debug( $msg, $context );
        return null;
    }

    /**
     * @param  mixed $msg a string, or a value Lib::forLog() formats
     * @return void
     */
    public static function error($msg) {

        $e = \OWA\Core\CoreAPI::errorSingleton();
        $e->err($msg);
        return;
    }

    /**
     * @param  mixed $msg a string, or a value Lib::forLog() formats
     * @return null
     */
    public static function notice($msg) {

        $e = \OWA\Core\CoreAPI::errorSingleton();
        $e->notice($msg);

        return null;
    }

    /**
     * @param string $cookie_name
     * @param mixed  $cookie_value an array is written as a=>b|||c=>d
     * @param int    $expires
     * @param string $path     The path the cookie is sent for. Default /.
     * @param string $domain
     * @param bool   $secure   true marks the cookie Secure. False leaves it
     *                         to the request: Secure on HTTPS (Lib::isHttps()),
     *                         as every cookie OWA sets has been.
     * @param bool $httponly Out of reach of the page's scripts. The sign-in
     *                       cookies are; state the reporting UI reads in the
     *                       browser cannot be.
     * @return void
     */
    public static function createCookie($cookie_name, $cookie_value, $expires = 0, $path = '/', $domain = '', $secure = false, $httponly = false) {

        if ( $domain ) {
            // sanitizes the domain
            $domain = \OWA\Core\Lib::sanitizeCookieDomain( $domain );
            
        } else {
            
            $domain = \OWA\Core\CoreAPI::getSetting('base', 'cookie_domain');
        }

        self::setNamespacedCookie( $cookie_name, $cookie_value, $expires, $path, (string) $domain, $secure, $httponly );
    }

    /**
     * A sign-in cookie (Auth's u and p, the login form's): host-only, on the
     * install's own path (signInCookiePath()), so another host under
     * cookie_domain, or another application on this one, is not sent it.
     * HttpOnly unless asked otherwise; Secure as createCookie() decides.
     *
     * Each write first expires the cookie of that name where createCookie()
     * puts one, at cookie_domain and /: a browser that holds one there sends
     * both, and with the same path the older first, which PHP reads. First,
     * not after: cookie_domain is usually this host, and a browser takes a
     * cookie with the same name, domain and path as the same cookie, so an
     * expiry sent after the new cookie would remove it.
     *
     * @param  string $cookie_name
     * @param  string $cookie_value '' deletes it
     * @param  int    $expires
     * @param  bool   $secure
     * @param  bool   $httponly
     * @return void
     */
    public static function createSignInCookie( $cookie_name, $cookie_value, $expires, $secure = false, $httponly = true ) {

        $path   = self::signInCookiePath();
        $domain = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'cookie_domain' );

        if ( $domain !== '' || $path !== '/' ) {

            self::setNamespacedCookie( $cookie_name, '', time() - 86400, '/', $domain, $secure, $httponly );
        }

        self::setNamespacedCookie( $cookie_name, $cookie_value, $expires, $path, '', $secure, $httponly );
    }

    /**
     * The path of the install's public URL, ending in /: what a sign-in cookie
     * is sent for. / when there is none.
     *
     * @return string
     */
    public static function signInCookiePath() {

        $path = trim( (string) parse_url( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ), PHP_URL_PATH ), '/' );

        return $path === '' ? '/' : '/' . $path . '/';
    }

    /**
     * @param string $cookie_name before the namespace
     * @param mixed  $cookie_value
     * @param int    $expires
     * @param string $path
     * @param string $domain '' for a host-only cookie
     * @param bool   $secure
     * @param bool   $httponly
     * @return void
     */
    private static function setNamespacedCookie( $cookie_name, $cookie_value, $expires, $path, $domain, $secure, $httponly ) {

        if (is_array($cookie_value)) {

            $cookie_value = \OWA\Core\Lib::implode_assoc('=>', '|||', $cookie_value);
        }

        $logged_value = self::cookieValueForLog( $cookie_name, $cookie_value );

        // add namespace
        $cookie_name = self::NS . $cookie_name;

        // debug
        \OWA\Core\CoreAPI::debug(sprintf('Setting cookie %s with values: %s under domain: %s', $cookie_name, $logged_value, $domain));

        // makes cookie to session cookie only
        if (!\OWA\Core\CoreAPI::getSetting('base', 'cookie_persistence')) {
            
            $expires = 0;
        }
        
        setcookie($cookie_name, $cookie_value,
            self::cookieOptions($expires, $path, $domain, self::cookieIsSecure($secure), $httponly));
    }

    /**
     * Whether createCookie() marks a cookie Secure: when asked to, and on any
     * HTTPS request whatever it was asked.
     *
     * $secure can add Secure and never removes it. Callers pass false
     * positionally to reach $httponly, so false cannot mean "not Secure"
     * without taking Secure off cookies on HTTPS installs.
     *
     * @param  bool $secure
     * @return bool
     */
    public static function cookieIsSecure($secure) {

        return (bool) $secure || (bool) \OWA\Core\Lib::isHttps();
    }

    /**
     * The prefix on OWA's cookie names and on the URL parameters it reads from
     * tracked pages, such as owa_source and owa_state.
     */
    const NS = 'owa_';

    /** The sign-in cookies, by name before the namespace: their values are credentials. */
    const CREDENTIAL_COOKIES = [ 'u', 'p' ];

    /**
     * A cookie's value as written to the debug log: [redacted] for a CREDENTIAL_COOKIES one.
     *
     * @param  string $cookie_name
     * @param  mixed  $cookie_value
     * @return string
     */
    public static function cookieValueForLog( $cookie_name, $cookie_value ) {

        return in_array( (string) $cookie_name, self::CREDENTIAL_COOKIES, true ) ? '[redacted]' : (string) $cookie_value;
    }

    /**
     * setcookie()'s options for createCookie().
     *
     * SameSite is Lax, as an option of its own. A path written with it on the
     * end ('/; samesite=Lax', 1.x's default) is read up to the ;.
     *
     * @param int    $expires
     * @param string $path      '' or a path ending at a ; reads as /
     * @param string $domain
     * @param bool   $secure
     * @param bool   $httponly
     * @return array{expires: int, path: string, samesite: string, domain: string, secure: bool, httponly: bool}
     */
    public static function cookieOptions($expires, $path, $domain, $secure, $httponly) {

        $path = trim(explode(';', (string) $path)[0]);

        return [
            'expires'  => (int) $expires,
            'path'     => $path !== '' ? $path : '/',
            'samesite' => 'Lax',
            'domain'   => (string) $domain,
            'secure'   => (bool) $secure,
            'httponly' => (bool) $httponly,
        ];
    }

    /**
     * @param  string $cookie_name
     * @param  string $path
     * @param  string $domain
     * @return null
     */
    public static function deleteCookie($cookie_name, $path = '/', $domain = '') {

        \OWA\Core\CoreAPI::createCookie($cookie_name, false, time()-3600*25, $path, $domain);

        return null;
    }

    /**
     * @param  string $name
     * @param  mixed  $expiration
     * @param  mixed  $length
     * @param  mixed  $format
     * @param  mixed  $type
     * @param  mixed  $cdh_required
     * @return null
     */
    public static function registerStateStore($name, $expiration, $length = '', $format = '', $type = 'cookie', $cdh_required = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->state->registerStore( $name, $expiration, $length, $format, $type, $cdh_required );
    }

    /**
     * @param  string $store
     * @param  string $name
     * @param  mixed  $value
     * @param  string $store_type
     * @param  bool|string $is_perminent only logged
     * @return null
     */
    public static function setState($store, $name, $value, $store_type = '', $is_perminent = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->state->set($store, $name, $value, $store_type, $is_perminent);
    }

    /**
     * @param  string $store
     * @param  string $name
     * @return mixed the value, or the store; false when there is none
     */
    public static function getState($store, $name = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->state->get($store, $name);
    }

    /**
     * @param  string $store
     * @param  string $name
     * @return mixed
     * @deprecated
     */
    public static function getStateParam($store, $name = '') {

        return \OWA\Core\CoreAPI::getState($store, $name);
    }

    /**
     * @param  string $name
     * @return mixed as $_SERVER holds it; false when absent
     */
    public static function getServerParam($name = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->request->getServerParam($name);
    }

    /** @var list<callable(): void> what afterResponse() was given, not yet run */
    private static $after_response = [];

    /**
     * Run $fn once the response has gone to the browser: after
     * fastcgi_finish_request() where PHP-FPM serves the request, otherwise at
     * shutdown, which the browser still waits for. For work whose time must
     * not show in the reply's, such as mail sent only for a known address.
     *
     * @param  callable(): void $fn
     * @return void
     */
    public static function afterResponse( callable $fn ) {

        if ( ! self::$after_response ) {

            register_shutdown_function( [ self::class, 'runAfterResponse' ] );
        }

        self::$after_response[] = $fn;
    }

    /**
     * What afterResponse() was given, run now. The shutdown function's; a
     * test's to run them itself.
     *
     * @return void
     */
    public static function runAfterResponse() {

        $fns = self::$after_response;
        self::$after_response = [];

        if ( ! $fns ) {

            return;
        }

        if ( function_exists( 'fastcgi_finish_request' ) ) {

            fastcgi_finish_request();
        }

        foreach ( $fns as $fn ) {

            try {

                $fn();

            } catch ( \Throwable $e ) {

                self::error( 'After the response: ' . $e->getMessage() );
            }
        }
    }

    /**
     * @param  string $store
     * @param  string $name
     * @return null
     */
    public static function clearState($store, $name = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $service->request->state->clear($store, $name);

        return null;
    }

    /**
     * @param object|string $event  an event, preferably; a bare type cannot say
     *                              whether it is a tracking event, so a string
     *                              resolves only by its own name
     * @return mixed the processor action; nothing when none is registered
     */
    public static function getEventProcessor($event) {

        $service = \OWA\Core\CoreAPI::serviceSingleton();

        // Registered under the DISPATCH name, like the listeners.
        $dispatch_name = is_object( $event )
            ? $event->getDispatchName() : (string) $event;

        $processor = $service->getMapValue( 'event_processors', $dispatch_name );

        if ( ! $processor ) {

            /*
             * The namespace, walked outside in, the way notify() resolves
             * listeners -- so `tracking.*` answers for every tracking event and a
             * custom name needs no registration of its own.
             */
            $processor = self::namespacedProcessor( $service, $dispatch_name );
        }

        if ( $processor ) {

            return $processor;
        }

        \OWA\Core\CoreAPI::debug("no event processor found for $dispatch_name");
    }

    /**
     * A processor registered for a namespace the dispatch name falls under.
     *
     * The same walk EventDispatch::listenersFor() does, for the same reason: a
     * custom event's name belongs to the site, so nothing can register for it, and
     * a flat map keyed by exact name leaves it with no processor at all --
     * performAction(null) then does nothing and the event is accepted and
     * discarded without an error.
     *
     * @param  object $service
     * @param  string $dispatch_name
     * @return mixed  the processor, or null
     */
    private static function namespacedProcessor( $service, $dispatch_name ) {

        $segments = explode( '.', (string) $dispatch_name );

        // A name is not its own namespace.
        array_pop( $segments );

        $prefix = '';

        foreach ( $segments as $segment ) {

            $prefix .= $segment;

            $processor = $service->getMapValue( 'event_processors',
                $prefix . \OWA\Module\Base\Classes\EventDispatch::NAMESPACE_WILDCARD );

            if ( $processor ) {

                return $processor;
            }

            $prefix .= '.';
        }

        return null;
    }

    /**
     * Handles OWA internal page/action requests
     *
     * @param  array<mixed>|null $caller_params params that override the request's
     * @param  string            $action        the request's action or do when empty
     * @return mixed
     */
    public static function handleRequest($caller_params = null, $action = '') {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        // Override request parsms with those passed by caller
        if (!empty($caller_params)) {
            $service->request->mergeParams($caller_params);
        };

        $params = $service->request->getAllOwaParams();

        if ( ! self::$request_logged ) {
            // Names only: the values are whatever a form posted, a password among them.
        \OWA\Core\CoreAPI::debug( 'Handling request with params: ' . implode( ', ', array_keys( (array) $params ) ) );
        }

        if (empty($action)) {
            $action = \OWA\Core\CoreAPI::getRequestParam('action');
            if (empty($action)) {
                $action = \OWA\Core\CoreAPI::getRequestParam('do');

                if (empty($action)) {
                    \OWA\Core\CoreAPI::debug('no action specified on request params');
                    return; 
                }
            }
        }
        
        self::$request_logged = true;

        // A request does not reach an action only code runs (Controller::IN_PROCESS_ONLY).
        if ( self::isInProcessOnlyAction( $action ) ) {

            return self::actionNotResolved( $action, 404 );
        }

        \OWA\Core\CoreAPI::debug('About to perform action: '.$action);
        return \OWA\Core\CoreAPI::performAction($action, $params);

    }    
    
    /**
     * Handles REST endpoint requests
     *
     * @return mixed
     */
    public static function handleRestRequest() {
        
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        
        $params = $service->request->getAllOwaParams();
        
        // Names only: the values may carry credentials.
        \OWA\Core\CoreAPI::debug( 'Handling REST request with params: ' . implode( ', ', array_keys( (array) $params ) ) );
        
        $action = \OWA\Core\CoreAPI::getRequestParam('do');

        // REST API Requests
        // Lookup controller for REST API route.
        if ( \OWA\Core\RequestType::is( \OWA\Core\RequestType::REST ) ) {

            // get request method
            $request_method = $service->request->getRequestType();

            /*
             * A CORS preflight, built by class.
             */
            if ($request_method == 'OPTIONS') {

                $controller = new \OWA\Module\Base\Controller\CorsPreflight( [] );
                return \OWA\Core\CoreAPI::runController( $controller );
            }

            // check for rewriten rest params and set module, version, and do params from that.
            //
            // This has to happen BEFORE the request is required to name an action:
            // the documented route form is /api/<module>/<version>/<route>, which
            // .htaccess rewrites to owa_rest_params alone, carrying no 'do' of its own.
            $rest_params = self::getRequestParam('rest_params');

            if ( $rest_params ) {

                $rest_params = explode('/', $rest_params);
                self::debug( 'exploding raw REST params:');
                self::debug( 'REST params: ' . implode( ', ', array_keys( (array) $rest_params ) ) );

                if ( count( $rest_params ) >= 3 ) {

                    $params['module'] = $rest_params[0];
                    $params['version'] = $rest_params[1];
                    $params['do'] = $rest_params[2];
                    $action = $params['do'];
                }
            }

            // A REST request must name its route. There is deliberately no default
            // action here -- the admin endpoint falls back to the start_page setting
            // when 'do' is absent, but a client that omits the route has made a
            // malformed request, and choosing one for it would hide the mistake.
            if ( ! $action ) {

                \OWA\Core\CoreAPI::debug('no action specified on REST request params');
                return self::restError( 400 );
            }

            
            \OWA\Core\CoreAPI::debug('Generating REST API route controller...');
            
            if ( \OWA\Core\Lib::keyExistsNotEmpty( 'module', $params ) && \OWA\Core\Lib::keyExistsNotEmpty( 'version', $params ) ) {
            
                $route = self::lookupRestRoute( $request_method, $params['module'], $params['version'], $action );

                // The path after module/version/route, named by the route's params_order.
                $path_params = $route && $rest_params
                    ? self::restPathParams( array_slice( $rest_params, 3 ), $route['conf']['params_order'] ?? [] )
                    : [];

                if ( $route && $path_params !== null ) {

                    $params = array_merge( $params, $path_params );
                    $params['rest_route'] = $route;
                    $controller = \OWA\Core\Lib::simpleFactory( $route['class_name'], '', $params );
                    return \OWA\Core\CoreAPI::runController( $controller );
                
                } else {

                    // Answered exactly as an unauthenticated request is, and for the
                    // same reason: this runs before the controller authenticates, so
                    // a distinct "no such route" reply would let an anonymous caller
                    // enumerate the API by watching which names answer differently.
                    // A path with more segments than the route names is no route either.
                    \OWA\Core\CoreAPI::debug('No REST API route found');
                    return self::restError( 401 );
                }

            } else {

                \OWA\Core\CoreAPI::debug('Could not generate controller because no version param was on request.');
                return self::restError( 400 );
            }
            
        } else {
            
            \OWA\Core\CoreAPI::debug('This is not a REST API request.');
        }
    }
    
    /**
     * Renders a REST error through the standard response envelope, so it
     * cannot be mistaken for a successful call that carried no data.
     *
     * These run BEFORE the controller authenticates, so the reply is readable by
     * anyone and says nothing a caller could not already supply: no route names,
     * no hint of whether a route exists, no suggestions. 401 reuses the wording
     * of a genuine auth failure for the same credentials, so the two cannot be
     * told apart.
     *
     * @param  int    $code  400 for a malformed request, 401 otherwise.
     * @return string        The rendered response body.
     */
    private static function restError( $code ) {

        $messages = [
            400 => [
                'headline'  => \OWA\Core\CoreAPI::t( 'Bad request.' ),
                'msg'       => \OWA\Core\CoreAPI::t( 'The request names no route.' )
            ],
            401 => [
                'headline'  => \OWA\Core\CoreAPI::t( 'Not authenticated.' ),
                // As Controller::notAuthenticatedAction() answers the same request.
                'msg'       => \OWA\Core\Auth::get_instance()->carriesAppPassword()
                    ? \OWA\Core\Controller::refusedAppPasswordMessage()
                    : \OWA\Core\Controller::notAuthenticatedMessage()
            ],
        ];

        $error_msg = isset( $messages[ $code ] ) ? $messages[ $code ] : $messages[ 400 ];

        http_response_code( $code );

        return self::displayView( [ 'error_msg' => $error_msg ], 'base.restApi' );
    }

    /**
     * The path segments after module/version/route, named by the route's
     * params_order: the first segment is the first name's value, and so on.
     * Empty segments at the end (a trailing slash) name nothing.
     *
     * @param  list<string> $segments
     * @param  mixed        $params_order the route's list of names; anything else names none
     * @return array<string, string>|null null when there are more segments than names
     */
    public static function restPathParams( array $segments, $params_order ) {

        while ( $segments && end( $segments ) === '' ) {

            array_pop( $segments );
        }

        $names = is_array( $params_order ) ? array_values( $params_order ) : [];

        if ( count( $segments ) > count( $names ) ) {

            return null;
        }

        $params = [];

        foreach ( array_values( $segments ) as $i => $value ) {

            $params[ (string) $names[ $i ] ] = $value;
        }

        return $params;
    }

    /**
     * A HEAD request takes the GET route when the route registers no HEAD of
     * its own: the same controller, and the server sends no body.
     *
     * @param  string $request_method
     * @param  string $module
     * @param  string $version
     * @param  string $do
     * @return RestRoute|void nothing when an argument is empty or no route is registered
     */
    public static function lookupRestRoute( $request_method, $module, $version, $do ) {

        if ( ! empty( $request_method )
            && ! empty( $version )
            && ! empty( $do )
            && ! empty( $module )
        ){

            $service = \OWA\Core\CoreAPI::serviceSingleton();
            $route = $service->getRestApiRoute($module, $version, $do, $request_method );

            if ( ! $route && $request_method === 'HEAD' ) {

                $route = $service->getRestApiRoute( $module, $version, $do, 'GET' );
            }

            \OWA\Core\CoreAPI::debug($route);
            return $route;
        }
    }

    /**
     * A module's schema is behind (Service::isSchemaUpdateRequired()).
     *
     * @return bool
     */
    public static function isSchemaUpdateRequired() {

        return \OWA\Core\CoreAPI::serviceSingleton()->isSchemaUpdateRequired();
    }

    /**
     * @return bool
     */
    public static function isUpdateRequired() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        return $service->isUpdateRequired();
    }

    /**
     * @return list<Row>
     */
    public static function getSitesList() {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.site' )->getTableName() );
        $db->selectColumn('*');
        $sites = $db->getAllRows();

        if ( ! $sites ) {
            $sites = [];
        }
        return $sites;
    }
    
    
    /**
     * @return \OWA\Module\Base\Classes\EventDispatch
     */
    public static function getEventDispatch() {


        return \OWA\Module\Base\Classes\EventDispatch::get_instance();

    }

    /**
     * @param  string $name
     * @return object
     */
    public static function getEventQueue( $name ) {

        // make queue if needed
        if ( ! isset( self::$event_queues[ $name ] ) ) {

            // get queue config
            $s = \OWA\Core\CoreAPI::serviceSingleton();
            $map = $s->getMapValue('event_queues', $name);

            if ( $map ) {

                $implementation = $s->getMapValue( 'event_queue_types', $map['queue_type'] );

                if ( $implementation
                     && isset( $implementation[0] )
                     && isset( $implementation[1] )
                ) {
                    \OWA\Core\CoreAPI::debug( 'Queue implementation:', $implementation );
                    self::$event_queues[ $name ] = \OWA\Core\Lib::simpleFactory( $implementation[0], '', $map );

                } else {

                    throw new \Exception("No event queue by that type found.");
                }

            } else {

                throw new \Exception("No configuration found for event queue $name.");
            }
        }
            // return queue
        return self::$event_queues[ $name ];
    }

    /**
     * @param  string $command
     * @return mixed the class that runs it; false when none does
     */
    public static function getCliCommandClass($command) {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        return $s->getCliCommandClass($command);
    }

    /**
     * @param  string|null $ip_address
     * @return \OWA\Module\Base\Classes\Geolocation|null
     */
    public static function getGeolocationFromIpAddress($ip_address) {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $s->geolocation->getGeolocationFromIp($ip_address);
        return $s->geolocation;
    }

    /**
     * @return float
     */
    public static function getNonceTimeInterval() {

        return  ceil( time() / \OWA\Core\CoreAPI::getSetting( 'base', 'nonce_expiration_period') );
    }

    /**
     * A nonce for $action, the current user and a time window.
     *
     * $tick defaults to the current window. verifyNonce() also passes the one
     * before it: windows are fixed buckets of the epoch, so a form rendered a
     * second before a boundary would otherwise be refused a second after it.
     *
     * @param  string         $action
     * @param  int|float|null $tick
     * @return string
     */
    public static function createNonce($action, $tick = null) {

        $time = $tick ?? \OWA\Core\CoreAPI::getNonceTimeInterval();
        $cu = \OWA\Core\CoreAPI::getCurrentUser();
        $user_id = $cu->getUserData( 'user_id' );

        $full_nonce = $time . $action . $user_id . 'owa_nonce';

        $nonce = substr( \OWA\Core\CoreAPI::saltedHash($full_nonce, 'nonce'), -12, 10);

        return $nonce;
    }
    
    /**
     * A nonce for one REST route and one HTTP method: one minted for a GET of
     * a route is not accepted on a POST or DELETE of it.
     *
     * @param string $version
     * @param string $module
     * @param string $do
     * @param string $method GET, POST, DELETE, ...
     * @return string
     */
    public static function createRestApiNonce( $version, $module, $do, $method = 'GET' ) {

        return self::createNonce( self::restApiNonceAction( $version, $module, $do, $method ) );
    }

    /**
     * What a REST nonce is minted for and checked against (Controller::doAction()).
     *
     * @param  string $version
     * @param  string $module
     * @param  string $do
     * @param  string $method
     * @return string
     */
    public static function restApiNonceAction( $version, $module, $do, $method ) {

        return strtoupper( (string) $method ) . ' ' . $version . $module . $do;
    }

    /**
     * @param  string $data
     * @param  string $scheme    NONCE or AUTH, any case
     * @param  string $hash_type
     * @return string
     */
    public static function saltedHash( $data, $scheme, $hash_type = 'md5' ) {

        $salt = \OWA\Core\CoreAPI::getSalt( $scheme );
        return \OWA\Core\Lib::hash( $hash_type, $data, $salt );
    }



    /**
     * The constants each salt scheme is read from: its key, then its salt.
     * They are Requirements::SECRET_CONSTANTS, which owa-config.php must define.
     */
    const SALT_CONSTANTS = [
        'NONCE' => [ 'OWA_NONCE_KEY', 'OWA_NONCE_SALT' ],
        'AUTH'  => [ 'OWA_AUTH_KEY', 'OWA_AUTH_SALT' ],
    ];

    /**
     * The scheme's key and salt constants, concatenated. A constant that is
     * not defined, or a scheme not in SALT_CONSTANTS, adds nothing.
     *
     * @param  string $scheme NONCE or AUTH, any case
     * @return string
     */
    public static function getSalt( $scheme ) {

        $scheme = strtoupper( (string) $scheme );

        if ( ! self::$salts ) {

            self::$salts = [];

            foreach ( self::SALT_CONSTANTS as $name => $constants ) {

                foreach ( $constants as $const ) {

                    if ( defined( $const ) ) {

                        self::$salts[ $name ][] = (string) constant( $const );
                    }
                }
            }
        }

        return implode( '', self::$salts[ $scheme ] ?? [] );
    }

    /**
     * @param  int  $length
     * @param  bool $special_chars
     * @param  bool $more_special_chars
     * @return string
     */
    public static function secureRandomString( $length, $special_chars = true, $more_special_chars = true ) {

        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        if ( $special_chars )
            $chars .= '!@#$%^&*()';
        if ( $more_special_chars )
            $chars .= '-_ []{}<>~`+=,.;:/?|';

        $password = '';
        for ( $i = 0; $i < $length; $i++ ) {
            $password .= substr($chars, \OWA\Core\CoreAPI::random(0, strlen($chars) - 1), 1);
        }

        return $password;
    }

    /**
     * @param  int $min
     * @param  int $max
     * @return int
     */
    public static function random($min, $max) {

        // A string from the start: strlen() of null is deprecated on PHP 8.1+.
        static $rnd_value = '';

        if ( strlen($rnd_value) < 8 ) {

            $notrandom = false;

            if ( function_exists( 'openssl_random_pseudo_bytes' ) ) {

                $rnd_value = bin2hex(openssl_random_pseudo_bytes(32, $cstrong));

                if ( ! $cstrong ) {

                    $notrandom = true;
                }

            } else {

                $notrandom = true;
            }

            if ( $notrandom ) {

                $seed = microtime();
                $rnd_value = md5( uniqid(microtime() . mt_rand(), true ) . $seed );
                $rnd_value .= sha1($rnd_value);
                $rnd_value .= sha1($rnd_value . $seed);

            }

        }
        // Take the first 8 digits for our value
        $value = substr($rnd_value, 0, 8);

        // Strip the first eight, leaving the remainder for the next call to random.
        $rnd_value = substr($rnd_value, 8);

        $value = abs(hexdec($value));

        $max_random_number = 4294967295; // 0xffffffff

        // Reduce the value to be within the min - max range
        if ( $max != 0 )
            $value = $min + ( $max - $min + 1 ) * $value / ( $max_random_number + 1 );

        return abs(intval($value));
    }

    /**
     * Queue a CLI command to run once, from the scheduler.
     *
     * @param  string      $command  a registered CLI command
     * @param  array<mixed> $params  its arguments
     * @param  string|null $key      at most one pending job per key
     * @param  int         $delay    seconds before it may run
     * @param  array<string, mixed>|null $retry  this job's retry policy (Classes\RetryPolicy);
     *                             null for its command's, else the one-off default
     * @return string|false  the job's id
     */
    public static function enqueueJob( $command, array $params = [], $key = null, $delay = 0, $retry = null ) {

        return \OWA\Module\Base\Classes\JobQueue::enqueue( $command, $params, $key, $delay, $retry );
    }

    /**
     * @param  string       $site_id
     * @param  array<mixed> $options
     * @return string
     */
    public static function getJsTrackerTag( $site_id, $options = [] ) {


        $t = new \OWA\Core\Template();

        $t->set( 'site_id', $site_id );
        // The Profile's own commands, from its tag settings.
        $t->set( 'profile_cmds', \OWA\Module\Base\Classes\TrackerBundle::commandLines( $site_id ) );
        // And any a third-party module still adds the old way.
        $cmds = \OWA\Core\CoreAPI::filter( 'tracker_tag_cmds', [] );
        $t->set( 'cmds', $cmds );
        $t->set('options', $options);
        $t->set_template('js_log_tag.php');
        return $t->fetch();
    }

    /**
     * The tag that loads a Profile's tracking bundle: one script,
     * configured on the server, so it never needs pasting again.
     *
     * @param  string $site_id
     * @return string
     */
    public static function getJsTrackerBundleTag( $site_id ) {

        $t = new \OWA\Core\Template();

        $t->set( 'site_id', $site_id );
        $t->set( 'bundle_url', \OWA\Module\Base\Classes\TrackerBundle::url( $site_id ) );
        $t->set_template( 'js_bundle_tag.php' );

        return $t->fetch();
    }

    /**
     * @param  string $module_name
     * @return bool|void nothing for no module name
     */
    public static function deactivateModule( $module_name ) {

        if ( $module_name ) {

            // Load a fresh module instance, symmetric with installModule() and
            // installModule(). deactivate() only flips the persisted is_active setting on
            // $this->name, so a boot-loaded instance is not needed, and a module outside
            // the boot-loaded set can still be deactivated.
            $m = \OWA\Core\CoreAPI::moduleClassFactory($module_name);
            return $m->deactivate();
        }
    }

    /**
     * @param  string      $module_name
     * @param  string|null $problem     set to why it failed (Module::$install_problem), '' when it did not
     * @return bool|void nothing for no module name
     */
    public static function installModule( $module_name, &$problem = null ) {

        if ($module_name) {

            $m = \OWA\Core\CoreAPI::moduleClassFactory($module_name);
            $status = $m->install();
            $problem = (string) $m->install_problem;
            return $status;
        }
    }

    /**
     * @return string
     */
    public static function generateInstanceSpecificHash() {

        if ( defined( 'OWA_SECRET' ) ) {
            $salt = OWA_SECRET;
        } else {
            $salt = '';
        }

        if ( defined( 'OWA_DB_USER' ) ) {
            $salt .= OWA_DB_USER;
        }

        if ( defined( 'OWA_DB_PASSWORD' ) ) {
            $salt .= OWA_DB_PASSWORD;
        }

        return md5( $salt );
    }

    /**
     * Every dimension, one entry per name: the modules' registrations and, when
     * a Property is named, its applied custom dimensions.
     *
     * Classes\DimensionRegistry::forProperty()->all(), which says how the
     * entity-keyed registries are flattened and why the custom dimensions are
     * per Property. Without a Property this is the modules' vocabulary alone.
     *
     * Pass $entities to scope the answer to a set of entities: a dimension is
     * offered only if it is defined on one of them, and a name defined on none
     * is absent entirely. That is how one catalog will serve two schema
     * generations without a caller having to tell them apart by name -- the
     * scope decides, so the wrong generation is not filtered out downstream, it
     * is never in the answer.
     *
     * @param string[]|null   $entities    entity names to restrict the answer to
     * @param int|string|null $property_id the Property whose custom dimensions to include
     * @return array<string, DimensionEntry> name => entry
     */
    public static function getAllDimensions( $entities = null, $property_id = null ) {

        return \OWA\Module\Base\Classes\DimensionRegistry::forProperty( $property_id )->all( $entities );
    }

    /**
     * @return array<string, list<MetricDefinition>>
     */
    public static function getAllMetrics() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        return $s->metrics;
    }

    /**
     * A user-facing string in the locale in use, keyed by its English.
     *
     * See Core\Strings. Write the English at the call site; the catalogue is
     * generated from these calls by `cli.php cmd=strings-extract`.
     *
     * @param  string $text     the English, as a string literal
     * @param  string $context  optional, to tell two meanings of the same English apart
     * @return string
     */
    public static function t( $text, $context = '' ) {

        return \OWA\Core\Strings::translate( $text, $context );
    }

    /**
     * A counted message in the locale in use: the form for $n. Fill in the
     * count with sprintf():
     *
     *   sprintf( \OWA\Core\CoreAPI::tn( '%d report', '%d reports', $n ), $n )
     *
     * See Core\Strings.
     *
     * @param  string    $singular the English for one, as a string literal
     * @param  string    $plural   the English for any other count, as a string literal
     * @param  int|float $n        the count
     * @param  string    $context  optional, as t()'s
     * @return string
     */
    public static function tn( $singular, $plural, $n, $context = '' ) {

        return \OWA\Core\Strings::translatePlural( $singular, $plural, $n, $context );
    }

    /**
     * @return int
     */
    public static function getRequestTimestamp() {

        $r = \OWA\Core\CoreAPI::requestContainerSingleton();
        return $r->getTimestamp();
    }

    /**
     * Edge receipt in microseconds -- the same instant getRequestTimestamp()
     * answers in seconds, taken from one reading.
     *
     * @return int
     */
    public static function getRequestTimestampMicroseconds() {

        $r = \OWA\Core\CoreAPI::requestContainerSingleton();
        return $r->getTimestampMicroseconds();
    }

    /**
     * @param  string $capability
     * @return bool
     */
    public static function isEveryoneCapable( $capability ) {

        $caps = \OWA\Core\CoreAPI::getCapabilities('everyone');

        if ( in_array( $capability, $caps ) ) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return string|null
     */
    public static function getCurrentUrl() {
        
        $r = \OWA\Core\CoreAPI::requestContainerSingleton();
        return $r->getCurrentUrl();
    }

    /**
     * $siteId is a Profile id. Passing it lets one Profile exclude an
     * office IP that another Profile still wants to record; omitting it
     * falls back to the install-wide list.
     *
     * @param  string $ip_address
     * @param  string $siteId
     * @return true|void nothing when the address is not excluded
     */
    public static function isIpAddressExcluded( $ip_address, $siteId = '' ) {

        // do not log if ip address is on the do not log list
        $ips = \OWA\Core\CoreAPI::getSetting( 'base', 'excluded_ips', 'profile', $siteId );
        
        if ( $ips ) {
            
            \OWA\Core\CoreAPI::debug('Excluded ip address list: '.$ips);

            $ips = trim( $ips );

            if ( strpos( $ips, ',' ) ) {
                $ips = explode( ',', $ips );
            } else {
                $ips = [ $ips ];
            }

            foreach( $ips as $ip ) {
                $ip = trim( $ip );
                if ( $ip_address === $ip ) {
                    \OWA\Core\CoreAPI::debug("Request is from excluded ip address: $ip.");
                    return true;
                }
            }
        }
    }
    
    /**
     * @param  string $file_name   under conf/, merged with its override in the
     *                             data directory's conf/ (Overrides), when OWA may run that
     * @param  string $filter_name 'conf.'.$file_name when empty
     * @return mixed the conf array, through the filter
     */
    public static function loadConf( $file_name, $filter_name = '' ) {
        
        $conf = [];
        $conf_file = \OWA\Core\Env::confDir() . $file_name;
        
        if ( file_exists( $conf_file ) ) {
        
            $conf = include( $conf_file);
        }
        
        $sup_file = \OWA\Core\Overrides::file( $file_name );

        if ( $sup_file !== null ) {

            $sup_conf = include( $sup_file );
            
            if ( is_array( $sup_conf) ) {
            
                $conf = array_merge( $conf, $sup_conf );
            }
        }
        
        // see generic filter name for filtering the final conf array
        if ( ! $filter_name ) {
            
            $filter_name = 'conf.' . $file_name;
        }
        
        return \OWA\Core\CoreAPI::filter( $filter_name, $conf );
    }

    /**
     * Attaches an event handler to the event queue
     *
     * @param string $filter_name
     * @param mixed $callback
     * @param int $priority
     * @return void
     */
    public static function registerFilter( $filter_name, $callback, $priority = 10 ) {

        $ed = \OWA\Core\CoreAPI::getEventDispatch();
        $ed->attachFilter($filter_name, $callback, $priority);
    }

    /**
     * Run a filter, passing any further arguments to each listener as context.
     *
     * VARIADIC, because EventDispatch::filter() hands every argument after the
     * first to each listener, chaining only the first.
     *
     * @param  string $filter_name
     * @param  mixed  $value    chained through the listeners
     * @param  mixed  ...$context  passed to every listener, unchanged
     * @return mixed
     */
    public static function filter( $filter_name, $value, ...$context ) {

        $ed = \OWA\Core\CoreAPI::getEventDispatch();

        return $ed->filter( $filter_name, $value, ...$context );
    }
    
    /**
     * @param  iterable<array<mixed>>|null $items
     * @param  string                     $entity_name
     * @return list<\OWA\Core\Entity>
     */
    public static function loadEntitiesFromArray( $items, $entity_name ) {
        
        $set = [];
        
        if ( $items ) {
            
            foreach ($items as $item ) {
                
                $entity = \OWA\Core\CoreAPI::entityFactory( $entity_name );
                $entity->setProperties( $item );
                $set[] = $entity;
                
            }
        }
        
        return $set;
    }
    
    }
