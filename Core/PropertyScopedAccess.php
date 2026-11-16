<?php
namespace OWA\Core;

/**
 * A controller whose capability is held on the Property the request is about.
 *
 * The Property is the request's propertyId, else the Property of its siteId.
 * The capability is checked against a Profile of that Property the user may
 * see; a user who is not an administrator and may see none is refused,
 * whatever the capability. A request naming neither is checked as any
 * controller's is, against its siteId.
 */
trait PropertyScopedAccess {

    /**
     * @param  \OWA\Module\Base\Classes\ServiceUser $currentUser
     * @return bool
     */
    protected function isCurrentUserCapable( $currentUser ) {

        $propertyId = $this->requestPropertyId();

        if ( $propertyId === '' ) {

            return parent::isCurrentUserCapable( $currentUser );
        }

        $siteId = $currentUser->siteIdForProperty( $propertyId );

        if ( $siteId === '' && ! $currentUser->isAdmin() ) {

            return false;
        }

        return (bool) $currentUser->isCapable( $this->getRequiredCapability(), $siteId );
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
