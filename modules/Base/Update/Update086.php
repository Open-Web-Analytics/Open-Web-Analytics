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

    use SettingRows;

    public $schema_version = 86;

    /** The modules whose every row this deletes. */
    const MODULES = [ 'fileCache', 'memcachedCache' ];

    /** The base settings this deletes. */
    const RETIRED = [ 'cache_objects', 'cacheType', 'cache_dir', 'memcachedServers',
        'memcachedPersistantConnections', 'default_cache_expiration_period' ];

    /** What up() deleted: { rows: id => row }. */
    const BEFORE = 'cache_settings_before_086';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /**
     * The rows this deletes, as a WHERE clause, and its parameters.
     *
     * @return array{string, list<string>}
     */
    private static function where() {

        $in = function ( array $values ) {
            return implode( ', ', array_fill( 0, count( $values ), '?' ) );
        };

        return [
            sprintf( "module IN ( %s ) OR ( module = 'base' AND name IN ( %s ) )", $in( self::MODULES ), $in( self::RETIRED ) ),
            array_merge( self::MODULES, self::RETIRED ),
        ];
    }

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->settingTable();

        list( $where, $params ) = self::where();

        $rows = (array) $db->get_results( sprintf( 'SELECT * FROM %s WHERE %s', $table, $where ), $params );

        if ( ! $rows ) {

            return true;
        }

        $record = $this->record();
        $record['rows'] = isset( $record['rows'] ) && is_array( $record['rows'] ) ? $record['rows'] : [];

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
    public function down() {

        $record = $this->record();

        if ( ! $record ) {

            return true;
        }

        $settings = $this->settings();

        foreach ( (array) ( $record['rows'] ?? [] ) as $row ) {

            if ( ! $this->restoreRow( (array) $row ) ) {

                $this->e->notice( sprintf( 'Restoring setting row %s failed.', $row['id'] ?? '' ) );

                return false;
            }

            if ( $settings && $row['scope_type'] === 'install' ) {

                $settings->db_settings[ $row['module'] ][ $row['name'] ] =
                    @unserialize( (string) $row['value'], [ 'allowed_classes' => false ] );
            }
        }

        if ( ! $this->deleteRecord() ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }
}
