<?php
namespace OWA\Module\Base\Update;

/**
 * Two things nothing read go: the prior_session_start_ts column, and the
 * stored rows of the base.geolocation_service setting.
 *
 *   prior_session_start_ts  the previous session's start (`psts`), from
 *                           owa_event_raw and every cube. The tracker no
 *                           longer sends it, and no dimension read it.
 *   geolocation_service     set to maxmind by the MaxMind module so that it
 *                           registered its geolocation filter. The module
 *                           registers it whenever it is active. Deleted at
 *                           every scope.
 *
 * THE INVERSE. Before any row is deleted, the rows, every column, are kept in
 * the retired_settings_before_108 setting; down() puts them back and removes
 * the record, and puts the column back on raw and every cube, empty: its
 * values are not kept anywhere. A run of up() that finds neither changes
 * nothing -- an install whose tables were created from the current entities
 * has no column -- so a second run leaves the record of the first; down()
 * with no record restores no row.
 *
 * NOT CLI-ONLY. Raw drops the column in place (instantly where the server
 * can, MySQL 8.0.29 and later); each cube is rebuilt once (CubeColumn::
 * dropColumns()), which is online. On an upgrade from 1.x raw is empty here
 * and there is no cube yet. The setting is a few rows at most.
 */
class Update108 extends \OWA\Core\Update {

    use CubeColumn;
    use SettingRows;

    public $schema_version = 108;

    public $down_does_not_restore = 'the prior_session_start_ts values: the column comes back empty.';

    /** The raw and cube column this drops. */
    const COLUMN = 'prior_session_start_ts';

    /** The base setting whose rows this deletes. */
    const RETIRED = 'geolocation_service';

    /** What up() deleted: { rows: id => row }. */
    const BEFORE = 'retired_settings_before_108';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        return $this->retireRows() && $this->dropColumns( [ self::COLUMN ] );
    }

    /** The exact inverse, but for the column's values. */
    public function down() {

        if ( ! $this->restoreColumn( self::COLUMN, OWA_DTD_BIGINT ) ) {

            $this->e->notice( sprintf( 'Restoring %s failed.', self::COLUMN ) );

            return false;
        }

        return $this->restoreRows();
    }

    /**
     * Delete every stored row of base.geolocation_service, kept first.
     *
     * @return bool
     */
    private function retireRows() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->settingTable();

        $rows = (array) $db->get_results( sprintf( "SELECT * FROM %s WHERE module = 'base' AND name = ?", $table ),
            [ self::RETIRED ] );

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

            $this->e->notice( sprintf( 'Keeping the %s rows in %s failed.', self::RETIRED, self::BEFORE ) );

            return false;
        }

        if ( $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name = ?", $table ), [ self::RETIRED ] ) === false ) {

            $this->e->notice( sprintf( 'Removing the %s rows failed.', self::RETIRED ) );

            return false;
        }

        $settings = $this->settings();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            if ( $settings && $row['scope_type'] === 'install' ) {

                $settings->removeSetting( $row['module'], $row['name'] );
            }
        }

        $this->e->notice( sprintf( 'Retired setting %s: %d %s removed.', self::RETIRED, count( $rows ),
            \OWA\Core\Lib::plural( count( $rows ), 'row', 'rows' ) ) );

        return true;
    }

    /**
     * Put back every row retireRows() deleted, from its record.
     *
     * @return bool
     */
    private function restoreRows() {

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
