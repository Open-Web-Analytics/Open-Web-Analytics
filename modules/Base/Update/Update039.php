<?php

namespace OWA\Module\Base\Update;

/**
 * acq_campaign and acq_ad become nullable.
 *
 * They were declared NOT NULL with the other acquisition columns, on the rule
 * that a resolution always produces a value or the sentinel. But only
 * acq_source and acq_medium resolve: they are classified from the stored
 * referring host, and a visit with no referrer is `direct` rather than nothing.
 * A campaign and an ad are TRANSCRIBED as collected, and most first visits
 * carry neither -- an untagged visitor has a store row with NULL in both.
 *
 * So one untagged visitor failed the build for a whole partition:
 *
 *   Column 'acq_campaign' cannot be null
 *
 * and the partition stayed at whatever the last good build left, silently,
 * because a failed build does not swap.
 *
 * It also asked Cube\CopyStep for something it deliberately does not do. That
 * step writes the sentinel where the store has NO ROW, which is a different
 * statement from a row holding NULL in one column -- and the second statement
 * needs somewhere to live. It lives in NULL, the same way acq_search_terms
 * already did.
 *
 * Widening NOT NULL to NULL keeps every existing value, and owa_event is
 * rebuildable from owa_event_raw regardless.
 *
 * NOT CLI-ONLY. MODIFY COLUMN on a nullability widening is an in-place
 * operation, and the cube holds only what a build put there.
 */
class Update039 extends \OWA\Core\Update {

    var $schema_version = 39;

    var $is_cli_mode_required = false;

    /**
     * The columns this update makes nullable, and their definition.
     *
     * @return array column => type
     */
    private function columns() {

        return array(
            'acq_campaign' => OWA_DTD_VARCHAR255,
            'acq_ad'       => OWA_DTD_VARCHAR255,
        );
    }

    function up( $force = false ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            foreach ( $this->columns() as $column => $type ) {

                if ( $db->modifyColumn( $table, $column, $type . ' NULL' ) === false ) {

                    $this->e->notice( sprintf(
                        'Making %s.%s nullable failed', $table, $column ) );

                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Back to NOT NULL.
     *
     * The exact inverse, and safe only because it is: a row written while this
     * update was applied may hold NULL in either column, so the rollback fills
     * them with the unresolved sentinel first. Refusing instead would leave an
     * installation unable to roll back at all, and the sentinel is what the
     * column held for an absent visitor before this.
     */
    function down() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::allTables() as $table ) {

            foreach ( $this->columns() as $column => $type ) {

                $db->query( sprintf(
                    "UPDATE %s SET %s = '%s' WHERE %s IS NULL",
                    $table, $column,
                    $db->prepare( \OWA\Module\Base\Classes\V2Event::UNRESOLVED ),
                    $column ) );

                if ( $db->modifyColumn( $table, $column, $type . ' NOT NULL' ) === false ) {

                    $this->e->notice( sprintf(
                        'Making %s.%s NOT NULL failed', $table, $column ) );

                    return false;
                }
            }
        }

        return true;
    }
}

?>
