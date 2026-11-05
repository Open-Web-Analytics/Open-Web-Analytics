<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * default_page gives way to directory_index_pages.
 *
 * default_page was one file name per Profile, empty unless someone set it, so
 * /store/index.html and /store were two pages on every Profile nobody had
 * configured. directory_index_pages is a list, install-wide and on no screen,
 * whose default names the index files web servers serve
 * (V2Event::DIRECTORY_INDEX_PAGES). page_path, and everything compared with
 * it, folds a last segment named in it.
 *
 * Every stored default_page value, at the install and on each Profile, is
 * read. A name the default list already has needs nothing. Any other is added:
 * the install row of directory_index_pages becomes the list it held, or the
 * default, with the extras after it, and each extra is named in a notice with
 * the Profiles that had it. That row is written only when there is an extra.
 * A value that names a directory as well as a file (`/en/index.html`) cannot be
 * a last segment and is reported and left out. Then the default_page rows are
 * deleted, since nothing reads them.
 *
 * An extra applies to every Profile, not only the one that named it. A file
 * name a web server serves for a directory on one site is the same page
 * wherever it appears.
 *
 * NOT RETROACTIVE. page_path is derived at ingest, so rows already stored keep
 * the path they were stored with; /store/index.html recorded before this stays
 * a row of its own until those days are re-ingested.
 *
 * THE INVERSE. Before anything is deleted, the removed rows, every column, are
 * kept in the default_page_before_080 setting, with the install row of
 * directory_index_pages as it was before up() wrote it (null when there was
 * none). down() puts the rows back, puts that install row back or removes it
 * if up() created it, and removes the record. A run of up() that finds no
 * default_page row changes nothing, so a second run leaves the record of the
 * first; down() with no record changes nothing.
 *
 * RAW SQL on owa_setting, as Update070, record included: the settings entity
 * cache could otherwise answer for a row this has just changed, and a test can
 * point the whole update at a scratch table. Neither direction touches the
 * object cache; `php cli.php cmd=flush-cache` clears it.
 *
 * NOT CLI-ONLY. A handful of rows.
 */
class Update080 extends \OWA\Core\Update {

    var $schema_version = 80;

    var $is_cli_mode_required = false;

    /** The setting this retires. */
    const OLD = 'default_page';

    /** The setting that replaces it. */
    const NAME = 'directory_index_pages';

    /** What up() did: { rows: id => row, index?: row|null }. */
    const BEFORE = 'default_page_before_080';

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

        $extras = array(); // name => who had it
        $list   = array();

        foreach ( $rows as $row ) {

            $row = (array) $row;

            // The first run's copy is the one down() restores.
            if ( ! isset( $record['rows'][ (string) $row['id'] ] ) ) {

                $record['rows'][ (string) $row['id'] ] = $row;
            }

            $value = @unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );
            $name  = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
            $who   = $row['scope_type'] === 'install' ? 'the install' : ucfirst( (string) $row['scope_type'] ) . ' ' . $row['scope_id'];

            if ( $name === '' || in_array( $name, \OWA\Module\Base\Classes\V2Event::DIRECTORY_INDEX_PAGES, true ) ) {

                continue;
            }

            if ( strpos( $name, '/' ) !== false ) {

                $this->e->notice( sprintf( 'default_page "%s" (%s) names a directory as well as a file, so it is'
                    . ' not added to %s.', $name, $who, self::NAME ) );

                continue;
            }

            $extras[ $name ][] = $who;
        }

        // By name, so the list does not depend on the order the rows came back in.
        ksort( $extras, SORT_STRING );

        if ( $extras ) {

            $index = $this->row( self::NAME );

            $list = $index
                ? \OWA\Module\Base\Classes\V2Event::indexPageNames(
                    @unserialize( (string) $index['value'], array( 'allowed_classes' => false ) ) )
                : \OWA\Module\Base\Classes\V2Event::DIRECTORY_INDEX_PAGES;

            // A list the install stored may name one already.
            $extras = array_diff_key( $extras, array_flip( $list ) );

            if ( $extras && ! array_key_exists( 'index', $record ) ) {

                $record['index'] = $index;
            }

            foreach ( array_keys( $extras ) as $name ) {

                $list[] = (string) $name;
            }
        }

        // Kept before anything changes, so a run that stops part way can still be undone.
        if ( ! $this->writeInstallRow( self::BEFORE, serialize( $record ), 0 ) ) {

            $this->e->notice( sprintf( 'Keeping the %s rows in %s failed.', self::OLD, self::BEFORE ) );

            return false;
        }

        if ( $extras ) {

            if ( ! $this->writeInstallRow( self::NAME, serialize( $list ), 1 ) ) {

                $this->e->notice( sprintf( 'Writing %s failed.', self::NAME ) );

                return false;
            }

            foreach ( $extras as $name => $who ) {

                $this->e->notice( sprintf( 'default_page "%s", set on %s, is added to %s.',
                    $name, implode( ', ', array_unique( $who ) ), self::NAME ) );
            }
        }

        if ( $db->query( sprintf( "DELETE FROM %s WHERE module = 'base' AND name = ?", $table ),
                array( self::OLD ) ) === false ) {

            $this->e->notice( sprintf( 'Removing the %s rows failed.', self::OLD ) );

            return false;
        }

        $this->e->notice( sprintf( '%s: %d row(s) removed; %s replaces it.', self::OLD, count( $rows ), self::NAME ) );

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

        if ( array_key_exists( 'index', $record ) ) {

            $restored = $record['index'] === null
                ? $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $table ), array( $this->installId( self::NAME ) ) ) !== false
                : $this->restoreRow( (array) $record['index'] );

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
