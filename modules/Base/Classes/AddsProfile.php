<?php
namespace OWA\Module\Base\Classes;

/**
 * Adding an Observation Profile, under an existing Property or a new one:
 * the input rules and the creation, shared by the Add Profile screen
 * (Controller\SitesAdd) and cmd=add-site (Controller\SitesAddCli).
 *
 * For a controller: it reads the request through getParam() and adds its
 * rules with addValidation().
 */
trait AddsProfile {

    /**
     * No propertyId and no live Property with the domain: createSite() makes one.
     *
     * @return bool
     */
    protected function createsProperty() {

        return $this->requestPropertyId() === '';
    }

    /**
     * The Property the Profile goes under, as createSite() picks it: the
     * request's propertyId, else the live Property of its domain. '' when a
     * new Property is made for it (createsProperty()).
     *
     * @return string
     */
    protected function requestPropertyId() {

        $propertyId = (string) $this->getParam( 'propertyId' );

        if ( $propertyId !== '' ) {

            return $propertyId;
        }

        return SiteManager::propertyIdForDomain( (string) $this->getParam( 'domain' ) );
    }

    /**
     * The domain is NOT validated here, because it is not set here.
     *
     * The domain belongs to the Property and is validated on the Property
     * screen; it is optional there -- an app has none. It is not unique
     * either: a second Observation Profile for a website already tracked is
     * what a Property is for. What this screen needs is to know WHICH
     * Property, and that is only required when it is not creating one.
     *
     * @return void
     */
    public function validate() {

        $this->validateStreamIdentity();

        if ( $this->getParam( 'propertyId' ) ) {

            $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'entityExists',
                [
                    'entity'   => 'base.property',
                    'column'   => 'id',
                    'errorMsg' => \OWA\Core\CoreAPI::t( 'That Property does not exist.' ),
                ] );

            return;
        }

        /*
         * Creating a Property as well as a Profile. The name describes the
         * website, so it is what the new Property will be called -- and a
         * Property with no name has nothing to head its group in the site
         * selector.
         */
        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Name is required.' ) ] );
    }

    /**
     * The identifier a Profile needs depends on what it observes.
     *
     * Called from validate() on both paths, because the type is a fact about
     * the Profile and is asked for whether or not a Property is being created
     * alongside it.
     *
     * @return void
     */
    protected function validateStreamIdentity() {

        $type = $this->getParam( 'streamType' ) ?: \OWA\Module\Base\Entity\Site::STREAM_WEB;

        if ( $type === \OWA\Module\Base\Entity\Site::STREAM_WEB ) {

            $this->addValidation( 'domain', SiteManager::domainOnly( $this->getParam( 'domain' ) ), 'required',
                [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Domain is required.' ) ] );

            return;
        }

        $this->addValidation( 'appId', trim( (string) $this->getParam( 'appId' ) ), 'required',
            [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Bundle ID or package name is required.' ) ] );
    }

    /**
     * Create the Profile, and its Property when there is none; the created
     * row's public properties go in 'site', [] when none was created.
     *
     * @return void
     */
    protected function addProfile() {

        $this->set('domain', $this->getParam('protocol').$this->getParam('domain') );

        $sm = new SiteManager();

        /*
         * createSite(), not createNewSite().
         *
         * createNewSite() refuses a domain that already has a site -- which
         * made a SECOND Observation Profile for a website impossible, the very
         * thing Properties exist to allow. nextProfileName() numbers them
         * "Observation Profile 1", "2", "3"; that counter could never reach 2
         * through this screen.
         */
        $site = $sm->createSite( $this->getParam( 'domain' ),
                            $this->getParam( 'name' ),
                            $this->getParam( 'description' ),
                            $this->getParam( 'site_family' ),
                            '',
                            $this->getParam( 'propertyId' ),
                            $this->getParam( 'streamType' ),
                            $this->getParam( 'appId' )
        );

        if ( $site ) {

            \OWA\Core\CoreAPI::debug( "Site added successfully. site_id: " . $site->get('site_id') );

            /*
             * Whoever adds a Profile and is not an administrator is granted it,
             * so the Property they made or manage is theirs to see: a grant on
             * any Profile is a grant on its Property (PropertyGrants).
             */
            $user = \OWA\Core\CoreAPI::getCurrentUser();

            if ( ! $user->isAdmin() && $user->getUserData( 'id' ) ) {

                $uid = (int) $user->getUserData( 'id' );
                $site->applyAssignedUserChanges( [ $uid ], [ $uid ] );
            }

            // A web Profile's bundle is there when its snippet is first pasted.
            if ( $site->getStreamType() === \OWA\Module\Base\Entity\Site::STREAM_WEB ) {

                TrackerBundle::publishNow( (string) $site->get( 'site_id' ) );
            }
        }

        // createSite() answers null when it could not create one.
        $this->set( 'site', $site ? $site->getPublicProperties() : [] );
    }
}
