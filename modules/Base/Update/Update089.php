<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * owa_user.api_key is dropped. The legacy API key signs nothing in (a request
 * carrying one is refused, see Auth::carriesLegacyApiKey()) and no user is
 * given one.
 *
 * THE INVERSE. Before the column is dropped, every user's id and api_key are
 * copied into a table of this update's own, <user table>_api_key_before_089,
 * whose comment holds the column's definition as SHOW CREATE TABLE gave it and
 * the column it followed. down() adds the column back with that definition in
 * that place, writes each kept value back to its user, and drops the table.
 *
 * Idempotent: with no api_key column up() changes nothing. A run that stopped
 * after the copy keeps the first copy's values (INSERT IGNORE). down() with no
 * kept table changes nothing.
 *
 * NOT CLI-ONLY. One small table.
 */
class Update089 extends \OWA\Core\Update {

    var $schema_version = 89;

    var $is_cli_mode_required = false;

    const COLUMN = 'api_key';

    const BACKUP_SUFFIX = '_api_key_before_089';

    /** @var string|null the user table to use instead of the install's. TESTS ONLY. */
    public $table = null;

    private function table() {

        return $this->table ?: \OWA\Core\CoreAPI::entityFactory( 'base.user' )->getTableName();
    }

    /** The table up() keeps the values in. */
    public function backupTable() {

        return $this->table() . self::BACKUP_SUFFIX;
    }

    /** The user table's columns, in order. @return string[] */
    private function columns() {

        $names = array();

        foreach ( (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf( 'SHOW COLUMNS FROM %s', $this->table() ) ) as $row ) {

            $names[] = (string) ( (array) $row )['Field'];
        }

        return $names;
    }

    /** The column's definition, as SHOW CREATE TABLE gives it, or ''. */
    private function definition() {

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row( sprintf( 'SHOW CREATE TABLE %s', $this->table() ) );
        $sql = (string) ( $row['Create Table'] ?? '' );

        return preg_match( '/^\s*(`' . self::COLUMN . '`.*?),?$/m', $sql, $m ) ? $m[1] : '';
    }

    function up( $force = false ) {

        $columns  = $this->columns();
        $position = array_search( self::COLUMN, $columns, true );

        if ( $position === false ) {

            return true;
        }

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $backup = $this->backupTable();

        if ( ! $db->tableExists( $backup ) ) {

            $definition = $this->definition();

            if ( $definition === '' ) {

                $this->e->notice( sprintf( 'Reading the definition of %s.%s failed.', $this->table(), self::COLUMN ) );

                return false;
            }

            $comment = json_encode( array(
                'definition' => $definition,
                'after'      => $position > 0 ? $columns[ $position - 1 ] : '',
            ) );

            if ( $db->query( sprintf( "CREATE TABLE %s ( id BIGINT NOT NULL, %s VARCHAR(255) NULL, PRIMARY KEY (id) ) %s COMMENT = '%s'",
                    $backup, self::COLUMN, $db->tableOptions(), $db->prepare( $comment ) ) ) === false ) {

                $this->e->notice( sprintf( 'Creating %s failed.', $backup ) );

                return false;
            }
        }

        // Kept before the column goes, so a run that stops part way can still be undone.
        if ( $db->query( sprintf( 'INSERT IGNORE INTO %s ( id, %s ) SELECT id, %s FROM %s',
                $backup, self::COLUMN, self::COLUMN, $this->table() ) ) === false ) {

            $this->e->notice( sprintf( 'Keeping %s.%s in %s failed.', $this->table(), self::COLUMN, $backup ) );

            return false;
        }

        if ( $db->query( sprintf( 'ALTER TABLE %s DROP COLUMN %s', $this->table(), self::COLUMN ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s from %s failed.', self::COLUMN, $this->table() ) );

            return false;
        }

        return true;
    }

    /** The exact inverse, from the table up() kept. */
    function down() {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $backup = $this->backupTable();

        if ( ! $db->tableExists( $backup ) ) {

            return true;
        }

        $kept = (array) json_decode( $this->backupComment(), true );

        if ( empty( $kept['definition'] ) || ! is_string( $kept['definition'] )
             || strpos( $kept['definition'], '`' . self::COLUMN . '` ' ) !== 0 ) {

            $this->e->notice( sprintf( 'The column definition kept in %s cannot be read.', $backup ) );

            return false;
        }

        $columns = $this->columns();

        if ( ! in_array( self::COLUMN, $columns, true ) ) {

            $after = (string) ( $kept['after'] ?? '' );

            if ( $after === '' ) {

                $place = ' FIRST';

            } elseif ( in_array( $after, $columns, true ) ) {

                $place = ' AFTER `' . $after . '`';

            } else {

                $place = '';
            }

            if ( $db->query( sprintf( 'ALTER TABLE %s ADD COLUMN %s%s', $this->table(), $kept['definition'], $place ) ) === false ) {

                $this->e->notice( sprintf( 'Adding %s to %s failed.', self::COLUMN, $this->table() ) );

                return false;
            }
        }

        if ( $db->query( sprintf( 'UPDATE %s u JOIN %s b ON b.id = u.id SET u.%s = b.%s',
                $this->table(), $backup, self::COLUMN, self::COLUMN ) ) === false ) {

            $this->e->notice( sprintf( 'Restoring %s.%s failed.', $this->table(), self::COLUMN ) );

            return false;
        }

        if ( $db->query( sprintf( 'DROP TABLE %s', $backup ) ) === false ) {

            $this->e->notice( sprintf( 'Removing %s failed.', $backup ) );

            return false;
        }

        return true;
    }

    /** The comment up() wrote on the kept table. */
    private function backupComment() {

        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT TABLE_COMMENT AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            array( $this->backupTable() ) );

        return (string) ( $row['c'] ?? '' );
    }
}

?>
