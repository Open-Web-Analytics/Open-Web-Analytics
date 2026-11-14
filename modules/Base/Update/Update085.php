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
 * whole update at a scratch table. Neither direction touches the object cache;
 * `php cli.php cmd=flush-cache` clears it.
 *
 * NOT CLI-ONLY. A handful of rows.
 */
class Update085 extends \OWA\Core\Update {

    var $schema_version = 85;

    var $is_cli_mode_required = false;

    /** The base settings this deletes. */
    const RETIRED = array( 'is_embedded_admin_user_password_reset', 'resolve_hosts', 'fetch_refering_page_info' );

    /** What up() deleted: { rows: id => row }. */
    const BEFORE = 'retired_settings_before_085';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /** @var string|null the settings table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName();
    }

    private static function placeholders() {

        return implode( ', ', array_fill( 0, count( self::RETIRED ), '?' ) );
    }

    function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table();

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
    function down() {

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
