<?php

namespace OWA\Module\MaxmindGeoip\Controller;

/**
 * Settings page for GeoIP lookups.
 *
 * The module needed one. It declared config_required = false and no settings at
 * all, so the licence key its database download requires could only be set by
 * hand-editing owa-config.php -- and nothing in the interface said so, or said
 * that a key was needed, or that the database goes stale.
 */
class OptionsGeoip extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';

        // Same capability as every other settings page. Nothing here is more
        // dangerous than the rest of them, and a separate one would be a
        // capability administrators had to discover.
        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        $edition = \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition();

        // What the page is really for: saying whether this installation can
        // actually resolve a location right now. A key that was never set and a
        // database that was never downloaded both present as "geolocation just
        // does not work", and neither is visible anywhere else.
        $file = \OWA\Module\MaxmindGeoip\Classes\Maxmind::dataDir() . $edition . '.mmdb';

        $this->set( 'db_file', $file );
        $this->set( 'db_present', file_exists( $file ) );
        $this->set( 'db_updated', file_exists( $file ) ? (int) filemtime( $file ) : 0 );
        $this->set( 'has_key', (bool) (
            \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_license_key' )
            ?: \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_license_key' )
        ) );
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->set( 'hierarchy_tier', 0 );
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        /*
         * Only what the Status block reads: the settings' controls and their
         * values come from the page's registration. 'has_key' is a STATUS, not
         * a control -- the answer to "why are locations blank" -- reported
         * above the field rather than inside it.
         */
        $this->setSubview( 'maxmind_geoip.optionsGeoip', [
            'template' => 'options_geoip.php',
            'vars'     => [ 'db_file', 'db_present', 'db_updated', 'has_key',
                            'settings_fieldsets' => \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'maxmind_geoip.optionsGeoip' ) ],
        ] );

        return $this->data;
    }
}
