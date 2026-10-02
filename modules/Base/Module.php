<?php
namespace OWA\Module\Base;


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
 * Base Package Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Module extends \OWA\Core\Module {

    /**
     * Constructor
     *
     */
    function __construct() {

        $this->name = 'base';
        $this->display_name = 'Open Web Analytics';
        $this->group = 'Base';
        $this->author = 'Peter Adams';
        $this->version = 11;
        $this->description = 'Base functionality for OWA.';
        $this->config_required = false;
        $this->required_schema_version = 66;
        return parent::__construct();
    }

    /**
     * The schema 1.14.0 left behind, and the oldest this release upgrades.
     *
     * v2's updates start at 34. The ones before are gone with the v1 code they
     * migrated, so an older installation upgrades to 1.14 first (PLAN.html
     * 2.21).
     */
    const OLDEST_UPGRADABLE_SCHEMA = 33;

    /**
     * Refuse an installation older than 1.14.0 before applying anything.
     *
     * No recorded version is refused too. Module::install() records one when it
     * creates every table, so its absence means tables made by something older,
     * which the chain used to handle by replaying from Update001.
     */
    function update() {

        /*
         * Before any update: an installation on a server 2.0 cannot run on
         * must not start the migration and fail partway through it.
         */
        $problem = \OWA\Module\Base\Classes\DatabaseRequirement::problem();

        if ( $problem !== null ) {

            \OWA\Core\CoreAPI::notice( $problem );

            return false;
        }

        $recorded = (int) \OWA\Core\CoreAPI::getSetting( $this->name, 'schema_version' );

        if ( $recorded < self::OLDEST_UPGRADABLE_SCHEMA ) {

            \OWA\Core\CoreAPI::notice( $recorded
                ? sprintf( 'This installation is at schema %d. Upgrade to OWA 1.14.0 (schema %d) first,'
                    . ' then to this release.', $recorded, self::OLDEST_UPGRADABLE_SCHEMA )
                : sprintf( 'This installation has no recorded schema version. Upgrade to OWA 1.14.0'
                    . ' (schema %d) first, then to this release.', self::OLDEST_UPGRADABLE_SCHEMA ) );

            return false;
        }

        return parent::update();
    }

    function init() {

	    // create event queues

        // register queue type implementations
        $this->registerImplementation('event_queue_types', 'file', \OWA\Module\Base\Classes\FileEventQueue::class, 'Classes/FileEventQueue.php');
        $this->registerImplementation('event_queue_types', 'database', \OWA\Module\Base\Classes\DbEventQueue::class, 'Classes/DbEventQueue.php');
        $this->registerImplementation('event_queue_types', 'http', \OWA\Module\Base\Classes\HttpEventQueue::class, 'Classes/HttpEventQueue.php');
        
        // register named queues
        $this->registerEventQueue( 'incoming_tracking_events', array(

            'queue_type'            =>     'file',
            'path'                    =>    \OWA\Core\CoreAPI::getSetting('base', 'async_log_dir'),
            'rotation_interval'        => 3600
        ));

        $this->registerEventQueue( 'processing', array(

            'queue_type'            => 'database',
            'server'                => \OWA\Core\CoreAPI::getSetting('base', 'db_host'),
            'port'                    => \OWA\Core\CoreAPI::getSetting('base', 'db_port'),
            'username'                => \OWA\Core\CoreAPI::getSetting('base', 'db_user'),
            'password'                => \OWA\Core\CoreAPI::getSetting('base', 'db_password')
        ));

        $this->setupTrackingProperties();

    }

    /**
     * Register Tracking Event Properties
     *
     *
     */

     public function setupTrackingProperties() {

        $environmental = \OWA\Module\Base\Classes\TrackingEventHelpers::requestProperties();

        $this->registerTrackingProperties( 'environmental', $environmental );

        /*
         * REGISTERED HERE, not in registerFilters(), because of when each
         * runs. registerFilters() is called from Service::initializeFramework()
         * and this method runs during module REGISTRATION, which is earlier --
         * so a filter attached there is attached after the maps it would
         * contribute to have already been built, and contributes nothing.
         *
         * This is therefore the hook point for a property contributor, and it
         * is before the maps are built by construction rather than by luck.
         */
        \OWA\Core\CoreAPI::registerFilter( 'tracking_properties_regular',
            array( '\OWA\Module\Base\Classes\Beacon\Compat', 'contributeClientProperties' ) );

        \OWA\Core\CoreAPI::registerFilter( 'tracking_properties_derived',
            array( '\OWA\Module\Base\Classes\Beacon\Compat', 'contributeDerivedProperties' ) );

        /*
         * Filtered as the maps are BUILT, not where they are used, so
         * everything downstream sees the same set: the allowlist at log.php
         * reads the regular map, and ProcessEvent reads the derived one.
         *
         * A compat layer contributes here what an older tracker still sends
         * and the current vocabulary no longer declares. tracking_properties
         * .json stays the statement of what v2 itself carries.
         */
        $regular = \OWA\Core\CoreAPI::filter( 'tracking_properties_regular',
            \OWA\Module\Base\Classes\TrackingEventHelpers::clientProperties() );

        $this->registerTrackingProperties( 'regular', $regular );

        $derived = \OWA\Core\CoreAPI::filter( 'tracking_properties_derived',
            \OWA\Module\Base\Classes\TrackingEventHelpers::serverProperties() );

        $this->registerTrackingProperties( 'derived', $derived );

     }

    /**
     * Register Filters
     *
     * The following lines register filter methods.
     */
    function registerFilters() {

        $this->registerFilter('tracker_tag_cmds', $this, 'addTrackerCmds', 0);

        /*
         * The beacon compat layer contributes what an older generation still
         * sends and the current format no longer declares. Through a FILTER
         * because the wire keeps moving -- browsers cache trackers, so every
         * change leaves a generation sending the old shape. What gets dropped
         * is an entry once nothing carries it; this hook stays.
         */

        /*
         * THE EVENTS A BEACON IS SAVED AS. Callbacks on the one store point, in
         * priority order: the materializers append session_start and
         * first_visit, then goal marking sets is_goal_event on every event in
         * the set. See Classes\Ingest.
         *
         * Registered here because registerFilters() runs from
         * Service::initializeFramework(), which Caller's constructor calls in
         * every role -- including the logger role the beacon endpoint runs in,
         * and the drain of a queued file.
         */
        \OWA\Core\CoreAPI::registerFilter(
            \OWA\Module\Base\Classes\Ingest::TRACKING_EVENTS_PRE_SAVE,
            array( '\OWA\Module\Base\Classes\MaterializedEvents', 'sessionStart' ), 10 );

        \OWA\Core\CoreAPI::registerFilter(
            \OWA\Module\Base\Classes\Ingest::TRACKING_EVENTS_PRE_SAVE,
            array( '\OWA\Module\Base\Classes\MaterializedEvents', 'firstVisit' ), 10 );

        \OWA\Core\CoreAPI::registerFilter(
            \OWA\Module\Base\Classes\Ingest::TRACKING_EVENTS_PRE_SAVE,
            array( '\OWA\Module\Base\Classes\PurchaseDeduplication', 'drop' ), 50 );

        \OWA\Core\CoreAPI::registerFilter(
            \OWA\Module\Base\Classes\Ingest::TRACKING_EVENTS_PRE_SAVE,
            array( '\OWA\Module\Base\Classes\GoalMarking', 'mark' ), 100 );
    }

    /**
     * The commands the generated snippet pushes, and therefore what a site records
     * out of the box.
     *
     * PER SITE, SERVER-SIDE. Which features a site records is decided here and
     * written into the page as commands, so the page author names none of them.
     *
     * So a feature that is not named here does not happen, however complete its
     * tracker method, registry entry and reserved name are. trackForms and
     * trackScroll were in exactly that state: both fully implemented, neither
     * invoked. CommandQueue dispatches any tracker method by name, so a site could
     * always have pushed them -- but nothing told it to, and nothing shipped them.
     *
     * SCROLL WAS WORSE THAN ABSENT. The depth check hung off another feature's
     * scroll binding, so scroll events fired only on installs with that module
     * active, for its sampled fraction of visitors, and nowhere else -- a first-class event gated on an
     * unrelated feature's sample rate. The two are separate features sharing a DOM
     * event and each binds its own listener now.
     *
     * trackSiteSearch IS here, and was briefly chained to trackPageView() instead.
     * That was wrong twice over. A public method whose contract is "send a page
     * view" should not also send a different event; and the chain broke on the
     * argument trackPageView takes -- Util.parseUrlParams() ignored its own url
     * parameter and read location.href, so a virtual page view named one URL while
     * the search read another. Both are fixed, and the command is where every other
     * feature is declared.
     *
     * It binds no listener, so like trackPageView it is called per page --
     * trackRouteChanges() calls it on each route change for that reason. A site
     * whose ?q= means something else calls setSearchQueryParams([]).
     */
    function addTrackerCmds( $cmds ) {

        $cmds[] = "owa_cmds.push(['trackPageView']);";
        $cmds[] = "owa_cmds.push(['trackClicks']);";
        $cmds[] = "owa_cmds.push(['trackForms']);";
        $cmds[] = "owa_cmds.push(['trackScroll']);";
        $cmds[] = "owa_cmds.push(['trackSiteSearch']);";

        return $cmds;
    }
    
    /**
     * Register this module's actions against their controllers.
     *
     * Registration is what lets CoreAPI::performAction() take the safe branch --
     * Lib::simpleFactory() with a class name and path resolved from THIS table --
     * instead of falling through to moduleFactory(), which reconstructs a class
     * name and a filesystem path by concatenating the request's own 'do' param.
     * That legacy branch stays for third-party modules that do not register, and
     * is now guarded by an identifier check, but core should never rely on it.
     *
     * Class names are the PSR-4 names, so Composer autoloads them and
     * simpleFactory() never touches the filesystem; the path is kept as a
     * fallback for a broken autoloader.
     */
    /**
     * Fieldsets rendered below the install, by SettingsForm::scopedFieldSet():
     * each field shows what it inherits until its Override switch is on.
     *
     * Registered with the actions, not in registerAdminPanels(): that runs only
     * when the admin nav is built, and the Profile and Property screens and
     * their save controllers read these without building it.
     */
    function registerScopedSettingsFieldSets() {

        $this->registerSettingsFieldSet( array(
            'id'       => 'base.profileObservation',
            'settings' => array(
                'p3p_policy',
                'domain_aliases',
                'query_string_filters',
                'default_page',
                'enableEcommerceReporting',
            ),
        ) );

        $this->registerSettingsFieldSet( array(
            'id'       => 'base.propertyAttribution',
            'settings' => array( 'attribution_lookback_days' ),
        ) );
    }

    function registerActions() {

        $this->registerScopedSettingsFieldSets();

        $this->registerAction( 'base.addSiteRest',                   'OWA\\Module\\Base\\Controller\\AddSiteRest',                  'Controller/AddSiteRest.php' );
        $this->registerAction( 'base.addUserRest',                   'OWA\\Module\\Base\\Controller\\AddUserRest',                  'Controller/AddUserRest.php' );
        $this->registerAction( 'base.apiRequest',                    'OWA\\Module\\Base\\Controller\\ApiRequest',                   'Controller/ApiRequest.php' );
        $this->registerAction( 'base.changeUserPasswordCli',         'OWA\\Module\\Base\\Controller\\ChangeUserPasswordCli',        'Controller/ChangeUserPasswordCli.php' );
        $this->registerAction( 'base.corsPreflight',                 'OWA\\Module\\Base\\Controller\\CorsPreflight',                'Controller/CorsPreflight.php' );
        $this->registerAction( 'base.deleteUserRest',                'OWA\\Module\\Base\\Controller\\DeleteUserRest',               'Controller/DeleteUserRest.php' );
        $this->registerAction( 'base.entityInstall',                 'OWA\\Module\\Base\\Controller\\EntityInstall',                'Controller/EntityInstall.php' );
        $this->registerAction( 'base.cubeRebuildCli',                'OWA\\Module\\Base\\Controller\\CubeRebuildCli',             'Controller/CubeRebuildCli.php' );
        $this->registerAction( 'base.v1DropCli',                     'OWA\\Module\\Base\\Controller\\V1DropCli',                   'Controller/V1DropCli.php' );
        $this->registerAction( 'base.flushCacheCli',                 'OWA\\Module\\Base\\Controller\\FlushCacheCli',                'Controller/FlushCacheCli.php' );
        $this->registerAction( 'base.updateUaRegexesCli',                 'OWA\\Module\\Base\\Controller\\UpdateUaRegexesCli',                'Controller/UpdateUaRegexesCli.php' );
        $this->registerAction( 'base.flushProcessedEventsCli',       'OWA\\Module\\Base\\Controller\\FlushProcessedEventsCli',      'Controller/FlushProcessedEventsCli.php' );
        $this->registerAction( 'base.installBase',                   'OWA\\Module\\Base\\Controller\\InstallBase',                  'Controller/InstallBase.php' );
        $this->registerAction( 'base.installCheckEnv',               'OWA\\Module\\Base\\Controller\\InstallCheckEnv',              'Controller/InstallCheckEnv.php' );
        $this->registerAction( 'base.installCli',                    'OWA\\Module\\Base\\Controller\\InstallCli',                   'Controller/InstallCli.php' );
        $this->registerAction( 'base.installConfig',                 'OWA\\Module\\Base\\Controller\\InstallConfig',                'Controller/InstallConfig.php' );
        $this->registerAction( 'base.installDefaultsEntry',          'OWA\\Module\\Base\\Controller\\InstallDefaultsEntry',         'Controller/InstallDefaultsEntry.php' );
        $this->registerAction( 'base.installFinish',                 'OWA\\Module\\Base\\Controller\\InstallFinish',                'Controller/InstallFinish.php' );
        $this->registerAction( 'base.installStart',                  'OWA\\Module\\Base\\Controller\\InstallStart',                 'Controller/InstallStart.php' );
        $this->registerAction( 'base.login',                         'OWA\\Module\\Base\\Controller\\Login',                        'Controller/Login.php' );
        $this->registerAction( 'base.loginForm',                     'OWA\\Module\\Base\\Controller\\LoginForm',                    'Controller/LoginForm.php' );
        $this->registerAction( 'base.logout',                        'OWA\\Module\\Base\\Controller\\Logout',                       'Controller/Logout.php' );
        $this->registerAction( 'base.moduleActivate',                'OWA\\Module\\Base\\Controller\\ModuleActivate',               'Controller/ModuleActivate.php' );
        $this->registerAction( 'base.moduleActivateCli',             'OWA\\Module\\Base\\Controller\\ModuleActivateCli',            'Controller/ModuleActivateCli.php' );
        $this->registerAction( 'base.moduleDeactivate',              'OWA\\Module\\Base\\Controller\\ModuleDeactivate',             'Controller/ModuleDeactivate.php' );
        $this->registerAction( 'base.moduleDeactivateCli',           'OWA\\Module\\Base\\Controller\\ModuleDeactivateCli',          'Controller/ModuleDeactivateCli.php' );
        $this->registerAction( 'base.moduleInstallCli',              'OWA\\Module\\Base\\Controller\\ModuleInstallCli',             'Controller/ModuleInstallCli.php' );
        $this->registerAction( 'base.notifyNewSession',              'OWA\\Module\\Base\\Controller\\NotifyNewSession',             'Controller/NotifyNewSession.php' );
        $this->registerAction( 'base.optionsFlushCache',             'OWA\\Module\\Base\\Controller\\OptionsFlushCache',            'Controller/OptionsFlushCache.php' );
        $this->registerAction( 'base.optionsGeneral',                'OWA\\Module\\Base\\Controller\\OptionsGeneral',               'Controller/OptionsGeneral.php' );
        $this->registerAction( 'base.visualizations',                'OWA\\Module\\Base\\Controller\\Visualizations',              'Controller/Visualizations.php' );
        $this->registerAction( 'base.visualizationSave',             'OWA\\Module\\Base\\Controller\\VisualizationSave',           'Controller/VisualizationSave.php' );
        $this->registerAction( 'base.visualizationEdit',             'OWA\\Module\\Base\\Controller\\VisualizationEdit',           'Controller/VisualizationEdit.php' );
        $this->registerAction( 'base.visualizationFunnel',           'OWA\\Module\\Base\\Controller\\VisualizationFunnel',         'Controller/VisualizationFunnel.php' );
        $this->registerAction( 'base.goalEvents',                     'OWA\\Module\\Base\\Controller\\GoalEvents',                    'Controller/GoalEvents.php' );
        $this->registerAction( 'base.goalEventEdit',                  'OWA\\Module\\Base\\Controller\\GoalEventEdit',                 'Controller/GoalEventEdit.php' );
        $this->registerAction( 'base.goalEventSave',                  'OWA\\Module\\Base\\Controller\\GoalEventSave',                 'Controller/GoalEventSave.php' );
        $this->registerAction( 'base.goalEventDelete',                'OWA\\Module\\Base\\Controller\\GoalEventDelete',               'Controller/GoalEventDelete.php' );
        $this->registerAction( 'base.optionsModules',                'OWA\\Module\\Base\\Controller\\OptionsModules',               'Controller/OptionsModules.php' );
        $this->registerAction( 'base.optionsReset',                  'OWA\\Module\\Base\\Controller\\OptionsReset',                 'Controller/OptionsReset.php' );
        $this->registerAction( 'base.optionsUpdate',                 'OWA\\Module\\Base\\Controller\\OptionsUpdate',                'Controller/OptionsUpdate.php' );
        $this->registerAction( 'base.overlayLauncher',               'OWA\\Module\\Base\\Controller\\OverlayLauncher',              'Controller/OverlayLauncher.php' );
        $this->registerAction( 'base.passwordResetForm',             'OWA\\Module\\Base\\Controller\\PasswordResetForm',            'Controller/PasswordResetForm.php' );
        $this->registerAction( 'base.passwordResetRequest',          'OWA\\Module\\Base\\Controller\\PasswordResetRequest',         'Controller/PasswordResetRequest.php' );
        $this->registerAction( 'base.processEvent',                  'OWA\\Module\\Base\\Controller\\ProcessEvent',                 'Controller/ProcessEvent.php' );
        $this->registerAction( 'base.processEventQueue',             'OWA\\Module\\Base\\Controller\\ProcessEventQueue',            'Controller/ProcessEventQueue.php' );
        $this->registerAction( 'base.processRequest',                'OWA\\Module\\Base\\Controller\\ProcessRequest',               'Controller/ProcessRequest.php' );
        $this->registerAction( 'base.pruneEventQueueArchivesCli',    'OWA\\Module\\Base\\Controller\\PruneEventQueueArchivesCli',   'Controller/PruneEventQueueArchivesCli.php' );
        $this->registerAction( 'base.partitionStatusCli',            'OWA\\Module\\Base\\Controller\\PartitionStatusCli',         'Controller/PartitionStatusCli.php' );
        $this->registerAction( 'base.cubeStatus',                    'OWA\\Module\\Base\\Controller\\CubeStatus',                 'Controller/CubeStatus.php' );
        $this->registerAction( 'base.cubeStatusDetail',              'OWA\\Module\\Base\\Controller\\CubeStatusDetail',           'Controller/CubeStatusDetail.php' );
        $this->registerAction( 'base.customDimensions',              'OWA\\Module\\Base\\Controller\\CustomDimensions',           'Controller/CustomDimensions.php' );
        $this->registerAction( 'base.customDimensionEdit',           'OWA\\Module\\Base\\Controller\\CustomDimensionEdit',        'Controller/CustomDimensionEdit.php' );
        $this->registerAction( 'base.customDimensionSave',           'OWA\\Module\\Base\\Controller\\CustomDimensionSave',        'Controller/CustomDimensionSave.php' );
        $this->registerAction( 'base.customDimensionDelete',         'OWA\\Module\\Base\\Controller\\CustomDimensionDelete',      'Controller/CustomDimensionDelete.php' );
        $this->registerAction( 'base.customDimensionListCli',        'OWA\\Module\\Base\\Controller\\CustomDimensionListCli',      'Controller/CustomDimensionListCli.php' );
        $this->registerAction( 'base.customDimensionApplyCli',       'OWA\\Module\\Base\\Controller\\CustomDimensionApplyCli',     'Controller/CustomDimensionApplyCli.php' );
        $this->registerAction( 'base.customDimensionRegisterCli',    'OWA\\Module\\Base\\Controller\\CustomDimensionRegisterCli',  'Controller/CustomDimensionRegisterCli.php' );
        $this->registerAction( 'base.customDimensionDeregisterCli',  'OWA\\Module\\Base\\Controller\\CustomDimensionDeregisterCli','Controller/CustomDimensionDeregisterCli.php' );
        $this->registerAction( 'base.scheduleRunCli',                'OWA\\Module\\Base\\Controller\\ScheduleRunCli',             'Controller/ScheduleRunCli.php' );
        $this->registerAction( 'base.scheduleStatusCli',             'OWA\\Module\\Base\\Controller\\ScheduleStatusCli',          'Controller/ScheduleStatusCli.php' );
        $this->registerAction( 'base.instanceInfoCli',              'OWA\\Module\\Base\\Controller\\InstanceInfoCli',           'Controller/InstanceInfoCli.php' );
        $this->registerAction( 'base.partitionInitCli',              'OWA\\Module\\Base\\Controller\\PartitionInitCli',           'Controller/PartitionInitCli.php' );
        $this->registerAction( 'base.partitionDropCli',              'OWA\\Module\\Base\\Controller\\PartitionDropCli',           'Controller/PartitionDropCli.php' );
        $this->registerAction( 'base.partitionReorganizeCli',        'OWA\\Module\\Base\\Controller\\PartitionReorganizeCli',     'Controller/PartitionReorganizeCli.php' );
        $this->registerAction( 'base.partitionRotateCli',            'OWA\\Module\\Base\\Controller\\PartitionRotateCli',         'Controller/PartitionRotateCli.php' );
        $this->registerAction( 'base.report',                        'OWA\\Module\\Base\\Controller\\Report',                        'Controller/Report.php' );
        $this->registerAction( 'base.reportsRest',                   'OWA\\Module\\Base\\Controller\\ReportsRest',                  'Controller/ReportsRest.php' );
        $this->registerAction( 'base.resetSecretsCli',               'OWA\\Module\\Base\\Controller\\ResetSecretsCli',              'Controller/ResetSecretsCli.php' );
        $this->registerAction( 'base.siteAddAllowedUserRest',        'OWA\\Module\\Base\\Controller\\SiteAddAllowedUserRest',       'Controller/SiteAddAllowedUserRest.php' );
        $this->registerAction( 'base.reportingHome',                 'OWA\\Module\\Base\\Controller\\ReportingHome',                 'Controller/ReportingHome.php' );
        $this->registerAction( 'base.propertyProfile',               'OWA\\Module\\Base\\Controller\\PropertyProfile',              'Controller/PropertyProfile.php' );
        $this->registerAction( 'base.organizationProfile',           'OWA\\Module\\Base\\Controller\\OrganizationProfile',          'Controller/OrganizationProfile.php' );
        $this->registerAction( 'base.profileSettings',               'OWA\\Module\\Base\\Controller\\ProfileSettings',              'Controller/ProfileSettings.php' );
        $this->registerAction( 'base.propertyAccess',                'OWA\\Module\\Base\\Controller\\PropertyAccess',               'Controller/PropertyAccess.php' );
        $this->registerAction( 'base.propertyDelete',                'OWA\\Module\\Base\\Controller\\PropertyDelete',                'Controller/PropertyDelete.php' );
        $this->registerAction( 'base.propertyEdit',                  'OWA\\Module\\Base\\Controller\\PropertyEdit',                  'Controller/PropertyEdit.php' );
        $this->registerAction( 'base.organizationEdit',              'OWA\\Module\\Base\\Controller\\OrganizationEdit',              'Controller/OrganizationEdit.php' );
        $this->registerAction( 'base.customReports',                 'OWA\\Module\\Base\\Controller\\CustomReports',                'Controller/CustomReports.php' );
        $this->registerAction( 'base.customReportEdit',              'OWA\\Module\\Base\\Controller\\CustomReportEdit',             'Controller/CustomReportEdit.php' );
        $this->registerAction( 'base.customReportMarkFavorite',      'OWA\\Module\\Base\\Controller\\CustomReportMarkFavorite',     'Controller/CustomReportMarkFavorite.php' );
        $this->registerAction( 'base.customReportSave',              'OWA\\Module\\Base\\Controller\\CustomReportSave',             'Controller/CustomReportSave.php' );
        $this->registerAction( 'base.customReportDelete',            'OWA\\Module\\Base\\Controller\\CustomReportDelete',           'Controller/CustomReportDelete.php' );
        $this->registerAction( 'base.sitesAdd',                      'OWA\\Module\\Base\\Controller\\SitesAdd',                     'Controller/SitesAdd.php' );
        $this->registerAction( 'base.sitesAddCli',                   'OWA\\Module\\Base\\Controller\\SitesAddCli',                  'Controller/SitesAddCli.php' );
        $this->registerAction( 'base.sitesDelete',                   'OWA\\Module\\Base\\Controller\\SitesDelete',                  'Controller/SitesDelete.php' );
        $this->registerAction( 'base.sitesEdit',                     'OWA\\Module\\Base\\Controller\\SitesEdit',                    'Controller/SitesEdit.php' );
        $this->registerAction( 'base.sitesEditAllowedUsers',         'OWA\\Module\\Base\\Controller\\SitesEditAllowedUsers',        'Controller/SitesEditAllowedUsers.php' );
        $this->registerAction( 'base.sitesEditSettings',             'OWA\\Module\\Base\\Controller\\SitesEditSettings',            'Controller/SitesEditSettings.php' );
        $this->registerAction( 'base.sitesInvocation',               'OWA\\Module\\Base\\Controller\\SitesInvocation',              'Controller/SitesInvocation.php' );
        $this->registerAction( 'base.sitesProfile',                  'OWA\\Module\\Base\\Controller\\SitesProfile',                 'Controller/SitesProfile.php' );
        $this->registerAction( 'base.sitesRest',                     'OWA\\Module\\Base\\Controller\\SitesRest',                    'Controller/SitesRest.php' );
        $this->registerAction( 'base.updates',                       'OWA\\Module\\Base\\Controller\\Updates',                      'Controller/Updates.php' );
        $this->registerAction( 'base.updatesApply',                  'OWA\\Module\\Base\\Controller\\UpdatesApply',                 'Controller/UpdatesApply.php' );
        $this->registerAction( 'base.notificationsRest',              'OWA\\Module\\Base\\Controller\\NotificationsRest',           'Controller/NotificationsRest.php' );
        $this->registerAction( 'base.realtimeRest',                  'OWA\\Module\\Base\\Controller\\RealtimeRest',                'Controller/RealtimeRest.php' );
        $this->registerAction( 'base.reportRealtime',                'OWA\\Module\\Base\\Controller\\ReportRealtime',              'Controller/ReportRealtime.php' );
        $this->registerAction( 'base.notificationMarkReadRest',           'OWA\\Module\\Base\\Controller\\NotificationMarkReadRest',        'Controller/NotificationMarkReadRest.php' );
        $this->registerAction( 'base.notificationDismissRest',       'OWA\\Module\\Base\\Controller\\NotificationDismissRest',    'Controller/NotificationDismissRest.php' );
        $this->registerAction( 'base.notificationsFetchCli',        'OWA\\Module\\Base\\Controller\\NotificationsFetchCli',       'Controller/NotificationsFetchCli.php' );
        $this->registerAction( 'base.updatesApplyCli',               'OWA\\Module\\Base\\Controller\\UpdatesApplyCli',              'Controller/UpdatesApplyCli.php' );
        $this->registerAction( 'base.users',                         'OWA\\Module\\Base\\Controller\\Users',                        'Controller/Users.php' );
        $this->registerAction( 'base.usersAdd',                      'OWA\\Module\\Base\\Controller\\UsersAdd',                     'Controller/UsersAdd.php' );
        $this->registerAction( 'base.usersChangePassword',           'OWA\\Module\\Base\\Controller\\UsersChangePassword',          'Controller/UsersChangePassword.php' );
        $this->registerAction( 'base.usersDelete',                   'OWA\\Module\\Base\\Controller\\UsersDelete',                  'Controller/UsersDelete.php' );
        $this->registerAction( 'base.usersEdit',                     'OWA\\Module\\Base\\Controller\\UsersEdit',                    'Controller/UsersEdit.php' );
        $this->registerAction( 'base.usersNewAccount',               'OWA\\Module\\Base\\Controller\\UsersNewAccount',              'Controller/UsersNewAccount.php' );
        $this->registerAction( 'base.usersPasswordEntry',            'OWA\\Module\\Base\\Controller\\UsersPasswordEntry',           'Controller/UsersPasswordEntry.php' );
        $this->registerAction( 'base.usersProfile',                  'OWA\\Module\\Base\\Controller\\UsersProfile',                 'Controller/UsersProfile.php' );
        $this->registerAction( 'base.myProfile',                 'OWA\\Module\\Base\\Controller\\MyProfile',                'Controller/MyProfile.php' );
        $this->registerAction( 'base.myProfileSave',             'OWA\\Module\\Base\\Controller\\MyProfileSave',            'Controller/MyProfileSave.php' );
        $this->registerAction( 'base.usersResetPassword',            'OWA\\Module\\Base\\Controller\\UsersResetPassword',           'Controller/UsersResetPassword.php' );
        $this->registerAction( 'base.usersRest',                     'OWA\\Module\\Base\\Controller\\UsersRest',                    'Controller/UsersRest.php' );
        $this->registerAction( 'base.usersSetPassword',              'OWA\\Module\\Base\\Controller\\UsersSetPassword',             'Controller/UsersSetPassword.php' );
    }

    /**
     * Register CLI Commands
     *
     * The following lines register various command line interface (CLI) controller.
     */
    function registerCliCommands() {

        $this->registerCliCommand('update', 'base.updatesApplyCli');
        $this->registerCliCommand('flush-cache', 'base.flushCacheCli');
        $this->registerCliCommand('v1-drop', 'base.v1DropCli');
        $this->registerCliCommand('fetch-notifications', 'base.notificationsFetchCli');
        $this->registerCliCommand('update-ua-regexes', 'base.updateUaRegexesCli');
        $this->registerCliCommand('processEventQueue', 'base.processEventQueue');
        $this->registerCliCommand('install', 'base.installCli');
        $this->registerCliCommand('activate', 'base.moduleActivateCli');
        $this->registerCliCommand('deactivate', 'base.moduleDeactivateCli');
        $this->registerCliCommand('install-module', 'base.moduleInstallCli');
        $this->registerCliCommand('add-site', 'base.sitesAddCli');
        $this->registerCliCommand('flush-processed-events', 'base.flushProcessedEventsCli');
        $this->registerCliCommand('prune-event-queue-archives', 'base.pruneEventQueueArchivesCli');
        $this->registerCliCommand('partition-status', 'base.partitionStatusCli');
        $this->registerCliCommand('partition-init', 'base.partitionInitCli');
        $this->registerCliCommand('partition-drop', 'base.partitionDropCli');
        $this->registerCliCommand('partition-reorganize', 'base.partitionReorganizeCli');
        $this->registerCliCommand('partition-rotate', 'base.partitionRotateCli');
        $this->registerCliCommand('change-password', 'base.changeUserPasswordCli');
        $this->registerCliCommand('reset-secrets', 'base.resetSecretsCli');
        $this->registerCliCommand('schedule-run', 'base.scheduleRunCli');
        $this->registerCliCommand('schedule-status', 'base.scheduleStatusCli');
        $this->registerCliCommand('instance-info', 'base.instanceInfoCli');
        $this->registerCliCommand('cube-rebuild', 'base.cubeRebuildCli');
        $this->registerCliCommand('custom-dimension-list', 'base.customDimensionListCli');
        $this->registerCliCommand('custom-dimension-apply', 'base.customDimensionApplyCli');
        $this->registerCliCommand('custom-dimension-register', 'base.customDimensionRegisterCli');
        $this->registerCliCommand('custom-dimension-deregister', 'base.customDimensionDeregisterCli');
    }

    /**
     * Register Scheduled Jobs
     *
     * Run by cmd=schedule-run, which belongs in cron every minute:
     *
     *   * * * * * cd /path/to/owa && php cli.php cmd=schedule-run
     *
     * Only partition-rotate ships registered. It is the one whose absence fails
     * silently and slowly on every installation -- the partition lead expires,
     * rows pile into the catch-all, reports keep working, and nobody notices
     * until a rotate has to rewrite a year of data with writes blocked.
     *
     * Queue processing is deliberately NOT shipped: whether to drain the queue
     * at all, and how often, depends on an installation's traffic and on whether
     * it queues in the first place. It is added in OWA_SCHEDULED_JOBS when
     * wanted -- see owa_settings::applyConfigConstants().
     */
    /**
     * A stable seed for spreading one daily job, per install and per job.
     *
     * The fallbacks matter: an install that has not been configured yet has no
     * public_url, and seeding every one of those from the same empty string
     * would put exactly the installs most likely to share an image back on the
     * same minute. The directory path differs per install even then.
     *
     * @return string
     */
    private function jobSeed( $job ) {

        $seed = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' );

        if ( $seed === '' ) {

            $seed = defined( 'OWA_DIR' ) ? OWA_DIR : php_uname( 'n' );
        }

        // The JOB NAME is part of it, or every daily job on one install lands
        // on the same minute -- which is the collision the spread exists to
        // avoid, just moved from between installs to within one.
        return $seed . '|' . $job;
    }

    function registerJobs() {

        // The NAME is deliberately not the command name. They are separate
        // fields -- a command can be scheduled more than once under different
        // names -- and when the only shipped job used the same string for both,
        // nothing in the documentation could show which one OWA_SCHEDULED_JOBS
        // keys on. It keys on the name.
        //
        // Registered with EMPTY params on purpose: no keep=, so nothing is ever
        // deleted. An installation that wants retention states it in
        // OWA_SCHEDULED_JOBS, which is the deliberate act it should be.
        // Retention must never arrive as a side effect of turning the scheduler
        // on. ScheduleCliTest pins the empty array for exactly that reason.
        //
        // DAILY, NOT MONTHLY. Every piece of work this job does is triggered by
        // a period AGEING -- a lead running short, a month passing out of the
        // detail window, the cube's daily partitions leaving the rebuild
        // window. Running monthly does not do less of it, it just finds each
        // one up to a month late: the cube would hold an extra month of daily
        // partitions, and a stalled lead would have a month to erode before the
        // next attempt, which 2.8 calls the invariant everything else rests on.
        // A run with nothing due is a handful of catalogue queries.
        //
        // NOT '@daily'. That is midnight exactly, and several OWA installs
        // commonly share one database server -- so every one of them would
        // start a run, and possibly a REORGANIZE that rewrites rows, at the
        // same instant. Same reasoning as fetch-notifications below, for a
        // local reason rather than a remote one.
        $this->registerJob(
            'rotate-partitions', 'partition-rotate',
            \OWA\Core\Cron::dailySpreadFor( $this->jobSeed( 'rotate-partitions' ) ), array() );

        /*
         * The reporting cube's build, EVERY FIVE MINUTES. Reports read the
         * cube, and their header says when it was built, so this cadence is
         * how fresh every report is.
         *
         * Affordable because a run skips what has not changed: a partition
         * nothing has reached since it was built, whose sessions had all
         * closed by then, is left alone (Cube\Builder::isCurrent()), and one
         * empty in both raw and the cube costs an index probe. So a quiet
         * Property costs a scan per partition and no swap, and an active one
         * rebuilds today's partition -- measured at 0.6s for 1k rows, 1.4s for
         * 10k and 12.6s for 100k on a db.t4g.small. An installation that wants
         * it more or less often says so in OWA_SCHEDULED_JOBS.
         *
         * Spread, not on the minute, for the reason rotate-partitions is:
         * several OWA installs commonly share one database server, and a
         * rebuild ends in an EXCHANGE PARTITION. Two runs cannot collide on a
         * cube either way -- each takes the cube's lock and a second skips it.
         */
        $this->registerJob(
            'rebuild-cube', 'cube-rebuild',
            \OWA\Core\Cron::minutelySpreadFor( $this->jobSeed( 'rebuild-cube' ), 5 ), array() );

        /*
         * Putting registered custom-dimension columns on the cubes.
         *
         * FREQUENT, unlike everything else here, and cheap enough to be. On a
         * run with nothing pending it is ONE indexed read for the whole
         * installation -- Dimensions::propertiesWithPendingWork() -- and does
         * no DDL at all.
         *
         * What it buys is the wait. Registering a dimension cannot do the ALTER
         * inline: it is a full table rebuild, 11.3 seconds measured at 1.5M
         * rows, well past PHP's 30-second limit and the load balancer's 65 --
         * and a killed request does not stop it, so a screen doing this inline
         * would time out, add the column anyway, and invite a retry that pays a
         * second rebuild. The build reconciles too, so nothing DEPENDS on this
         * job; without it a dimension registered from a screen would simply
         * wait for the next daily build instead of a quarter of an hour.
         */
        $this->registerJob(
            'apply-custom-dimensions', 'custom-dimension-apply',
            \OWA\Core\Cron::minutelySpreadFor( $this->jobSeed( 'apply-custom-dimensions' ), 15 ),
            array() );

        /*
         * Daily is the right cadence for release announcements: they are not
         * urgent, and the endpoint is rate limited per IP. Nothing renders from
         * the network any more, so a missed run costs a day's freshness rather
         * than a broken dashboard.
         *
         * NOT '@daily'. That is `0 0 * * *` -- midnight exactly -- so every
         * install running this job would call api.github.com at the same
         * instant, and most servers keep UTC, so timezones would not even
         * spread it. GitHub cannot tell that from an attack. Each install
         * derives its own minute and hour instead, and keeps it.
         *
         * The seed must be stable and install-specific. public_url is both, and
         * the fallbacks matter: an install that has not been configured yet has
         * no public_url, and seeding every one of those from the same empty
         * string would put exactly the installs most likely to share an image
         * back on the same minute. The directory path differs per install even
         * then.
         */
        $this->registerJob(
            'fetch-notifications', 'fetch-notifications',
            \OWA\Core\Cron::dailySpreadFor( $this->jobSeed( 'fetch-notifications' ) ), array() );

        // NOT registering update-ua-regexes here, deliberately.
        //
        // It would fit -- the patterns go stale on their own and monthly is
        // roughly how often uap-core changes -- but it makes an outbound HTTP
        // request to a third party, unattended, on a schedule. For self-hosted
        // analytics that is a decision the administrator makes, not one that
        // arrives with an upgrade. Same reasoning as the empty params above:
        // turning the scheduler on must not turn anything else on.
        //
        // An installation that wants it adds it to OWA_SCHEDULED_JOBS, or runs
        // cli.php cmd=update-ua-regexes from its own cron.
    }

    /**
     * Register API methods
     *
     * The following lines register various API methods.
     */
    function registerApiMethods() {

    	$this->registerRestApiRoute( 'v1', 'sites', 'GET', 'OWA\\Module\\Base\\Controller\\SitesRest', 'Controller/SitesRest.php' );
        $this->registerRestApiRoute( 'v1', 'sites', 'POST', 'OWA\\Module\\Base\\Controller\\AddSiteRest', 'Controller/AddSiteRest.php' );
        $this->registerRestApiRoute( 'v1', 'users', 'GET', 'OWA\\Module\\Base\\Controller\\UsersRest', 'Controller/UsersRest.php' );
        $this->registerRestApiRoute( 'v1', 'users', 'POST', 'OWA\\Module\\Base\\Controller\\AddUserRest', 'Controller/AddUserRest.php' );
		$this->registerRestApiRoute( 'v1', 'users', 'DELETE', 'OWA\\Module\\Base\\Controller\\DeleteUserRest', 'Controller/DeleteUserRest.php', [ 'params_order' => ['user_id'] ] );
		$this->registerRestApiRoute( 'v1', 'siteUsers', 'POST', 'OWA\\Module\\Base\\Controller\\SiteAddAllowedUserRest', 'Controller/SiteAddAllowedUserRest.php' );
		$this->registerRestApiRoute( 'v1', 'notifications', 'GET', 'OWA\\Module\\Base\\Controller\\NotificationsRest', 'Controller/NotificationsRest.php' );
		$this->registerRestApiRoute( 'v1', 'realtime', 'GET', 'OWA\\Module\\Base\\Controller\\RealtimeRest', 'Controller/RealtimeRest.php' );
		$this->registerRestApiRoute( 'v1', 'notifications', 'POST', 'OWA\\Module\\Base\\Controller\\NotificationMarkReadRest', 'Controller/NotificationMarkReadRest.php', [ 'params_order' => ['notificationId'] ] );
		$this->registerRestApiRoute( 'v1', 'notifications', 'DELETE', 'OWA\\Module\\Base\\Controller\\NotificationDismissRest', 'Controller/NotificationDismissRest.php', [ 'params_order' => ['notificationId'] ] );
		$this->registerRestApiRoute( 'v1', 'reports', 'GET', 'OWA\\Module\\Base\\Controller\\ReportsRest', 'Controller/ReportsRest.php', [ 'params_order' => ['report_name'] ] );
    }

    /**
     * Registers Admin panels
     *
     */
    function registerAdminPanels() {

        $this->registerSettingsPage(array(
                'do'             => 'base.optionsGeneral',
                'title'          => 'Main Configuration',
                'group'          => 'General',
                'order'          => 1,
                'fieldsets'      => array(
                    'base.tracking', 'base.announcements', 'base.reporting' ) )
        );

        /*
         * The general options page, as three fieldsets rather than as HTML.
         *
         * What each control looks like is already in the declaration -- `type`
         * says select or text, `label` and `description` say what to call it --
         * so the screen no longer spells any of that out a second time. The
         * order here is the order they render in.
         */
        $this->registerSettingsFieldSet( array(
            'id'       => 'base.tracking',
            'legend'   => 'Tracking Request Processing',
            'settings' => array(
                'resolve_hosts',
                'log_robots',
                'log_named_users',
                'excluded_ips',
                'anonymize_ips',
                'query_string_filters',
            ),
        ) );

        $this->registerSettingsFieldSet( array(
            'id'       => 'base.announcements',
            'legend'   => 'Visitor Announcements',
            'settings' => array( 'announce_visitors', 'notice_email' ),
        ) );

        $this->registerSettingsFieldSet( array(
            'id'       => 'base.reporting',
            'legend'   => 'Reporting',
            'settings' => array( 'timezone', 'attribution_lookback_days' ),
        ) );





        /*
         * No Properties panel. The hierarchy is navigated from the site control
         * above the report nav, which is where someone is when they need it;
         * an admin-menu roster was a second place to browse the same tree.
         * The per-Property edit screen is reached from that control.
         */

        /*
         * The settings nav is for INSTALL-WIDE configuration only.
         *
         * Everything else now hangs off the Organization / Property / Profile
         * hierarchy and is reached from the site control, which is where
         * someone is when they need it:
         *
         *   - User Management -> the Organization, because that is where user
         *     accounts live.
         *   - Tracked Sites   -> the control itself replaced that roster.
         *   - Goal Settings   -> a Profile, since goals are per site.
         *
         * What is left here is genuinely install-scoped: the modules that are
         * active, and the timezone -- which cannot move down, because yyyymmdd
         * and nine other date parts are baked into every fact row at collection
         * in the configured zone, so it is not retroactive and two profiles
         * disagreeing about it would put rows of different meanings in one
         * table with nothing recording which.
         */
        $this->registerSettingsPage(array(
                'do'             => 'base.optionsModules',
                'title'          => 'Modules',
                'group'          => 'General',
                'order'          => 3)
        );

        /*
         * The health of every reporting cube. Install-wide because whether the
         * scheduler is building them is a fact about the installation, and
         * beside Modules because it answers the same kind of question: is this
         * installation doing what it should.
         */
        $this->registerSettingsPage(array(
                'do'             => 'base.cubeStatus',
                'title'          => 'Reporting Cubes',
                'group'          => 'General',
                'order'          => 4)
        );

        /*
        */
    }


    /**
     * Register Metrics
     *
     * The following lines register various data metrics.
     */
    /**
     * The v1 metric vocabulary was here.
     *
     * REMOVED. v2's metrics replace it -- declared in config/metrics.php and
     * resolved against the Property's cube -- and the reports that existed
     * under v1 are driven by those now. There is no second vocabulary to keep
     * working: the migrator denormalises v1's tracking data into v2's raw
     * store, so no v1 reporting path survives it.
     *
     * Keeping both was not neutral. A name registered against BOTH a v1 fact
     * table and the cube resolved to whichever the report's base entity
     * happened to be, so a metric could exist for one report and not another,
     * and a report mixing a v1-only metric with a cube-only one failed with no
     * error anywhere -- the metric boxes simply did not render. That cost more
     * to reason about than the vocabulary was worth.
     *
     * git history has the implementations if one is ever needed for comparison.
     */
    function registerMetrics() {}

    /**
     * And the v1 dimension vocabulary, removed for the same reason.
     *
     * config/dimensions.php declares v2's, every one a column read against the
     * cube. Report configs naming an old name are caught by the report
     * characterization tests rather than by a runtime fallback.
     */
    function registerDimensions() {}

    /**
     * The dimensions that read a Property's reporting cube.
     *
     * EVERY ONE IS DENORMALISED, and that is structural rather than a choice:
     * the cube carries its values as columns on the event row, so there is no
     * dimension table to join and no foreign key to name. Two things follow.
     *
     * The join disappears -- `denormalized` is what tells ResultSetManager to
     * group by a column instead of joining a satellite -- and so does the
     * name-collision problem, because `registerDimension()` keys a denormalised
     * entry by ENTITY (`[$name][$entity]`) while a normalised one is keyed by
     * name alone and the last write wins. A cube dimension sharing a name with
     * a v1 one therefore sits beside it rather than replacing it.
     *
     * These are inert until something asks for them: an entity reaches a query
     * only by being able to compute one of its metrics, and no v1 metric is
     * registered against the cube.
     */
    /**
     * Every report this module offers, under the id it is reached by.
     *
     * All of them name a controller today, so nothing renders differently --
     * this is the indirection going in ahead of the conversion. `pages` reaches
     * exactly what `base.reportPages` reached, and converting it later means
     * changing this one line to name a JSON file instead of a class.
     *
     * Ids are derived from the controller names they replace, hyphenated
     * because they are read by people and appear in URLs: `entry-pages`, not
     * `reportEntryPages`.
     *
     * Called lazily by CoreAPI::getReportRegistry(), never from the module
     * constructor -- see the note on Module::registerReports().
     */
    function registerReports() {

        $this->registerReport( 'ads', 'reports/ads.json' );
        $this->registerReport( 'avg-order-value', 'reports/avg-order-value.json' );
        $this->registerReport( 'browsers', 'reports/browsers.json' );
        $this->registerReport( 'campaigns', 'reports/campaigns.json' );
        $this->registerReport( 'channels', 'reports/channels.json' );
        $this->registerReport( 'content', 'reports/content.json' );
        $this->registerReport( 'creative-performance', 'reports/creative-performance.json' );
        $this->registerReport( 'dashboard', 'reports/dashboard.json' );
        // Not configuration: it reads raw, not the cube (Classes\Realtime).
        $this->registerReport( 'model-comparison', 'reports/model-comparison.json' );
        $this->registerReport( 'realtime', array( 'controller' => 'base.reportRealtime' ) );
        $this->registerReport( 'document', 'reports/document.json' );
        /*
         * EVENTS, grouped by name, and it replaces the v1 action reports.
         *
         * 1.x had an Actions report over owa_action_fact with actionName,
         * actionLabel and actionGroup dimensions and an `actions` metric. NONE of
         * those exist on v2 and none should: a tracked action is a `custom_event`
         * row like any other event, and what separates it from a page view is
         * event_type -- the column `eventName` is registered against. So "what
         * happened on this site, and how often" is one report over one dimension
         * instead of a report per event family.
         *
         * An action's own name, label and group ride `params`, and params ARE the
         * custom dimensions -- site-defined keys, promoted to a cube column by
         * Classes\Cube\Dimensions. So there is nothing for this release's
         * vocabulary to declare, and the reports that grouped by them are removed
         * rather than reimplemented.
         */
        $this->registerReport( 'events', 'reports/events.json' );
        $this->registerReport( 'clicks', 'reports/clicks.json' );
        $this->registerReport( 'dom-clicks', 'reports/dom-clicks.json' );
        /*
         * HOW FAR DOWN PEOPLE READ. Scroll events grouped by the threshold passed,
         * site-wide and by page. Every threshold a page view crosses raises its own
         * event, so each row's count is "how many reached at least this far".
         */
        $this->registerReport( 'scroll-depth', 'reports/scroll-depth.json' );
        /*
         * WHAT PEOPLE SEARCHED FOR on the site: view_search_results by term, as
         * searches and as the sessions that searched.
         */
        $this->registerReport( 'site-search', 'reports/site-search.json' );
        $this->registerReport( 'ecommerce', 'reports/ecommerce.json' );
        $this->registerReport( 'ecommerce-conversion-rate', 'reports/ecommerce-conversion-rate.json' );
        $this->registerReport( 'entry-pages', 'reports/entry-pages.json' );
        $this->registerReport( 'exit-pages', 'reports/exit-pages.json' );
        $this->registerReport( 'geolocation', 'reports/geolocation.json' );
        $this->registerReport( 'goals', 'reports/goals.json' );
        $this->registerReport( 'hosts', 'reports/hosts.json' );
        $this->registerReport( 'keywords', 'reports/keywords.json' );
        $this->registerReport( 'latest-visits', 'reports/latest-visits.json' );
        $this->registerReport( 'os', 'reports/os.json' );
        $this->registerReport( 'page-types', 'reports/page-types.json' );
        $this->registerReport( 'pages', 'reports/pages.json' );
        $this->registerReport( 'referring-sites', 'reports/referring-sites.json' );
        $this->registerReport( 'revenue', 'reports/revenue.json' );
        $this->registerReport( 'search-engines', 'reports/search-engines.json' );
        $this->registerReport( 'sources', 'reports/sources.json' );
        $this->registerReport( 'traffic', 'reports/traffic.json' );
        $this->registerReport( 'transactions', 'reports/transactions.json' );
        $this->registerReport( 'user-acquisition', 'reports/user-acquisition.json' );
        $this->registerReport( 'visitors', 'reports/visitors.json' );
        $this->registerReport( 'visitors-loyalty', 'reports/visitors-loyalty.json' );
    }

    function registerNavigation() {

        $this->addNavigationSubGroup('Dashboard', $this->reportRef( 'dashboard' ), 'Dashboard', 1, 'view_reports', 'Reports','fa fa-tachometer-alt');

        // Second, after the dashboard.
        $this->addNavigationSubGroup('Realtime', $this->reportRef( 'realtime' ), 'Realtime', 2, 'view_reports', 'Reports','fa fa-bolt');

        /*
         * The custom report roster. Gated on view_reports rather than on
         * edit_reports, because a reader who cannot author one can still be
         * sent one -- the roster is where they find what they have been given.
         */
        $this->addNavigationSubGroup('Custom Reports', array( 'do' => 'base.customReports' ),
            'Custom Reports', 9, 'view_site_list', 'Reports', 'fa fa-sliders-h');

        //Ecommerce
        /*
         * Every e-commerce report is in the NAV, not behind a links widget on
         * the overview.
         *
         * The overview used to carry a report-links widget listing eight of
         * them, which meant a reader had to know to open the overview first and
         * then read a list -- while the nav, the thing built for moving between
         * reports, showed four of the eight. Reports belong in the menu.
         *
         * The five that were only in the widget -- products, product-skus,
         * product-categories, avg-order-value, ecommerce-conversion-rate -- are
         * added here. `transactions` was already in the nav and not in the
         * widget, so it stays.
         *
         * Ordered by what a reader is answering: money first, then what sold,
         * then how long buying took.
         *
         * Every child declares view_reports_ecommerce like the group. The
         * default is the weaker view_reports, which was masked -- a child is
         * only reachable once its group's capability passed -- but a link
         * claiming a weaker requirement than the group holding it is a
         * disagreement waiting to be read the wrong way.
         */
        $this->addNavigationSubGroup('Ecommerce', $this->reportRef( 'ecommerce' ), 'Ecommerce', 6, 'view_reports_ecommerce', 'Reports','fa fa-shopping-cart');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'revenue' ), 'Revenue', 2, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'transactions' ), 'Transactions', 3, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'avg-order-value' ), 'Average Order Value', 4, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'ecommerce-conversion-rate' ), 'Conversion Rate', 5, 'view_reports_ecommerce');
        /*
         * days-to-purchase and visits-to-purchase go with them. Each is one
         * dimension -- daysToTransaction, visitsToTransaction -- measuring the
         * gap between acquisition and a purchase, and v2 has built neither. The
         * anchors are on the row (visitor_fsts, prior_sessions), so these come
         * back as computed dimensions rather than needing schema.
         */

        /*
         * THE PRODUCT REPORTS ARE NOT BUILT, and the data is kept for them.
         * A purchase's line items are in raw's params.items, from ingest and
         * from the v1 migration (PurchaseMigrator), in the tracker's shape. No
         * cube column or dimension reads them yet, so productName, productSku,
         * productCategory, lineItemRevenue and lineItemQuantity have no v2
         * answer. Items are a list per purchase, so reading them is a row per
         * item -- JSON_TABLE, or an item-grain table a build fills -- not one
         * more cube column.
         */

        //Content
        $this->addNavigationSubGroup('Content', $this->reportRef( 'content' ), 'Content', 5, 'view_reports', 'Reports','fa fa-newspaper');
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'pages' ), 'Pages', 1);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'page-types' ), 'Page Types', 2);
        /*
         * The Feeds link was here. Feed requests are not a v2 event -- the type
         * is refused at the door -- and nothing has written one since 2021. A
         * nav entry to a report with no data source is worse than no entry.
         */
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'entry-pages' ), 'Entry Pages', 3);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'exit-pages' ), 'Exit Pages', 4);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'clicks' ), 'Clicks', 5);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'scroll-depth' ), 'Scroll Depth', 6);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'site-search' ), 'Site Search', 7);


        /*
         * ACTION TRACKING was here, and the data still flows -- track.action
         * maps onto custom_event and its properties land in owa_event_raw's
         * `params`. What cannot ship is the REPORTS: they group by actionGroup,
         * actionName and actionLabel, which are params paths, and the cube
         * reaches a params path through a REGISTERED custom dimension
         * (Cube\JsonStep) that adds a column per Property. That is an
         * installation's choice, so it cannot be a shipped dimension, and a
         * shipped report cannot depend on one.
         *
         * An install that wants these registers action_group and friends as
         * custom dimensions and builds its own report over them.
         */

        //Visitors
        $this->addNavigationSubGroup( 'Visitors', $this->reportRef( 'visitors' ), 'Visitors', 4, 'view_reports', 'Reports','fa fa-user-friends');
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'geolocation' ), 'Geo-location', 1);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'hosts' ), 'Domains', 2);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'visitors-loyalty' ), 'Visitor Loyalty', 3);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'browsers' ), 'Browser Types', 6);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'os' ), 'Operating Systems', 7);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'latest-visits' ), 'Latest Visits', 8);

        //Traffic
        $this->addNavigationSubGroup('Traffic', $this->reportRef( 'traffic' ), 'Traffic', 3, 'view_reports', 'Reports','fa fa-random');
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'channels' ), 'Channels', 0);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'user-acquisition' ), 'User Acquisition', 2);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'model-comparison' ), 'Model Comparison', 7);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'keywords' ), 'Search Terms', 1);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'search-engines' ), 'Search Engines', 3);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'referring-sites' ), 'Referring Web Sites', 4);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'campaigns' ), 'Campaigns', 5);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'ads' ), 'Ad Performance', 6);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'creative-performance' ), 'Creative Performance', 8);

        //Goals
        $this->addNavigationSubGroup('Goals', $this->reportRef( 'goals' ), 'Goals', 7, 'view_reports', 'Reports','fa fa-bullseye');

    }

    /*
     * The combined reporting stylesheet is now produced by webpack
     * (reportingCssConfig in webpack.config.js), emitting the same file to the
     * same directory. The PHP-CLI build package that used to concatenate the six
     * source CSS files here has been retired along with the whole build-package
     * machinery (the base.build CLI command + owa_buildController).
     */

    /**
     * Registers Event Handlers with queue queue
     *
     */
    function _registerEventHandlers() {

        /*
         * THE v1 INGEST CHAIN WAS HERE, and this is 2.25 step 4.
         *
         * It was: base.page_request -> requestHandlers writes owa_request and
         * raises base.page_request_logged -> sessionHandlers writes owa_session
         * and raises base.new_session -> ten dimension handlers and the
         * document, conversion, commerce and visitor-update handlers populate
         * the star schema. Clicks, actions and feed requests ran their own
         * copies of the same shape.
         *
         * NOTHING READ ITS OUTPUT. The v1 metric and dimension vocabularies are
         * gone, so owa_request, owa_session, the dimension tables and the fact
         * tables were write-only -- and it is not free to keep: the queue is a
         * RETRY queue, reached when a handler returns EVENT_FAILED, and this
         * install had accumulated 50 failures in two hours from a chain filling
         * tables nobody queries.
         *
         * base.new_session, the one signal anything outside v1 wanted, is
         * raised by Handler\EventRawHandlers::announce() now -- from the
         * ingest that materialises the marker, one hop instead of three, and
         * beside base.new_visitor and base.new_page_view. Both were raised
         * twice while the two chains overlapped, which is what
         * IngestAnnouncementsTest caught.
         *
         * WHAT GOES WITH IT, and is not replaced:
         *   - v1's GOAL CONVERSIONS. conversionHandlers evaluated them into
         *     owa_session's goal_N columns. v2 marks is_goal_event at ingest
         *     instead (Classes\GoalMarking).
         *   - v1's tables stop being WRITTEN. They are not dropped: the
         *     migrator reads them, and their history is the only copy of what
         *     was collected before v2 ingest existed.
         *
         * git history has every handler if one is ever wanted.
         */

        /*
         * Notification handler.
         *
         * announce_visitors and notice_email are per-Profile now, and this
         * runs at module-registration time -- there is no event and no site
         * here to ask about. So the handler registers unconditionally and
         * makes the decision itself, where it has a site_id. The only test
         * left that is knowable this early is the CLI one.
         */
        if ( ! defined('OWA_CLI') ) {

            $this->registerEventHandler( 'base.new_session', 'notifyHandlers' );
        }

        /*
         * EVERY TRACKING EVENT BASE PROCESSES, as a namespace rather than a list.
         *
         * base.processRequest dispatches each event it processes under
         * tracking.<name> (logEvent() sets the key), and this handler writes the
         * raw row. A type a module routes to its own processor
         * (addTrackingEventProcessor) never passes through base.processRequest,
         * so it is never dispatched here -- unless that processor dispatches it
         * under tracking.* itself, which a module's processor must not do.
         *
         * The handler's own guard is what refuses a beacon with no site, visitor,
         * session or instant; the namespace decides only that it is asked.
         */
        $this->registerEventHandler(
            \OWA\Core\CoreAPI::anyTrackingEvent(),
            /*
             * Passed as an OBJECT, where every handler above is passed by name.
             * registerEventHandler() accepts either; this costs the same
             * instantiation the factory would have done.
             */
            new \OWA\Module\Base\Handler\EventRawHandlers()
        );

        // install complete handler
        $this->registerEventHandler('install_complete', $this, 'installCompleteHandler');
        // User management
        $this->registerEventHandler(array('base.set_password', 'base.reset_password', 'base.new_user_account'), 'userHandlers');
    }

    function _registerEventProcessors() {

        /*
         * Base's own tracking events: every name its property registry
         * declares, each routed by exact name. A module registers its own the
         * same way (addTrackingEventProcessor), and an exact name outranks the
         * wildcard, so a module's events never reach this processor.
         */
        $this->addTrackingEventProcessor(
            \OWA\Module\Base\Classes\TrackingEventHelpers::eventNames(), 'base.processRequest' );

        /*
         * A site's CUSTOM event names, which nothing can register ahead of time:
         * the tracking.* namespace, resolved last by getEventProcessor().
         */
        $this->addEventProcessor( \OWA\Core\CoreAPI::anyTrackingEvent(), 'base.processRequest' );
    }

    function _registerEntities() {

        $this->registerEntity(array(
                'site',
                'organization',
                'property',
            /*
             * 'configuration' is NOT here. Update043 unpacked that table into
             * install-scope rows of owa_setting and dropped it, so a fresh
             * install must not create it -- its presence is what tells
             * Settings the blob is still the store of record.
             *
             * The entity class stays: the rollback recreates the table, and
             * entityFactory resolves by PSR-4 rather than from this list.
             */
            'setting',
            'goal_event',
            'goal_event_condition',
                'user',
                'queue_item',
                'scheduled_job',
                'notification',
                'notification_state',
                'custom_report',
                'custom_report_favorite',
                'job_lock',
                'site_user',
                /*
                 * v2. Registered unconditionally so cmd=update creates them and
                 * the partition commands find them -- neither is conditional on
                 * anything, and a table nothing writes to costs an empty
                 * tablespace. Handler\EventRawHandlers writes both, for every
                 * site.
                 *
                 * `event` IS NOT HERE, and that is not an omission. There is no
                 * owa_event: the reporting cube is one table per Property,
                 * named and created by Classes\Cube\Cubes, so Entity\Event is
                 * a shape rather than a table. Registering it would have this
                 * module's install create owa_event, and would have the
                 * partition commands maintain a lead on a table nothing writes
                 * to.
                 */
                'event_raw',
                'visitor_acquisition',
                'custom_dimension',
                // How far the v1 migration has got (Classes\Migration).
                'migration_progress')
            );

    }

    function installCompleteHandler($event) {

        //owa_coreAPI::debug('test handler: '.print_r($event, true));
    }
    

}


?>
