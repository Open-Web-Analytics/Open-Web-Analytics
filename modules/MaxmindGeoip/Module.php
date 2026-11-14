<?php
namespace OWA\Module\MaxmindGeoip;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//


/**
 * Maxmind GeoIP Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class Module extends \OWA\Core\Module {

    var $method;

    function __construct() {

        $this->name = 'maxmind_geoip';
        $this->display_name = 'Maxmind GeoIP';
        $this->group = 'geoip';
        $this->author = 'Peter Adams';
        $this->version = '1.0';
        $this->description = 'Performs Maxmind Geo-IP lookups.';
        $this->config_required = false;
        $this->required_schema_version = 1;

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
		
		// turn on geo-location
		\OWA\Core\CoreAPI::setSetting( 'base', 'geolocation_lookup', true );
        // needed so default filters will not fun
        \OWA\Core\CoreAPI::setSetting('base', 'geolocation_service', 'maxmind');


        return parent::__construct();
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
    function registerCliCommands() {

        $this->registerCliCommand( 'update-geoip-db', 'maxmind_geoip.updateGeoipDbCli' );
    }

    /**
     * Keeping the database current, weekly, on the servers whose role runs the
     * scheduler (the command's default roles, full and worker).
     *
     * Registered here for the reason the command is: a module's jobs are
     * collected from active modules only (Service::loadJobs()). Registered
     * whether or not a licence key is set, so the job is listed wherever it can
     * run; without a key the scheduled run is a success that says it skipped.
     *
     * Unlike Base's update-ua-regexes, this outbound request ships on: an
     * administrator who activated this module and saved a key has asked for
     * the database.
     *
     * Weekly at a time each install derives, not '@weekly': MaxMind see every
     * install's check, and '@weekly' is Sunday midnight everywhere. The check
     * is a HEAD request, which does not count against the account's download
     * limit; MaxMind publish twice a week, so a copy is at most a week behind.
     */
    function registerJobs() {

        $this->registerJob(
            'refresh-geoip-db', 'update-geoip-db',
            \OWA\Core\Cron::weeklySpreadFor( $this->jobSeed( 'refresh-geoip-db' ) ), array( 'scheduled' => 1 ),
            'Checks whether MaxMind have published a newer GeoLite2 database than the local copy, and downloads '
          . 'it if they have, or if there is no local copy. The check does not count against the MaxMind '
          . 'account\'s download limit. Does nothing until a MaxMind licence key is set.' );
    }

    function registerActions() {

        $this->registerAction(
            'maxmind_geoip.updateGeoipDbCli',
            'OWA\\Module\\MaxmindGeoip\\Controller\\UpdateGeoipDbCli',
            'Controller/UpdateGeoipDbCli.php'
        );

        $this->registerAction(
            'maxmind_geoip.optionsGeoip',
            'OWA\\Module\\MaxmindGeoip\\Controller\\OptionsGeoip',
            'Controller/OptionsGeoip.php'
        );

        $this->registerAction(
            'maxmind_geoip.optionsGeoipUpdate',
            'OWA\\Module\\MaxmindGeoip\\Controller\\OptionsGeoipUpdate',
            'Controller/OptionsGeoipUpdate.php'
        );
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
    function registerAdminPanels() {

        $this->registerSettingsPage( array(
            'do'        => 'maxmind_geoip.optionsGeoip',
            'title'     => \OWA\Core\CoreAPI::t( 'GeoIP' ),
            'section'   => 'Instance',
            'order'     => 10,
            'fieldsets' => array( 'maxmind_geoip.account' ),
        ) );

        /*
         * The two editable settings. What they are called and what they offer
         * is in settings.php; this says only that they appear together on this
         * page, under this legend, in this order.
         *
         * The Status block above them on that page is NOT a fieldset: it
         * reports whether the database is present and how to refresh it, which
         * is not a setting anyone edits.
         */
        $this->registerSettingsFieldSet( array(
            'id'       => 'maxmind_geoip.account',
            'legend'   => \OWA\Core\CoreAPI::t( 'MaxMind Account' ),
            'settings' => array( 'db_license_key', 'db_edition' ),
        ) );
    }

    function registerFilters() {

        if ( \OWA\Core\CoreAPI::getSetting('base', 'geolocation_service') === 'maxmind' ) {

            $this->registerFilter('geolocation', 'maxmind', $this->method, 0, 'classes');
        }
    }
}