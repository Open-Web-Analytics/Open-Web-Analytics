<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Delete the stored rows of 1.x settings that no module declares and nothing
 * reads.
 *
 * 1.x stored these in owa_configuration, and Update043 copied every key there
 * into an install row, so a migrated install can still hold them. Undeclared,
 * a stored value is ignored and Settings::declarationProblems() names each one.
 *
 *   is_embedded_admin_user_password_reset  set by 1.x's password screen on an
 *                                          embedded install
 *   resolve_hosts                          the reverse-DNS lookup (Update074)
 *   fetch_refering_page_info               the referring page crawler
 *
 * Every scope: a row of one of these names is dead wherever it is.
 *
 * THE INVERSE. Before anything is deleted, the rows, every column, are kept in
 * the retired_settings_before_085 setting. down() puts them back and removes
 * the record. A run of up() that finds no row changes nothing, so a second run
 * leaves the record of the first; down() with no record changes nothing.
 *
 * RAW SQL on owa_setting, as Update084: the settings entity cache could
 * otherwise answer for a row this has just changed, and a test can point the
 * whole update at a scratch table. Neither direction touches the object cache,
 * which lasts only for the request.
 *
 * NOT CLI-ONLY. A handful of rows.
 */
class Update085 extends \OWA\Core\Update {

    use SettingRows;

    public $schema_version = 85;

    /** The base settings this deletes. */
    const RETIRED = array( 'is_embedded_admin_user_password_reset', 'resolve_hosts', 'fetch_refering_page_info' );

    /** What up() deleted: { rows: id => row }. */
    const BEFORE = 'retired_settings_before_085';

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

            $this->e->notice( sprintf( 'Keeping the retired settings in %s failed.', self::BEFORE ) );

            return false;
        }

        if ( $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name IN ( %s )",
                $table, self::placeholders() ), self::RETIRED ) === false ) {

            $this->e->notice( 'Removing the retired settings failed.' );

            return false;
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

        foreach ( (array) ( $record['rows'] ?? array() ) as $row ) {

            if ( ! $this->restoreRow( (array) $row ) ) {

                $this->e->notice( sprintf( 'Restoring setting row %s failed.', $row['id'] ?? '' ) );

                return false;
            }
        }

        if ( ! $this->deleteRecord() ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }
}

?>
