<?php
namespace OWA\Core;

/**
 * A controller whose request is about a Property.
 *
 * Such a request needs two things: the user's role holds the controller's
 * capability (ServiceUser::isCapable()), and the Property is on the user's
 * access list (ServiceUser::canAccessProperty()). A capability the everyone
 * role holds is held on every Property.
 *
 * The Property is the one the request names: the Property of its siteId, its
 * propertyId, and the Property of the object it names
 * (requestObjectPropertyId()). A request whose siteId is no Profile is
 * refused, and so is one naming two different Properties, administrator or
 * not. A request naming no Property needs only the capability. One naming
 * only a Profile with no Property needs access to that Profile.
 *
 * Making a Property asks create_properties alone (createsProperty()). So does
 * a Property with no Profile: no grant can be held on it until its first,
 * which is the next step after making it.
 */
trait PropertyScopedAccess {

    /**
     * @param  \OWA\Module\Base\Classes\ServiceUser $currentUser
     * @return bool
     */
    protected function isCurrentUserCapable( $currentUser ) {

        if ( ! $this->requestNamesOneProperty() ) {

            return false;
        }

        if ( $this->createsProperty() ) {

            return (bool) $currentUser->isCapable( 'create_properties' );
        }

        $capability = $this->getRequiredCapability();
        $propertyId = $this->requestPropertyId();

        if ( $propertyId === '' ) {

            $siteId = (string) $this->getCurrentSiteId();

            return $currentUser->isCapable( $capability )
                && ( $siteId === '' || \OWA\Core\CoreAPI::isEveryoneCapable( $capability )
                     || $currentUser->isSiteAccessible( $siteId ) );
        }

        if ( $currentUser->isCapableOnProperty( $capability, $propertyId ) ) {

            return true;
        }

        return $currentUser->isCapable( 'create_properties' ) && self::isEmptyProperty( $propertyId );
    }

    /**
     * Whether the request makes a new Property. None does unless its
     * controller says so.
     *
     * @return bool
     */
    protected function createsProperty() {

        return false;
    }

    /**
     * The Property of the object the request names by its own id (a goal, a
     * custom report), or of a Property named under another parameter; null
     * when it names none, or one that does not exist. None does unless its
     * controller says so.
     *
     * @return string|null
     */
    protected function requestObjectPropertyId() {

        return null;
    }

    /**
     * Whether a Property exists and has no Profile, archived or live.
     *
     * @param  string $propertyId
     * @return bool
     */
    private static function isEmptyProperty( $propertyId ) {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $propertyId );

        return (bool) $property->get( 'id' ) && ! \OWA\Module\Base\Classes\Cube\Cubes::siteIds( $propertyId );
    }

    /**
     * The Property the request is about: the first namedPropertyIds() finds;
     * '' for none, or for a Profile with no Property.
     *
     * @return string
     */
    protected function requestPropertyId() {

        $named = (array) $this->namedPropertyIds();

        return $named ? (string) $named[0] : '';
    }

    /**
     * Every Property the request names, in this order: its siteId's ('' for
     * a Profile with no Property), its propertyId, its object's. Null for a
     * siteId that is no Profile.
     *
     * @return list<string>|null
     */
    private function namedPropertyIds() {

        $ids = [];

        if ( (string) $this->getCurrentSiteId() !== '' ) {

            $ofSite = $this->propertyIdOfRequestSite();

            if ( $ofSite === null ) {

                return null;
            }

            $ids[] = $ofSite;
        }

        $named = (string) $this->getParam( 'propertyId' );

        if ( $named !== '' ) {

            $ids[] = $named;
        }

        $object = $this->requestObjectPropertyId();

        if ( $object !== null ) {

            $ids[] = (string) $object;
        }

        return $ids;
    }

    /**
     * Whether the request's siteId, when it sends one, names a Profile, and
     * everything the request names is of one Property.
     *
     * @return bool
     */
    private function requestNamesOneProperty() {

        $named = $this->namedPropertyIds();

        return $named !== null && count( array_unique( $named ) ) <= 1;
    }

    /**
     * The Property of the Profile the request's siteId names: '' for no
     * siteId or a Profile with no Property, null for a siteId that is no
     * Profile. Archived Profiles count.
     *
     * @return string|null
     */
    protected function propertyIdOfRequestSite() {

        $siteId = (string) $this->getCurrentSiteId();

        if ( $siteId === '' ) {

            return '';
        }

        $site = \OWA\Module\Base\Entity\Site::forSiteId( $siteId );

        if ( ! $site->wasPersisted() ) {

            return null;
        }

        return (string) ( $site->get( 'property_id' ) ?: '' );
    }
}
