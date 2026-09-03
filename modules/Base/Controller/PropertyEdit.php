<?php

namespace OWA\Module\Base\Controller;

/**
 * Rename a Property.
 *
 * The migration names Properties for you -- from the site's domain, or from
 * whichever site it happened to see first -- so without this the names it chose
 * are permanent. That is the whole reason this screen exists.
 *
 * It deliberately does NOT touch the Profiles beneath it. A Profile's name is a
 * published API field (/v1/sites emits it, and the WordPress plugin labels its
 * picker with it), so renaming a Property must not cascade into one.
 */
class PropertyEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_sites' );
        $this->setNonceRequired();
    }

    public function validate() {

        /*
         * The name is required rather than allowed to be blank. A Property with
         * no name has nothing to head its group in the site selector, which
         * falls back to the domain and reads as though the Property had been
         * deleted.
         */
        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => 'A Property needs a name -- it is what the site selector groups by.' ) );

        /*
         * A web Property needs a domain.
         *
         * It is the origin a tracking request is accepted or refused on, so a
         * web Property without one has nothing to check against -- the field
         * was optional and quietly load-bearing. An APP Property is identified
         * by its Profiles' bundle ids instead, so it is not asked for one.
         *
         * The TYPE is read the way the save reads it: from the stored row when
         * editing, from the request only when creating. Trusting the request on
         * an edit would let a hand-made post declare an existing web Property
         * an app and skip this.
         */
        if ( $this->propertyTypeFor( $this->getParam( 'propertyId' ) )
             === \OWA\Module\Base\Entity\Property::TYPE_WEB ) {

            $this->addValidation( 'domain', trim( (string) $this->getParam( 'domain' ) ), 'required',
                array( 'errorMsg' => 'A website Property needs a domain -- it is the origin a '
                    . 'tracking request is accepted or refused on.' ) );
        }

        /*
         * Only checked when editing. An absent id means create, so requiring
         * the row to exist would make adding a Property impossible.
         */
        if ( $this->getParam( 'propertyId' ) ) {

            $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'entityExists',
                array(
                    'entity'   => 'base.property',
                    'column'   => 'id',
                    'errorMsg' => 'That Property no longer exists.',
                ) );
        }
    }

    function action() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $name        = trim( (string) $this->getParam( 'name' ) );
        $domain      = trim( (string) $this->getParam( 'domain' ) );
        $description = (string) $this->getParam( 'description' );

        $propertyId = $this->getParam( 'propertyId' );

        if ( $propertyId ) {

            /*
             * property_type is NOT written on an edit.
             *
             * It is chosen once, at creation: the kind decides which identifier
             * the Property is known by, and every Profile beneath it is set up
             * against that. Letting an edit change it would invalidate all of
             * them at once, so the form states it and this ignores whatever
             * arrives.
             */
            $property->load( $propertyId );
            $property->set( 'name', $name );
            $property->set( 'domain', $domain );
            $property->set( 'description', $description );
            $property->update();

        } else {

            /*
             * A new Property joins the Organization every other one belongs to.
             * ensureOrganization() creates it on first need, so this works on
             * an install that has somehow never had one.
             */
            $sm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

            $propertyId = $property->generateId( 'property:' . $name . ':' . uniqid( '', true ) );

            $property->set( 'id', $propertyId );
            $property->set( 'organization_id', $sm->ensureOrganization() );
            $property->set( 'name', $name );
            $property->set( 'domain', $domain );
            $property->set( 'property_type', $this->requestedType() );
            $property->set( 'description', $description );
            $property->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $property->create();
        }

        $this->set( 'propertyId', $propertyId );
        $this->setRedirectAction( 'base.propertyProfile' );
        $this->set( 'status_code', 3201 );
    }

    /**
     * The kind a request is asking for, checked against the known kinds.
     *
     * It decides whether a domain is required, so an unrecognised value must
     * not become a way to skip that: anything that is not a kind is web, which
     * is both the default and the stricter of the two.
     *
     * @return string
     */
    private function requestedType() {

        $asked = (string) $this->getParam( 'propertyType' );

        return isset( \OWA\Module\Base\Entity\Property::types()[ $asked ] )
            ? $asked : \OWA\Module\Base\Entity\Property::TYPE_WEB;
    }

    /**
     * The kind that governs this save: stored when editing, requested when new.
     *
     * @param  string $propertyId
     * @return string
     */
    private function propertyTypeFor( $propertyId ) {

        if ( ! $propertyId ) {

            return $this->requestedType();
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $propertyId );

        /*
         * A row that does not load answers WEB rather than falling through to
         * the request. The id is validated separately and the save will refuse;
         * until it does, the stricter answer is the safe one.
         */
        return $property->wasPersisted()
            ? $property->getPropertyType()
            : \OWA\Module\Base\Entity\Property::TYPE_WEB;
    }

    function errorAction() {

        $this->setRedirectAction( 'base.propertyProfile' );
        $this->set( 'error_msg', implode( ' ', (array) $this->getValidationErrorMsgs() ) );
    }
}

?>
