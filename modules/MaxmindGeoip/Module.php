<?php
namespace OWA\Module\MaxmindGeoip;

/**
 * Maxmind GeoIP Module
 */

class Module extends \OWA\Core\Module {

    const NAME = 'maxmind_geoip';

    /*
     * Declared, not set in the constructor, so cmd=version-info reads it
     * without constructing the module (Core\VersionInfo).
     */
    public $required_schema_version = 1;

    /**
     * The lookup the geolocation filter calls on the maxmind class, by the
     * lookup_method setting: getLocation or getLocationFromWebService.
     *
     * @var string
     */
    public $method;

    public function __construct() {

        $this->display_name = 'Maxmind GeoIP';
        $this->group = 'geoip';
        $this->author = 'Peter Adams';
        $this->version = '1.0';
        $this->description = 'Performs Maxmind Geo-IP lookups.';
        $this->config_required = false;

        $mode = \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'lookup_method');

        switch ( $mode ) {

            case "geoip_city_isp_org_web_service":
                $method = 'getLocationFromWebService';
                break;

            case "city_lite_db":
                $method = 'getLocation';
                break;

            default:
                $method = 'getLocation';
        }

        $this->method = $method;
        
        // An active module resolves locations.
        \OWA\Core\CoreAPI::setSetting( 'base', 'geolocation_lookup', true );


        parent::__construct();
    }

    /**
     * Registered here rather than in Base, so the command exists only while
     * this module is active.
     *
     * A module's constructor runs only for modules in the active list, so an
     * installation not doing GeoIP lookups is never offered a command for
     * maintaining a database it does not read -- and one that activates the
     * module gets it with nothing further to do.
     */
    public function registerCliCommands() {

        $this->registerCliCommand( 'geoip-update-db', 'maxmind_geoip.geoipUpdateDbCli' );
    }

    /**
     * Keeping the database current, weekly, on the full server, which runs
     * the scheduler (ServerRole::SCHEDULER).
     *
     * Registered here for the reason the command is: a module's jobs are
     * collected from active modules only (Service::loadJobs()). Registered
     * whether or not a licence key is set, so the job is listed wherever it can
     * run; without a key the scheduled run is a success that says it skipped.
     *
     * This outbound request ships on: an administrator who activated this
     * module and saved a key has asked for the database.
     *
     * Weekly at a time each install derives, not '@weekly': MaxMind see every
     * install's check, and '@weekly' is Sunday midnight everywhere. The check
     * is a HEAD request, which does not count against the account's download
     * limit; MaxMind publish twice a week, so a copy is at most a week behind.
     */
    public function registerJobs() {

        $this->registerJob(
            'refresh-geoip-db', 'geoip-update-db',
            \OWA\Core\Cron::weeklySpreadFor( $this->jobSeed( 'refresh-geoip-db' ) ), [ 'scheduled' => 1 ],
            'Checks whether MaxMind have published a newer GeoLite2 database than the local copy, and downloads '
          . 'it if they have, or if there is no local copy. Does nothing until a MaxMind licence key is set.' );
    }

    public function registerActions() {

        $this->registerAction(
            'maxmind_geoip.geoipUpdateDbCli',
            'OWA\\Module\\MaxmindGeoip\\Controller\\GeoipUpdateDbCli' );

        $this->registerAction(
            'maxmind_geoip.optionsGeoip',
            'OWA\\Module\\MaxmindGeoip\\Controller\\OptionsGeoip' );

        $this->registerAction(
            'maxmind_geoip.optionsGeoipUpdate',
            'OWA\\Module\\MaxmindGeoip\\Controller\\OptionsGeoipUpdate' );
    }

    /**
     * Puts the settings page in the options navigation, but only while this
     * module is active -- the same gating the CLI command gets, and for the
     * same reason: an installation not doing GeoIP lookups should not be shown
     * settings for it.
     *
     * In the Instance section, after Base's pages: the nav drops a section it
     * does not build.
     */
    public function registerAdminPanels() {

        $this->registerSettingsPage( [
            'do'        => 'maxmind_geoip.optionsGeoip',
            'title'     => \OWA\Core\CoreAPI::t( 'GeoIP' ),
            'section'   => 'Instance',
            'order'     => 10,
            'fieldsets' => [ 'maxmind_geoip.account' ],
        ] );

        /*
         * The two editable settings. What they are called and what they offer
         * is in settings.php; this says only that they appear together on this
         * page, under this legend, in this order.
         *
         * The Status block above them on that page is NOT a fieldset: it
         * reports whether the database is present and how to refresh it, which
         * is not a setting anyone edits.
         */
        $this->registerSettingsFieldSet( [
            'id'       => 'maxmind_geoip.account',
            'legend'   => \OWA\Core\CoreAPI::t( 'MaxMind Account' ),
            'settings' => [ 'db_license_key', 'db_edition' ],
        ] );
    }

    /**
     * The geolocation filter, and this module's System Health section.
     * Registered whenever the module is active: filters are collected from
     * active modules only.
     */
    public function registerFilters() {

        $this->registerFilter( 'geolocation', 'maxmind', $this->method, 0, 'classes' );
        $this->registerFilter( 'system_health_sections', $this, 'systemHealthSection' );
    }

    /**
     * The GeoIP section of System Health, when lookups go to the web service:
     * the last failure, red within a day of it.
     *
     * @param  array<string, array<string, mixed>> $sections a module's sections, key => section
     * @param  int|null                            $now      unix time
     * @return array<string, array<string, mixed>>
     */
    public function systemHealthSection( $sections, $now = null ) {

        if ( \OWA\Core\CoreAPI::getSetting( self::NAME, 'lookup_method' ) !== 'geoip_city_isp_org_web_service' ) {

            return $sections;
        }

        $H    = \OWA\Module\Base\Classes\SystemHealth::class;
        $now  = $now ?? time();
        $last = (array) \OWA\Core\CoreAPI::getSetting( self::NAME, 'ws_last_failure' );

        if ( empty( $last['at'] ) ) {

            $finding = $H::finding( 'green', \OWA\Core\CoreAPI::t( 'Web service' ),
                \OWA\Core\CoreAPI::t( 'No lookup has failed.' ) );

        } else {

            $finding = $H::finding( $now - (int) $last['at'] < 86400 ? 'red' : 'green', \OWA\Core\CoreAPI::t( 'Last failure' ),
                sprintf( \OWA\Core\CoreAPI::t( '%1$s: %2$s. No location is recorded while lookups fail.' ),
                    date( 'Y-m-d H:i', (int) $last['at'] ), (string) ( $last['message'] ?? '' ) ) );
        }

        $sections['geoip'] = $H::section( \OWA\Core\CoreAPI::t( 'GeoIP' ), [ $finding ] );

        return $sections;
    }
}
