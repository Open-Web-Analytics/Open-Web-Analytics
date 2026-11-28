<?php
namespace OWA\Module\Base\Update;

/**
 * Delete the stored rows of the removed base.ns setting, at every scope.
 *
 *   ns    the prefix on OWA's cookie names and on the URL parameters it reads
 *         from tracked pages. It is CoreAPI::NS, owa_, the setting's default.
 *         A config that still defines OWA_NS gets the retired constant's
 *         notice (Core\Requirements::RETIRED_CONSTANTS).
 *
 * THE INVERSE. Before anything is deleted, the rows, every column, are kept in
 * the retired_settings_before_100 setting. down() puts them back and removes
 * the record. A run of up() that finds no row changes nothing, so a second run
 * leaves the record of the first; down() with no record changes nothing.
 *
 * RAW SQL on owa_setting, as Update092. The install rows are autoloaded, so the
 * settings this process saves are kept in step, as Update091 does.
 *
 * NOT CLI-ONLY. A few rows at most.
 */
class Update100 extends \OWA\Core\Update {

    use SettingRows;

    public $schema_version = 100;

    /** The base settings this deletes. */
    const RETIRED = [ 'ns' ];

    /** What up() deleted: { rows: id => row }. */
    const BEFORE = 'retired_settings_before_100';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /**
     * @return string
     */
    private static function placeholders() {

        return implode( ', ', array_fill( 0, count( self::RETIRED ), '?' ) );
    }

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->settingTable();

        $rows = (array) $db->get_results( sprintf( "SELECT * FROM %s WHERE module = 'base' AND name IN ( %s )",
            $table, self::placeholders() ), self::RETIRED );

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

            $this->e->notice( sprintf( 'Keeping the retired settings in %s failed.', self::BEFORE ) );

            return false;
        }

        if ( $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name IN ( %s )",
                $table, self::placeholders() ), self::RETIRED ) === false ) {

            $this->e->notice( 'Removing the retired settings failed.' );

            return false;
        }

        $settings = $this->settings();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            if ( $settings && $row['scope_type'] === 'install' ) {

                $settings->removeSetting( $row['module'], $row['name'] );
            }
        }

        $this->e->notice( sprintf( 'Retired settings: %d %s removed.', count( $rows ), \OWA\Core\Lib::plural( count( $rows ), 'row', 'rows' ) ) );

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
