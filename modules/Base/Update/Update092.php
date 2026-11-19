<?php
namespace OWA\Module\Base\Update;

/**
 * Delete the stored rows of three base settings nothing reads, which are no
 * longer declared.
 *
 *   goals                     1.x's numbered goal slots, one map per Profile.
 *                             Update025 turned them into goal event definitions,
 *                             and Update061 and Update081 read the Profile rows
 *                             for the funnels and values inside them.
 *   modules                   a list nothing reads.
 *   db_force_new_connections  passed to the database driver as
 *                             open_new_connection, which no driver reads.
 *
 * THE INVERSE. Before anything is deleted, the rows, every column, are kept in
 * the retired_goals_before_092 setting. down() puts them back and removes the
 * record, so a rollback of Update081 or Update061 after this one finds the rows
 * it reads. A run of up() that finds no row changes nothing.
 *
 * RAW SQL on owa_setting, as Update085.
 *
 * NOT CLI-ONLY. One goals row per Profile at most.
 */
class Update092 extends \OWA\Core\Update {

    use SettingRows;

    public $schema_version = 92;

    /** The base settings this deletes. */
    const RETIRED = [ 'goals', 'modules', 'db_force_new_connections' ];

    /** What up() deleted: { rows: id => row }. */
    const BEFORE = 'retired_goals_before_092';

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

        $this->e->notice( sprintf( 'Retired settings: %d %s removed.', count( $rows ), \OWA\Core\Lib::plural( count( $rows ), 'row', 'rows' ) ) );

        return true;
    }

    /** The exact inverse, from the record up() kept. */
    public function down() {

        $record = $this->record();

        if ( ! $record ) {

            return true;
        }

        foreach ( (array) ( $record['rows'] ?? [] ) as $row ) {

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
