<?php
namespace OWA\Module\Base\Update;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Remove indexes that duplicate another index on the same table, exactly.
 *
 * addIndex() issued an unnamed statement -- 'ALTER TABLE x ADD INDEX (col)' --
 * so MySQL assigned the name and a repeated call produced site_id, site_id_2,
 * site_id_3 rather than failing as a duplicate. It was reachable on an ordinary
 * upgrade, not only by re-running: Update005 indexes yyyymmdd on four tables and
 * Update007 indexes site_id/session_id on the same set plus more yyyymmdd.
 *
 * The copies are exact, so they can never be chosen over the original -- they
 * only take space and are written on every INSERT, which on a tracker is the
 * hot path. One observed installation carried 33 redundant copies totalling
 * ~196MB, on tables whose indexes are hit by every logged event.
 *
 * addIndex() no longer creates them, so this is a one-off repair of the installs
 * that already have them.
 *
 * What it will not do:
 *  - touch PRIMARY, or any table outside the 'owa_' prefix, since an install may
 *    share its database with another application
 *  - remove the last index over a given column list; one always survives
 *  - treat a unique and a non-unique index over the same columns as copies
 *  - rename what survives. Renaming means rebuilding the index, which on a large
 *    fact table is expensive and buys nothing.
 */
class Update013 extends \OWA\Core\Update {

    var $schema_version = 13;

    function up($force = true) {

        $result = self::repair( \OWA\Core\CoreAPI::dbSingleton() );

        if ( ! $result['dropped'] && ! $result['failed'] ) {

            \OWA\Core\CoreAPI::notice( 'No duplicate indexes found.' );

            return true;
        }

        foreach ( $result['dropped'] as $line ) {

            \OWA\Core\CoreAPI::notice( $line );
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Dropped %d duplicate index(es).', count( $result['dropped'] ) ) );

        if ( $result['failed'] ) {

            // Report and carry on. A duplicate left in place costs space, which
            // is not a reason to fail the upgrade and strand the schema version.
            \OWA\Core\CoreAPI::notice( sprintf(
                'Could not drop %d index(es): %s. They are duplicates and can be removed by hand.',
                count( $result['failed'] ), implode( ', ', $result['failed'] )
            ) );
        }

        return true;
    }

    /**
     * Not reversible, deliberately.
     *
     * Reversing it means recreating indexes that were exact copies of ones the
     * table still has. They could never be chosen over the original, so putting
     * them back would restore the cost -- space, and a write on every INSERT --
     * without restoring any capability. Nothing was lost that could be wanted
     * back, so this reports and succeeds rather than failing the rollback.
     */
    function down() {

        \OWA\Core\CoreAPI::notice(
            'Update013 is not reversible: it only removed indexes that duplicated '
            . 'another index on the same table, which changes no query behaviour.'
        );

        return true;
    }

    /**
     * Drop every duplicate, keeping one index per column list.
     *
     * Separated from up() so it can be exercised against a single table. The
     * update itself passes no filter and therefore covers the whole schema.
     *
     * @param object $db
     * @param string|null $only_table  restrict to one table; null means all
     * @return array ['dropped' => string[], 'failed' => string[]]
     */
    public static function repair( $db, $only_table = null ) {

        $dropped = array();
        $failed  = array();

        foreach ( $db->getDuplicateIndexes( $only_table ) as $dupe ) {

            // dropIndex() takes the index name, which came from
            // information_schema rather than from user input.
            if ( $db->dropIndex( $dupe['t'], $dupe['i'] ) ) {

                $dropped[] = sprintf(
                    'Dropped duplicate index %s on %s (%s); %s covers the same columns.',
                    $dupe['i'], $dupe['t'], $dupe['cols'], $dupe['keeping']
                );

            } else {

                $failed[] = $dupe['t'] . '.' . $dupe['i'];
            }
        }

        return array( 'dropped' => $dropped, 'failed' => $failed );
    }
}
