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

        // Skipped when already present -- see Update::addColumnIfMissing().
        if ( ! $this->addColumnIfMissing( $entity, 'property_type' ) ) {

            $this->e->notice( 'Adding property_type to owa_property failed' );

            return false;
        }

        return true;
    }

    /**
     * Take property_type back off the Property.
     *
     * Idempotent. Every Property reads as a website again, which is what falsy
     * already means -- see Property::getPropertyType() -- so nothing downstream
     * changes behaviour beyond losing the ability to say otherwise.
     */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        if ( ! $this->dropColumnIfPresent( $entity, 'property_type' ) ) {

            $this->e->notice( 'Dropping property_type from owa_property failed' );

            return false;
        }

        return true;
    }
}
