<?php

namespace OWA\Module\Base\Update;

/**
 * An Observation Profile says what KIND of thing it observes.
 *
 * The domain was required to create a Profile, and the reason was 2009: a
 * site's identity was md5( domain ), so the domain was primary-key material.
 * Identity is minted now, and the rule had outlived its reason into preventing
 * two things the hierarchy exists to allow -- a second Profile for a website
 * you already track, and a Property with no domain (an app) ever being observed
 * at all.
 *
 * Moving the domain up to the Property was the obvious fix and the wrong one.
 * GA4 answers the same question by typing the LEAF: a property carries no URL,
 * and each data stream declares web / Android / iOS and supplies whatever that
 * kind needs. Universal Analytics did put a website URL on the property, and
 * Google moved it down when a property stopped being able to assume it was a
 * website. A property holding a site and its apps has no single domain to put
 * there.
 *
 * So the type goes on the Profile, and the type decides which identifier is
 * required: a web Profile needs a domain, an app Profile needs a bundle id or
 * package name.
 *
 * Nothing is backfilled. Every existing Profile is a website, which is what a
 * NULL stream_type already means -- see Site::getStreamType(), where falsy
 * reads as web.
 */
class Update024 extends \OWA\Core\Update {

    var $schema_version = 24;

    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        foreach ( array( 'stream_type', 'app_id' ) as $column ) {

            if ( $entity->addColumn( $column ) === false ) {

                $this->e->notice( "Adding $column to owa_site failed" );

                return false;
            }
        }

        return true;
    }

    function down() {

        return true;
    }
}
