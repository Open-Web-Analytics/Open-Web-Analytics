<?php

namespace OWA\Module\Base\Update;

/**
 * A Property says what kind of thing it is.
 *
 * The Profile already answers this -- a Profile observes a website or an app,
 * which decides whether it needs a domain or a bundle id. But the DOMAIN lives
 * on the Property, because the domain describes the thing being measured rather
 * than one way of watching it. So the Property is where "this is a website" has
 * to be said, and until now it was only implied by whatever its Profiles
 * happened to be.
 *
 * It is what makes the domain requirable. A web Property with no domain cannot
 * have its origin checked, which is what a tracking request is accepted or
 * refused on -- so the field was optional and quietly load-bearing.
 *
 * Nothing is backfilled. Falsy means web -- see Property::getPropertyType() --
 * and every Property that existed before types did is a website: they were
 * minted by the migration from sites that had domains.
 */
class Update027 extends \OWA\Core\Update {

    var $schema_version = 27;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $db = \OWA\Core\CoreAPI::dbSingleton();

        /*
         * Skipped when it is already there, rather than treated as a failure.
         *
         * addColumn() answers false both for "could not" and for "already
         * exists", and a migration that stops on the second never completes --
         * so the schema version is never written and every subsequent request
         * refuses with "OWA Updates required" while the database is in fact
         * correct. Measured on this install, on Update026.
         *
         * Interpolated, not bound: SHOW COLUMNS takes no parameters, and both
         * values are hardcoded -- the table from the entity, the column from
         * the literal below. Nothing from a request reaches this.
         */
        $existing = (array) $db->get_results( sprintf(
            "SHOW COLUMNS FROM %s LIKE '%s'",
            $entity->getTableName(),
            'property_type' ) );

        if ( $existing ) {

            return true;
        }

        if ( $entity->addColumn( 'property_type' ) === false ) {

            $this->e->notice( 'Adding property_type to owa_property failed' );

            return false;
        }

        return true;
    }

    function down() {

        return true;
    }
}
