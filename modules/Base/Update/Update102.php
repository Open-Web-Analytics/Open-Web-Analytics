<?php
namespace OWA\Module\Base\Update;

/**
 * The patch level of automatic updates is retired: a stored auto_update_level
 * of patch becomes minor, at every scope. The levels are minor (patch and
 * minor releases) and major (patch, minor and major releases).
 *
 * A config that defines OWA_AUTO_UPDATE_LEVEL as patch is refused at boot
 * (AutoUpdate\ReleaseCheck::levelProblems()).
 *
 * THE INVERSE. Before anything changes, the rows, every column, are kept in
 * the retired_settings_before_102 setting. down() puts them back and removes
 * the record. A run of up() that finds no patch row changes nothing, so a
 * second run leaves the record of the first; down() with no record changes
 * nothing.
 *
 * RAW SQL on owa_setting, as Update092. The install rows are autoloaded, so the
 * settings this process saves are kept in step, as Update084 does.
 *
 * NOT CLI-ONLY. One row at most.
 */
class Update102 extends \OWA\Core\Update {

    use SettingRows;

    public $schema_version = 102;

    /** The base setting this changes. */
    const NAME = 'auto_update_level';

    /** The value it retires, and the one that replaces it. */
    const RETIRED = 'patch';
    const REPLACEMENT = 'minor';

    /** What up() changed: { rows: id => row }. */
    const BEFORE = 'retired_settings_before_102';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /**
     * @param  bool $force
     * @return bool
     */
    public function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->settingTable();

        $rows = (array) $db->get_results( sprintf( "SELECT * FROM %s WHERE module = 'base' AND name = ? AND value = ?", $table ),
            [ self::NAME, serialize( self::RETIRED ) ] );

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

            $this->e->notice( sprintf( 'Keeping the %s rows in %s failed.', self::NAME, self::BEFORE ) );

            return false;
        }

        if ( $db->query( sprintf( "UPDATE %s SET value = ? WHERE module = 'base' AND name = ? AND value = ?", $table ),
                [ serialize( self::REPLACEMENT ), self::NAME, serialize( self::RETIRED ) ] ) === false ) {

            $this->e->notice( sprintf( 'Changing %s from %s to %s failed.', self::NAME, self::RETIRED, self::REPLACEMENT ) );

            return false;
        }

        foreach ( $rows as $row ) {

            $row = (array) $row;

            if ( $row['scope_type'] === 'install' ) {

                $this->hold( self::NAME, self::REPLACEMENT );
            }
        }

        $this->e->notice( sprintf( '%s: %s is no longer a level, so it is %s (patch and minor releases); %d %s changed.',
            self::NAME, self::RETIRED, self::REPLACEMENT, count( $rows ), \OWA\Core\Lib::plural( count( $rows ), 'row', 'rows' ) ) );

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

            if ( $row['scope_type'] === 'install' ) {

                $this->hold( self::NAME, @unserialize( (string) $row['value'], [ 'allowed_classes' => false ] ) );
            }
        }

        if ( ! $this->deleteRecord() ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }
}
