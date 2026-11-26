<?php
namespace OWA\Module\Base\Controller;

/**
 * Site Profile Controller
 */

class SitesProfile extends \OWA\Core\AdminController {

    // edit_sites on the Property of the Profile, or of the one a new Profile
    // is added to; create_properties when there is neither.
    use \OWA\Core\PropertyScopedAccess;

    /**
     * No siteId and no propertyId: the form's save makes a new Property.
     *
     * @return bool
     */
    protected function createsProperty() {

        return (string) $this->getParam( 'siteId' ) === '' && (string) $this->getParam( 'propertyId' ) === '';
    }

    public function __construct($params) {

        $this->setRequiredCapability('edit_sites');
        parent::__construct($params);
    }

    public function action() {

        // needed as this controller is
        $site_id = $this->getParam('siteId');
        if (!empty($site_id)) {
            $site = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );
            $site_data = $site->_getProperties();
            $this->set('config', $site->get('settings') );
            $this->set('edit', $this->getParam('edit'));

        } else {
            $site_data = $this->defaultsForProperty( $this->getParam( 'propertyId' ) );
            // Pre-selects the Property on the form and in the site control.
            $this->set( 'propertyId', isset( $site_data['property_id'] ) ? $site_data['property_id'] : '' );
            $this->set( 'params', array_merge( (array) $this->params, [
                'propertyId' => isset( $site_data['property_id'] ) ? $site_data['property_id'] : '' ] ) );
        }



        $this->set('site', $site_data);
        $this->set('siteId', $site_id);
        // The settings chrome: a Profile is a tier of the tree, not a
        // settings page. See OptionsHierarchy.
        $this->settingsChrome( [], $site_id, $site_id ? '' : (string) $this->getParam( 'propertyId' ) );
        $this->setSubview('base.sitesProfile');
    }

    /**
     * What a new Profile under a given Property starts with.
     *
     * An app Property's Profiles observe an app, and a web Property's
     * Profiles usually observe its own domain, so both are filled in. The
     * form still lets either be changed. An unknown or archived Property
     * gives nothing, and the form falls back to creating a new one.
     *
     * @param  string $propertyId
     * @return array<string, string> the new Profile's property_id, stream_type and domain; nothing for no Property
     */
    private function defaultsForProperty( $propertyId ) {

        if ( ! $propertyId ) {

            return [];
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $propertyId );

        if ( ! $property->wasPersisted() || $property->isArchived() ) {

            return [];
        }

        if ( ! $property->isWebProperty() ) {

            return [
                'property_id' => (string) $property->get( 'id' ),
                'stream_type' => \OWA\Module\Base\Entity\Site::STREAM_APP,
            ];
        }

        return [
            'property_id' => (string) $property->get( 'id' ),
            'stream_type' => \OWA\Module\Base\Entity\Site::STREAM_WEB,
            'domain'      => (string) $property->get( 'domain' ),
        ];
    }

}
