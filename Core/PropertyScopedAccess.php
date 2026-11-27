<?php
namespace OWA\Core;

/**
 * A controller whose capability is held on the Property the request is about.
 *
 * The Property is the Property of the request's siteId, else its propertyId.
 * A request whose siteId is no Profile is refused, and so is one whose
 * propertyId is not that Profile's Property, administrator or not. The
 * capability is checked against a Profile of that Property the user may see.
 * A user who is not an administrator and may see none is refused, whatever
 * the capability, unless the Property has no Profile at all. A request with
 * no Property, naming neither or a Profile that has none, is checked as any
 * controller's is, against its siteId.
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

        if ( ! $this->requestNamesOneProperty() ) {

            return false;
        }

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
     * The Property of the request's siteId, else its propertyId; '' for
     * neither, or for a Profile with no Property.
     *
     * @return string
     */
    protected function requestPropertyId() {

        if ( (string) $this->getCurrentSiteId() !== '' ) {

            return (string) $this->propertyIdOfRequestSite();
        }

        return (string) $this->getParam( 'propertyId' );
    }

    /**
     * Whether the request's siteId names a Profile, and its propertyId, when
     * it sends one too, is that Profile's Property. True for a request with
     * no siteId.
     *
     * @return bool
     */
    private function requestNamesOneProperty() {

        if ( (string) $this->getCurrentSiteId() === '' ) {

            return true;
        }

        $ofSite = $this->propertyIdOfRequestSite();

        if ( $ofSite === null ) {

            return false;
        }

        $named = (string) $this->getParam( 'propertyId' );

        return $named === '' || $named === $ofSite;
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
