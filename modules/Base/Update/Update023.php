<?php

namespace OWA\Module\Base\Update;

/**
 * Removing a Profile or a Property archives it rather than destroying it.
 *
 * Deleting a site used to delete one row and leave everything hanging off it --
 * its access grants, and (since the scoped settings table) its settings --
 * behind as invisible orphans that a re-minted identifier could inherit. The
 * collected data was stranded too.
 *
 * A nullable date is the whole change. FALSY means live; a timestamp means
 * archived, and says when.
 *
 * Read it as falsy, never as `IS NULL`. The column ends up holding three
 * values -- NULL for a row never archived, a stamp for one that is, and 0 for
 * one that was restored, because setting '' on a numeric column is treated by
 * the entity layer as "no value given" and skipped. empty()/(bool) answers all
 * three correctly; `IS NULL` would classify every restored row as archived.
 *
 * Nothing is backfilled. Every existing row is live, which is what the NULL
 * default already says, so this adds a column and changes no data.
 */
class Update023 extends \OWA\Core\Update {

    var $schema_version = 23;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $targets = array(
            'base.site'     => 'owa_site',
            'base.property' => 'owa_property',
        );

        foreach ( $targets as $entityName => $table ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $entityName );

            /*
             * Skipped when it is already there. Update021 CREATES owa_property
             * from the current entity definition, which already declares
             * archived_date -- so on an install jumping from below 21 the
             * column exists before this runs, and treating that as a failure
             * stopped a live upgrade dead on a correct database.
             */
            if ( ! $this->addColumnIfMissing( $entity, 'archived_date' ) ) {

                $this->e->notice( "Adding archived_date to $table failed" );

                return false;
            }
        }

        return true;
    }

    /**
     * Take archived_date back off both tables.
     *
     * Idempotent, like the up: dropColumnIfPresent() treats an already-absent
     * column as done, so a rollback that got half way through can be finished
     * rather than being stuck needing hand surgery.
     *
     * Archiving state is LOST by this, and that is the honest outcome -- the
     * column is where it lived. Rolling back to a schema with no notion of
     * archiving cannot keep a record of what was archived.
     */
    function down() {

        $targets = array(
            'base.site'     => 'owa_site',
            'base.property' => 'owa_property',
        );

        foreach ( $targets as $entityName => $table ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( $entityName );

            if ( ! $this->dropColumnIfPresent( $entity, 'archived_date' ) ) {

                $this->e->notice( "Dropping archived_date from $table failed" );

                return false;
            }
        }

        return true;
    }
}
