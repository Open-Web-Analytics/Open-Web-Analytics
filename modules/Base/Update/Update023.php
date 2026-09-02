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

            if ( $entity->addColumn( 'archived_date' ) === false ) {

                $this->e->notice( "Adding archived_date to $table failed" );

                return false;
            }
        }

        return true;
    }

    function down() {

        return true;
    }
}
