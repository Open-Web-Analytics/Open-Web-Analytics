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

    function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';

        // Same capability as every other settings page. Nothing here is more
        // dangerous than the rest of them, and a separate one would be a
        // capability administrators had to discover.
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $this->set( 'configuration', $this->c->fetch( 'maxmind_geoip' ) );

        $edition = \OWA\Module\MaxmindGeoip\Classes\Maxmind::edition();

        $this->set( 'editions', \OWA\Module\MaxmindGeoip\Classes\Maxmind::EDITIONS );
        $this->set( 'edition', $edition );

        // What the page is really for: saying whether this installation can
        // actually resolve a location right now. A key that was never set and a
        // database that was never downloaded both present as "geolocation just
        // does not work", and neither is visible anywhere else.
        $file = ( defined( 'OWA_MAXMIND_DATA_DIR' ) ? OWA_MAXMIND_DATA_DIR : OWA_DATA_DIR . 'maxmind/' )
              . $edition . '.mmdb';

        $this->set( 'db_file', $file );
        $this->set( 'db_present', file_exists( $file ) );
        $this->set( 'db_updated', file_exists( $file ) ? (int) filemtime( $file ) : 0 );
        $this->set( 'has_key', (bool) (
            \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_license_key' )
            ?: \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_license_key' )
        ) );

        $this->setView( 'base.options' );
        $this->data['subview'] = 'maxmind_geoip.optionsGeoip';
        $this->data['view_method'] = 'delegate';

        return $this->data;
    }
}
