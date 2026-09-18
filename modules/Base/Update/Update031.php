<?php

namespace OWA\Module\Base\Update;

/**
 * Stored "(not set)" becomes NULL, so absence is recorded as absence.
 *
 * The write paths that produced the string have stopped, and an absent dimension
 * is labelled "(not set)" at render time by
 * ResultSetManager::formatDimensionValue(). This clears what earlier versions
 * already wrote, so no installation is left holding both representations of the
 * same thing in one column.
 *
 * That last part is the whole reason this is an Update rather than a command an
 * operator opts into. With the writers stopped, a site that upgrades and never
 * runs a cleanup accumulates new rows holding NULL beside old rows holding the
 * string -- and a GROUP BY then draws two buckets that both mean "unknown" and
 * both render as "(not set)". Running automatically is what keeps any column
 * from carrying both.
 *
 * SET-BASED, one statement per column. RepairGeoEncodingCli writes row by row
 * because each row gets a different repaired value; here the new value is always
 * NULL, so the whole column is one UPDATE. Measured at 218 rows in 9.3ms, and
 * the largest column on either live install is 33,632 of 54,017 rows -- about a
 * second. The tables are dimensions, not facts.
 *
 * ONLY THE COLUMNS A REPORT CAN REACH. Every geo dimension resolves through
 * owa_location_dim and referralPageTitle through owa_referer. owa_session,
 * owa_host and owa_visitor hold the same string in around 600,000 more values
 * that no registered dimension reads; rewriting those would be a large write for
 * no visible change, and they want deleting rather than editing.
 *
 * The custom-variable columns on owa_commerce_line_item_fact hold it too, from a
 * writer this change does not touch -- those properties are declared `required`
 * with the string as their default, so every event writes it. They are uniform
 * rather than mixed, so nothing in a report is currently wrong about them, and
 * they wait for v2.
 */
class Update031 extends \OWA\Core\Update {

    var $schema_version = 31;

    var $is_cli_mode_required = false;

    /**
     * table => the columns a registered dimension resolves through.
     *
     * Matched exactly. A city genuinely named something similar is not this, and
     * a LIKE would be a guess about somebody's data.
     */
    const TARGETS = array(
        'owa_location_dim' => array( 'city', 'country', 'state' ),
        'owa_referer'      => array( 'page_title' ),
    );

    const SENTINEL = '(not set)';

    function up( $force = false ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( self::TARGETS as $table => $columns ) {

            foreach ( $columns as $column ) {

                /*
                 * Idempotent: the predicate stops matching once the column is
                 * cleared, so a second run updates nothing rather than failing.
                 * $table and $column come from the constant above and never from
                 * input; the value is bound.
                 */
                $ret = $db->query(
                    sprintf( 'UPDATE %s SET %s = NULL WHERE %s = ?', $table, $column, $column ),
                    array( self::SENTINEL ) );

                if ( $ret === false ) {

                    $this->e->notice( sprintf(
                        'Clearing "%s" from %s.%s failed', self::SENTINEL, $table, $column ) );

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Deliberately not reversible, and deliberately not attempted.
     *
     * The inverse would be "set these columns back to the string", and there is
     * no these: a NULL this update wrote is indistinguishable from one written
     * by the current code, which no longer stores the string at all. Restoring
     * the string everywhere would invent values for rows that never held one,
     * which is worse than leaving it alone.
     *
     * Nothing is lost by not reversing. The information the string carried --
     * "this field has no value" -- is exactly what NULL carries, and the label
     * is still what a report displays.
     */
    function down() {

        return true;
    }
}

?>
