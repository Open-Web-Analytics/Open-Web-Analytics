<?php
namespace OWA\Core;

/**
 * A controller whose capability is held on the Property the request is about.
 *
 * The Property is the request's propertyId, else the Property of its siteId.
 * The capability is checked against a Profile of that Property the user may
 * see. A user who is not an administrator and may see none is refused,
 * whatever the capability, unless the Property has no Profile at all.
 * A request naming neither is checked as any controller's is, against its
 * siteId.
 *
 * Making a Property asks create_properties, which is held install-wide
 * (createsProperty()). So does a Property with no Profile: no grant can be
 * held on it until its first, which is the next step after making it.
 */
trait PropertyScopedAccess {

    /**
     * @param  \OWA\Module\Base\Classes\ServiceUser $currentUser
     * @return bool
     */
    protected function isCurrentUserCapable( $currentUser ) {

        if ( $this->createsProperty() ) {

            return (bool) $currentUser->isCapable( 'create_properties' );
        }

        $propertyId = $this->requestPropertyId();

        if ( $propertyId === '' ) {

            return parent::isCurrentUserCapable( $currentUser );
        }

        $siteId = $currentUser->siteIdForProperty( $propertyId );

        if ( $siteId === '' && ! $currentUser->isAdmin() ) {

            return self::isEmptyProperty( $propertyId ) && $currentUser->isCapable( 'create_properties' );
        }

        return (bool) $currentUser->isCapable( $this->getRequiredCapability(), $siteId );
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
     * The request's propertyId, else the Property of its siteId; '' for neither.
     *
     * @return string
     */
    protected function requestPropertyId() {

        $propertyId = (string) $this->getParam( 'propertyId' );

        if ( $propertyId === '' && $this->getCurrentSiteId() ) {

            $propertyId = \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $this->getCurrentSiteId() );
        }

        return $propertyId;
    }
}
