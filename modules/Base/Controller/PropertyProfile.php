<?php

namespace OWA\Module\Base\Controller;

/**
 * The edit form for ONE Property.
 *
 * Reached from the site control's Properties column, which is where the
 * hierarchy is navigated now -- there is no Property roster screen. A roster
 * was a second place to browse the same tree, answering a question people ask
 * while looking at reports, on a screen reached from the admin menu.
 */
class PropertyProfile extends \OWA\Core\AdminController {

    // edit_sites on the Property acted on, not on the request's siteId.
    use \OWA\Core\PropertyScopedAccess;

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_sites' );

        parent::__construct( $params );
    }

    public function action() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        /*
         * No propertyId means ADD. The same form serves both, as the Profile
         * form does -- a separate add screen would duplicate every field and
         * the two would drift.
         */
        if ( $this->getParam( 'propertyId' ) ) {

            $property->load( $this->getParam( 'propertyId' ) );
        }

        $this->set( 'property', $property->_getProperties() );
        $this->set( 'propertyId', $this->getParam( 'propertyId' ) );


        /*
         * How many Profiles the delete would take with it, so the confirmation
         * can say a number rather than "and its Profiles". Counted live: a
         * Property gains and loses Profiles from other screens, and a stale
         * number in a destructive confirmation is worse than none.
         */
        $this->set( 'profileCount', self::countLiveProfiles( $this->getParam( 'propertyId' ) ) );
        /*
         * A current Profile even here, so the tile shows all three tiers rather
         * than the Organization over two blank lines -- and so the nav can
         * offer the Property and Profile groups at all.
         */
        $siteId = $this->resolveCurrentSiteId(
            $this->getParam( 'siteId' ), $this->getParam( 'propertyId' ) );

        $this->settingsChrome( array( 'siteId' => $siteId ), '', $this->getParam( 'propertyId' ) );
        $this->setSubview( 'base.propertyProfile', self::screen( $this->getParam( 'propertyId' ) ) );
    }

    /**
     * The details screen, which PropertyEdit draws again for a refused save.
     * A Property not yet saved has no id.
     *
     * @param string $propertyId
     * @return array<string, mixed> as setSubview() takes it
     */
    public static function screen( $propertyId ) {

        return [
            'template' => 'property_profile.php',
            'vars'     => [
                'headline' => $propertyId ? \OWA\Core\CoreAPI::t( 'Property Details' ) : \OWA\Core\CoreAPI::t( 'New Property' ),
                'property', 'propertyId',
                // A refused save's values, to show in place of what is stored.
                'posted',
            ],
        ];
    }

    /**
     * Profiles this Property still has, archived ones excluded.
     *
     * @param  string $propertyId
     * @return int
     */
    public static function countLiveProfiles( $propertyId ) {

        if ( ! $propertyId ) {

            return 0;
        }

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( 'id, archived_date' );
        $db->where( 'property_id', $propertyId );

        $live = 0;

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( empty( $row['archived_date'] ) ) {

                $live++;
            }
        }

        return $live;
    }
}

?>
