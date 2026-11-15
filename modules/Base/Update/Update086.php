<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Delete the stored rows of the removed File Cache and Memcached modules, and
 * of the base settings that configured the persistent object cache.
 *
 * The modules are gone from disk, so the loader skips them. Their stored
 * is_active and schema_version still made cmd=instance-info list fileCache as
 * active and up to date, and log on boot that a module stores settings it
 * does not declare.
 *
 *   fileCache.*, memcachedCache.*      every row the two modules stored
 *   base.cache_objects, cacheType,     the persistent cache's settings, which
 *   cache_dir, memcachedServers,       nothing reads now
 *   memcachedPersistantConnections,
 *   default_cache_expiration_period
 *
 * Every scope.
 *
 * THE INVERSE. Before anything is deleted, the rows, every column, are kept in
 * the cache_settings_before_086 setting. down() puts them back and removes the
 * record. A run of up() that finds no row changes nothing, so a second run
 * leaves the record of the first; down() with no record changes nothing.
 *
 * RAW SQL on owa_setting, as Update085, so a test can point the whole update at
 * a scratch table.
 *
 * NOT CLI-ONLY. A handful of rows.
 */
class Update086 extends \OWA\Core\Update {

    var $schema_version = 86;

    var $is_cli_mode_required = false;

    /** The modules whose every row this deletes. */
    const MODULES = array( 'fileCache', 'memcachedCache' );

    /** The base settings this deletes. */
    const RETIRED = array( 'cache_objects', 'cacheType', 'cache_dir', 'memcachedServers',
        'memcachedPersistantConnections', 'default_cache_expiration_period' );

    /** What up() deleted: { rows: id => row }. */
    const BEFORE = 'cache_settings_before_086';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /** @var string|null the settings table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    /** @var object|null the settings to keep in step instead of the install's. TESTS ONLY. */
    public $settings = null;

    /**
     * The settings this process saves, which must agree with the table.
     *
     * Boot loads every row of a module with no settings.php, fileCache's
     * included, and the update run saves its settings when it ends. Deleted
     * from the table only, the rows were written straight back; restored to
     * the table only, the save's pruning would delete them again.
     *
     * None with a scratch table and no stand-in: the install's own settings
     * are not the ones being changed.
     */
    private function settings() {

        if ( $this->settings ) {

            return $this->settings;
        }

        return $this->table ? null : \OWA\Core\CoreAPI::configSingleton();
    }

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName();
    }

    /** The rows this deletes, as a WHERE clause, and its parameters. */
    private static function where() {

        $in = function ( array $values ) {
            return implode( ', ', array_fill( 0, count( $values ), '?' ) );
        };

        return array(
            sprintf( "module IN ( %s ) OR ( module = 'base' AND name IN ( %s ) )", $in( self::MODULES ), $in( self::RETIRED ) ),
            array_merge( self::MODULES, self::RETIRED ),
        );
    }

    function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table();

        list( $where, $params ) = self::where();

        $rows = (array) $db->get_results( sprintf( 'SELECT * FROM %s WHERE %s', $table, $where ), $params );

        if ( ! $rows ) {

            return true;
        }

        $record = $this->record();
        $record['rows'] = isset( $record['rows'] ) && is_array( $record['rows'] ) ? $record['rows'] : array();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            // The first run's copy is the one down() restores.
            if ( ! isset( $record['rows'][ (string) $row['id'] ] ) ) {

                $record['rows'][ (string) $row['id'] ] = $row;
            }
        }

        // Kept before anything changes, so a run that stops part way can still be undone.
        if ( ! $this->writeRecord( serialize( $record ) ) ) {

            $this->e->notice( sprintf( 'Keeping the object cache settings in %s failed.', self::BEFORE ) );

            return false;
        }

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE %s', $table, $where ), $params ) === false ) {

            $this->e->notice( 'Removing the object cache settings failed.' );

            return false;
        }

        $settings = $this->settings();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            if ( $settings && $row['scope_type'] === 'install' ) {

                $settings->removeSetting( $row['module'], $row['name'] );
            }
        }

        $this->e->notice( sprintf( 'Object cache settings: %d %s removed.', count( $rows ), \OWA\Core\Lib::plural( count( $rows ), 'row', 'rows' ) ) );

        return true;
    }

    /** The exact inverse, from the record up() kept. */
    function down() {

        $record = $this->record();

        if ( ! $record ) {

            return true;
        }

        $settings = $this->settings();

        foreach ( (array) ( $record['rows'] ?? array() ) as $row ) {

            if ( ! $this->restoreRow( (array) $row ) ) {

                $this->e->notice( sprintf( 'Restoring setting row %s failed.', $row['id'] ?? '' ) );

                return false;
            }

            if ( $settings && $row['scope_type'] === 'install' ) {

                $settings->db_settings[ $row['module'] ][ $row['name'] ] =
                    @unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );
            }
        }

        if ( \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->table() ),
                array( $this->recordId() ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }

    /** What up() kept, or an empty array. */
    private function record() {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT value FROM %s WHERE id = ?', $this->table() ),
            array( $this->recordId() ) );

        if ( ! $row ) {

            return array();
        }

        $record = @unserialize( (string) ( (array) $row )['value'], array( 'allowed_classes' => false ) );

        return is_array( $record ) ? $record : array();
    }

    private function recordId() {

        return (string) \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->makeId(
            'install', self::INSTALL_SCOPE_ID, 'base', self::BEFORE );
    }

    /** Upsert the install row of the record. */
    private function writeRecord( $value ) {

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)'
          . " VALUES (?, 'install', ?, 'base', ?, ?, 0, ?) ON DUPLICATE KEY UPDATE value = ?", $this->table() ),
            array( $this->recordId(), self::INSTALL_SCOPE_ID, self::BEFORE, $value, time(), $value ) ) !== false;
    }

    /** A row put back exactly as SELECT * read it. */
    private function restoreRow( array $row ) {

        $columns = array_keys( $row );

        foreach ( $columns as $column ) {

            // Names the table gave, but they are interpolated, so only plain ones.
            if ( ! preg_match( '/^[a-z_]+$/', (string) $column ) ) {

                return false;
            }
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $this->table() ), array( (string) $row['id'] ) ) === false ) {

            return false;
        }

        return $db->query( sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $this->table(), implode( ', ', $columns ),
            implode( ', ', array_fill( 0, count( $columns ), '?' ) ) ), array_values( $row ) ) !== false;
    }
}

?>
