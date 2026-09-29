<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * 32-bit ids in the tables v2 keeps, re-keyed to 64-bit (PLAN.html 2.21).
 *
 * 1.14 derived ids from content with crc32 until an administrator ran
 * rederive-dimension-ids, and that command never covered every table: rows
 * written while an installation was still marked use_32bit_hash kept 32-bit
 * ids in owa_setting and owa_notification_state even after it ran. v2 has no
 * such command, so the upgrade does this whichever way the installation
 * arrives: a row is re-keyed when its id is the crc32 of its own content.
 *
 * ONLY WHERE THE ID IS DERIVED AGAIN TO FIND THE ROW. A 32-bit id that is only
 * ever read back as stored -- a property, a goal event, a favourite -- works as
 * it is. Where the code derives the id to load the row, the old one is missed
 * and a duplicate written beside it:
 *
 *   owa_site                site_id; owa_site_user.site_id holds it too
 *   owa_setting             scope_type|scope_id|module|name
 *   owa_notification_state  notification_id . user_id
 *
 * A row already at its 64-bit id wins over a 32-bit copy of it: that is the
 * one the code has been reading since the flag cleared.
 *
 * Raw SQL throughout: owa_site is a cached entity, and a cached object would
 * answer with the id this is changing.
 */
class WideIds {

    /** table => how its id is derived, and the columns elsewhere holding it */
    const TABLES = array(
        'base.site' => array(
            'columns'   => array( 'site_id' ),
            'separator' => '',
            'followers' => array( 'base.site_user' => 'site_id' ),
        ),
        'base.setting' => array(
            'columns'   => array( 'scope_type', 'scope_id', 'module', 'name' ),
            'separator' => '|',
            'followers' => array(),
        ),
        'base.notification_state' => array(
            'columns'   => array( 'notification_id', 'user_id' ),
            'separator' => '',
            'followers' => array(),
        ),
    );

    /** @var string[] what each table had done to it */
    public $report = array();

    /** @var array entity name => table name, where a test re-keys copies */
    public $tables = array();

    /**
     * Re-key every 32-bit row. Whether the installation derives 32-bit ids
     * from now on is the caller's: this reads and writes rows only.
     *
     * @return bool
     */
    public function widen() {

        $this->report = array();

        foreach ( self::TABLES as $entity_name => $shape ) {

            if ( ! $this->rekey( $entity_name, $shape, true ) ) {

                return false;
            }
        }

        return true;
    }

    /**
     * Back to 32-bit, for an installation that arrived still deriving them.
     *
     * Every derived row goes back, including ones written since: 1.14 marked
     * use_32bit_hash derives nothing else.
     *
     * @return bool
     */
    public function narrow() {

        $this->report = array();

        foreach ( self::TABLES as $entity_name => $shape ) {

            if ( ! $this->rekey( $entity_name, $shape, false ) ) {

                return false;
            }
        }

        return true;
    }

    private function tableFor( $entity_name ) {

        return $this->tables[ $entity_name ]
            ?? \OWA\Core\CoreAPI::entityFactory( $entity_name )->getTableName();
    }

    /** The id a row's content derives, 64-bit or 32-bit. */
    public static function derive( $content, $wide ) {

        return $wide
            ? (string) \OWA\Core\Lib::wideStringGuid( $content )
            : (string) crc32( strtolower( $content ) );
    }

    /**
     * Whether $id is the 32-bit id of $content, stored either way round: 1.x
     * wrote crc32 into a signed BIGINT on some paths.
     */
    public static function isNarrow( $id, $content ) {

        $crc = crc32( strtolower( $content ) );

        return (string) $id === (string) $crc || (string) $id === (string) ( $crc - 4294967296 );
    }

    private function rekey( $entity_name, array $shape, $wide ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->tableFor( $entity_name );

        if ( ! $db->tableExists( $table ) ) {

            return true;
        }

        $rows = (array) $db->get_results( sprintf( 'SELECT id, %s FROM %s', implode( ', ', array_map(
            function ( $c ) { return '`' . $c . '`'; }, $shape['columns'] ) ), $table ) );

        $moved = $merged = 0;

        foreach ( $rows as $row ) {

            $row   = (array) $row;
            $parts = array();

            foreach ( $shape['columns'] as $column ) {

                $parts[] = (string) $row[ $column ];
            }

            $content = implode( $shape['separator'], $parts );

            if ( $content === '' ) {

                continue;
            }

            $from = (string) $row['id'];

            if ( $wide ? ! self::isNarrow( $from, $content ) : $from !== self::derive( $content, true ) ) {

                continue;
            }

            $to = self::derive( $content, $wide );

            if ( $to === $from ) {

                continue;
            }

            $taken = (bool) $db->get_row( sprintf( 'SELECT id FROM %s WHERE id = ?', $table ), array( $to ) );

            foreach ( $shape['followers'] as $follower => $column ) {

                $follower_table = $this->tableFor( $follower );

                if ( $db->query( sprintf( 'UPDATE %s SET `%s` = ? WHERE `%s` = ?', $follower_table, $column, $column ),
                        array( $to, $from ) ) === false ) {

                    return $this->failed( $table, $from );
                }
            }

            $ok = $taken
                ? $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', $table ), array( $from ) )
                : $db->query( sprintf( 'UPDATE %s SET id = ? WHERE id = ?', $table ), array( $to, $from ) );

            if ( $ok === false ) {

                return $this->failed( $table, $from );
            }

            $taken ? $merged++ : $moved++;
        }

        if ( $moved || $merged ) {

            $this->report[] = sprintf( '%s: %d row(s) re-keyed to %d-bit ids%s.', $table, $moved, $wide ? 64 : 32,
                $merged ? sprintf( ', %d duplicate(s) of a row already at its new id removed', $merged ) : '' );
        }

        return true;
    }

    private function failed( $table, $id ) {

        $this->report[] = sprintf( '%s: re-keying row %s failed.', $table, $id );

        return false;
    }
}

?>
