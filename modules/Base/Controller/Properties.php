<?php

namespace OWA\Module\Base\Controller;

/**
 * The Property roster.
 *
 * A Property is the website or app; the Observation Profiles beneath it are the
 * ways of watching it, and it is the Profile that carries the tracker id. The
 * roster exists because the migration NAMES properties for you -- from the
 * domain, or from whichever site it saw first -- and without a screen those
 * names are permanent.
 *
 * Profiles are listed under their parent rather than linked away to, because
 * the question this screen answers is "what does this website look like", and
 * a Property with two Profiles is the case the whole hierarchy exists for.
 */
class Properties extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        return parent::__construct( $params );
    }

    function action() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $property->getTableName() );
        $db->selectColumn( '*' );
        $db->orderBy( 'name' );

        $properties = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $row['profiles'] = array();
            $properties[ $row['id'] ] = $row;
        }

        /*
         * Profiles are attached from the site list the current user is allowed
         * to see, not from a second query over every site. An admin sees all of
         * them, so the roster is complete for the only role that can reach it.
         */
        $orphans = array();

        foreach ( (array) $this->getSitesAllowedForCurrentUser() as $site ) {

            $parent = $site->get( 'property_id' );

            if ( $parent && isset( $properties[ $parent ] ) ) {

                $properties[ $parent ]['profiles'][] = $site;

            } else {

                /*
                 * A Profile with no Property is shown, not hidden. One can
                 * exist -- created before the migration, or by a path that
                 * assigns none -- and a roster that omits it would report a
                 * site as absent when it is merely unparented.
                 */
                $orphans[] = $site;
            }
        }

        /*
         * The Organization heads the page rather than getting a screen of its
         * own: there is exactly one, and its only editable field is its name.
         */
        $sm           = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );
        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );
        $organization->load( $sm->ensureOrganization() );

        $this->set( 'organization_name', (string) $organization->get( 'name' ) );
        $this->set( 'properties', $properties );
        $this->set( 'unassigned_profiles', $orphans );
        $this->setView( 'base.options' );
        $this->setSubview( 'base.properties' );
    }
}

?>
