<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Delete the stored rows of the removed Hello example module: every row with
 * module 'hello', at every scope. The module is a page in the wiki
 * (v2-Writing-a-Module) and does not ship.
 *
 * The loader skips a module that is not on disk. The stored is_active and
 * schema_version still made cmd=instance-info list hello as active and up to
 * date, made the CLI log on boot that hello stores settings it does not
 * declare, and cmd=deactivate module=hello could not clear them: it needs the
 * module's class.
 *
 * THE INVERSE. Before anything is deleted, the rows, every column, are kept in
 * the hello_settings_before_091 setting. down() puts them back and removes the
 * record. A run of up() that finds no row changes nothing, so a second run
 * leaves the record of the first; down() with no record changes nothing.
 *
 * RAW SQL on owa_setting, as Update086, so a test can point the whole update at
 * a scratch table.
 *
 * NOT CLI-ONLY. A handful of rows.
 */
class Update091 extends \OWA\Core\Update {

    use SettingRows;

    public $schema_version = 91;

    /** The module whose every row this deletes. */
    const MODULE = 'hello';

    /** What up() deleted: { rows: id => row }. */
    const BEFORE = 'hello_settings_before_091';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->settingTable();

        $rows = (array) $db->get_results( sprintf( 'SELECT * FROM %s WHERE module = ?', $table ), [ self::MODULE ] );

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

            $this->e->notice( sprintf( 'Keeping the Hello module settings in %s failed.', self::BEFORE ) );

            return false;
        }

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE module = ?', $table ), [ self::MODULE ] ) === false ) {

            $this->e->notice( 'Removing the Hello module settings failed.' );

            return false;
        }

        $settings = $this->settings();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            if ( $settings && $row['scope_type'] === 'install' ) {

                $settings->removeSetting( $row['module'], $row['name'] );
            }
        }

        $this->e->notice( sprintf( 'Hello module settings: %d %s removed.', count( $rows ), \OWA\Core\Lib::plural( count( $rows ), 'row', 'rows' ) ) );

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
