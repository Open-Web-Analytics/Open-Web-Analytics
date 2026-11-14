<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * log_robots gives way to spam_threshold.
 *
 * log_robots on meant "store robots too". Robot detection is now the
 * user_agent rule set of spam detection, and the only way to store what it
 * scores is for nothing to be blocked: a blank spam_threshold. So an install
 * row of log_robots that is true becomes an install row of spam_threshold
 * holding '' (blank), and a notice says so. False, or no row, needs nothing:
 * the default threshold blocks what log_robots off dropped. Then every
 * log_robots row is deleted, since nothing reads it.
 *
 * THE INVERSE. Before anything changes, the removed rows, every column, are
 * kept in the log_robots_before_084 setting, with the install row of
 * spam_threshold as it was before up() wrote it (null when there was none).
 * down() puts the rows back, puts that row back or removes it if up() created
 * it, and removes the record. A run of up() that finds no log_robots row
 * changes nothing, so a second run leaves the record of the first; down() with
 * no record changes nothing.
 *
 * RAW SQL on owa_setting, as Update080: the settings entity cache could
 * otherwise answer for a row this has just changed, and a test can point the
 * whole update at a scratch table. Neither direction touches the object cache;
 * `php cli.php cmd=flush-cache` clears it.
 *
 * A log_robots set in owa-config.php is not a row, and is not read any more.
 *
 * NOT CLI-ONLY. A handful of rows.
 */
class Update084 extends \OWA\Core\Update {

    var $schema_version = 84;

    var $is_cli_mode_required = false;

    /** The setting this retires. */
    const OLD = 'log_robots';

    /** The setting that replaces it. */
    const NAME = 'spam_threshold';

    /** What up() did: { rows: id => row, threshold?: row|null }. */
    const BEFORE = 'log_robots_before_084';

    /** Settings::INSTALL_SCOPE_ID, spelled out as Update043 does. */
    const INSTALL_SCOPE_ID = '1';

    /** @var string|null the settings table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->getTableName();
    }

    function up( $force = false ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table();

        $rows = (array) $db->get_results( sprintf( "SELECT * FROM %s WHERE module = 'base' AND name = ?", $table ),
            array( self::OLD ) );

        if ( ! $rows ) {

            return true;
        }

        $record = $this->record();
        $record['rows'] = isset( $record['rows'] ) && is_array( $record['rows'] ) ? $record['rows'] : array();

        $on = false;

        foreach ( $rows as $row ) {

            $row = (array) $row;

            // The first run's copy is the one down() restores.
            if ( ! isset( $record['rows'][ (string) $row['id'] ] ) ) {

                $record['rows'][ (string) $row['id'] ] = $row;
            }

            if ( $row['scope_type'] === 'install' && (string) $row['scope_id'] === self::INSTALL_SCOPE_ID ) {

                $value = @unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );
                $on    = is_scalar( $value ) && filter_var( $value, FILTER_VALIDATE_BOOLEAN );
            }
        }

        if ( $on && ! array_key_exists( 'threshold', $record ) ) {

            $record['threshold'] = $this->row( self::NAME );
        }

        // Kept before anything changes, so a run that stops part way can still be undone.
        if ( ! $this->writeInstallRow( self::BEFORE, serialize( $record ), 0 ) ) {

            $this->e->notice( sprintf( 'Keeping the %s rows in %s failed.', self::OLD, self::BEFORE ) );

            return false;
        }

        if ( $on ) {

            if ( ! $this->writeInstallRow( self::NAME, serialize( '' ), 1 ) ) {

                $this->e->notice( sprintf( 'Writing %s failed.', self::NAME ) );

                return false;
            }

            $this->e->notice( sprintf( '%s was on, so %s is blank: no beacon is blocked as spam. Set a threshold '
                . 'in Settings > General to block them.', self::OLD, self::NAME ) );
        }

        if ( $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name = ?", $table ),
                array( self::OLD ) ) === false ) {

            $this->e->notice( sprintf( 'Removing the %s rows failed.', self::OLD ) );

            return false;
        }

        $this->e->notice( sprintf( '%s: %d %s removed; %s replaces it.', self::OLD, count( $rows ), \OWA\Core\Lib::plural( count( $rows ), 'row', 'rows' ), self::NAME ) );

        return true;
    }

    /** The exact inverse, from the record up() kept. */
    function down() {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $table  = $this->table();
        $record = $this->record();

        if ( ! $record ) {

            return true;
        }

        foreach ( (array) ( $record['rows'] ?? array() ) as $row ) {

            if ( ! $this->restoreRow( (array) $row ) ) {

                $this->e->notice( sprintf( 'Restoring %s row %s failed.', self::OLD, $row['id'] ?? '' ) );

                return false;
            }
        }

        if ( array_key_exists( 'threshold', $record ) ) {

            $restored = $record['threshold'] === null
                ? $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $table ), array( $this->installId( self::NAME ) ) ) !== false
                : $this->restoreRow( (array) $record['threshold'] );

            if ( ! $restored ) {

                $this->e->notice( sprintf( 'Restoring the install row of %s failed.', self::NAME ) );

                return false;
            }
        }

        if ( $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $table ), array( $this->installId( self::BEFORE ) ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s failed.', self::BEFORE ) );

            return false;
        }

        return true;
    }

    /** What up() kept, or an empty array. */
    private function record() {

        $row = $this->row( self::BEFORE );

        if ( ! $row ) {

            return array();
        }

        $record = @unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );

        return is_array( $record ) ? $record : array();
    }

    /** An install row of base.<name>, every column, or null. */
    private function row( $name ) {

        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SELECT * FROM %s WHERE id = ?', $this->table() ),
            array( $this->installId( $name ) ) );

        return $row ? (array) $row : null;
    }

    private function installId( $name ) {

        return (string) \OWA\Core\CoreAPI::entityFactory( 'base.setting' )->makeId(
            'install', self::INSTALL_SCOPE_ID, 'base', $name );
    }

    /** Upsert an install row of base.<name> holding this serialized value. */
    private function writeInstallRow( $name, $value, $autoload ) {

        return \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)'
          . " VALUES (?, 'install', ?, 'base', ?, ?, ?, ?) ON DUPLICATE KEY UPDATE value = ?", $this->table() ),
            array( $this->installId( $name ), self::INSTALL_SCOPE_ID, $name, $value, (int) $autoload, time(), $value ) ) !== false;
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
