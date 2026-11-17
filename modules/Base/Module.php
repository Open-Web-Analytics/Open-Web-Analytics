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
    public function __construct() {

        $this->name = 'base';
        $this->display_name = 'Open Web Analytics';
        $this->group = 'Base';
        $this->author = 'Peter Adams';
        $this->version = '11';
        $this->description = 'Base functionality for OWA.';
        $this->config_required = false;
        $this->required_schema_version = 90;
        parent::__construct();
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
    public function update() {

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

        if ( ! self::widenIdsAt( $recorded ) ) {

            return false;
        }

        if ( ! parent::update() ) {

            return false;
        }

        /*
         * What a new version needs beyond its schema (PLAN 2.30.7): the
         * Profiles' tracking bundles made from its tracker, and a bundle for
         * every Profile from 1.x, which has none. Only those not current are
         * rewritten -- each one's first line names the build and settings it
         * was made from.
         *
         * From the command line, now. From the update screen, as one queued
         * publish-trackers job for the next scheduler tick: a bundle per
         * Profile is not work for a web request, the same rule as a save
         * above one Profile.
         */
        if ( self::publishesInline() ) {

            $failed = array_keys( \OWA\Module\Base\Classes\TrackerBundle::publishStale(), 'failed', true );

            if ( $failed ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'Tracker bundles not published for %s; see the log, then run'
                    . ' php cli.php cmd=publish-trackers.', implode( ', ', $failed ) ) );
            }

        } else {

            \OWA\Module\Base\Classes\TrackerBundle::scheduleFullPublish();
        }

        return $this->recordTrackerBuild();
    }

    /** The schema of 1.x's own Update034, which creates v2's tables and re-keys no id. */
    const ONE_X_UPDATE034_SCHEMA = 34;

    /**
     * Update034's id step, for an installation at schema 34 before v2's
     * updates run.
     *
     * A 1.x build after 1.14 has an Update034 that creates v2's tables and
     * leaves use_32bit_hash alone. v2 runs only the updates after 34, and each
     * of them derives ids, so without this such an installation keeps deriving
     * 32-bit ones. At 34 from v2's own Update034 the step finds nothing to do.
     *
     * @param  int $recorded the base schema before any update runs
     * @return bool
     */
    public static function widenIdsAt( $recorded ) {

        if ( (int) $recorded !== self::ONE_X_UPDATE034_SCHEMA ) {

            return true;
        }

        return \OWA\Module\Base\Update\Update034::widenIds();
    }

    /** @var bool|null whether update() publishes in the run; null to decide by OWA_CLI. TESTS ONLY. */
    public static $publish_inline = null;

    /**
     * The command line publishes in the run; the update screen queues it.
     *
     * @return bool
     */
    private static function publishesInline() {

        return self::$publish_inline ?? ( defined( 'OWA_CLI' ) && OWA_CLI );
    }

    /**
     * Up to date when the schema is, AND the tracker this install has built is
     * the one that last applied an update (PLAN 2.30.7): the hash of the built
     * tracker (TrackerBundle::buildHash()) is the one recorded. A release that
     * leaves the tracker alone builds the same tracker and asks for no update.
     *
     * The BUILD, not a version a PR bumps: the hash comes from the manifest the
     * build writes, so nothing is committed and two tracker changes in flight
     * cannot conflict. Equal or not is all the gate asks, so no order is needed.
     *
     * The schema alone is still isSchemaCurrent(), which is what the
     * scheduler asks: a new tracker is no reason to stop its jobs.
     */
    public function isUpToDate() {

        return parent::isUpToDate()
            && (string) \OWA\Core\CoreAPI::getSetting( $this->name, 'tracker_build' ) === self::requiredTrackerBuild();
    }

    /**
     * The built tracker's hash; empty with no build. Not cached: a build can change under a long run.
     *
     * @return string
     */
    public static function requiredTrackerBuild() {

        return \OWA\Module\Base\Classes\TrackerBundle::buildHash();
    }

    public function install() {

        return parent::install() && $this->recordTrackerBuild();
    }

    /**
     * The built tracker as the one the install is updated to.
     *
     * @return true
     */
    private function recordTrackerBuild() {

        $c = \OWA\Core\CoreAPI::configSingleton();
        $c->persistSetting( $this->name, 'tracker_build', self::requiredTrackerBuild() );
        $c->save();

        return true;
    }

    public function init() {

        // The one queue type Base ships; a module adds its own (the sqs module).
        $this->registerImplementation('event_queue_types', 'file', \OWA\Module\Base\Classes\FileEventQueue::class );

        /*
         * The tracking intake (PLAN 2.30.3). Its type is written here and the
         * backend is the only plug: a queue type a module registers serves its
         * own queues, and that module's constant points this one at it
         * (TrackerIngest::backend()).
         */
        $this->registerEventQueue( \OWA\Module\Base\Classes\TrackerIngest::QUEUE, array(

            'queue_type'   => 'file',
            'path'         => \OWA\Core\CoreAPI::getSetting( 'base', 'async_log_dir' ),
            'max_receives' => \OWA\Module\Base\Classes\TrackerIngest::MAX_RECEIVES,
        ));

        $this->setupTrackingProperties();

    }

    /**
     * Register Tracking Event Properties
     *
     * @return void
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
    public function registerFilters() {

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
         * first_visit, then goal marking appends a goal event for each
         * definition an event in the set met. See Classes\Ingest.
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
     * Fieldsets rendered below the install, by SettingsForm::scopedFieldSet():
     * each field shows what it inherits until its Override switch is on.
     *
     * Registered with the actions, not in registerAdminPanels(): that runs only
     * when the admin nav is built, and the Profile and Property screens and
     * their save controllers read these without building it.
     *
     * @return void
     */
    public function registerScopedSettingsFieldSets() {

        /*
         * The Profile's Observation Settings screen, in the `profile_observation`
         * group so its save controller (SitesEditSettings) and its template read
         * the same list.
         */
        foreach ( array(
            array( 'base.profileObservation', \OWA\Core\CoreAPI::t( 'Page URLs' ),
                array( 'query_string_filters' ) ),
            array( 'base.profileCampaigns', \OWA\Core\CoreAPI::t( 'Campaign parameters' ),
                array( 'campaignKeys' ) ),
            array( 'base.profilePrivacy', \OWA\Core\CoreAPI::t( 'Privacy and exclusions' ),
                array( 'anonymize_ips', 'excluded_ips', 'log_named_users' ) ),
            array( 'base.profileAnnouncements', \OWA\Core\CoreAPI::t( 'Visitor announcements' ),
                array( 'announce_visitors', 'notice_email' ) ),
        ) as $order => list( $id, $legend, $settings ) ) {

            $this->registerSettingsFieldSet( array(
                'id'       => $id,
                'group'    => 'profile_observation',
                'order'    => $order,
                'legend'   => $legend,
                'settings' => $settings,
            ) );
        }

        $this->registerSettingsFieldSet( array(
            'id'       => 'base.propertyAttribution',
            'settings' => array( 'attribution_lookback_days', 'campaignUtmParams', 'campaignKeys' ),
        ) );

        /*
         * What a Property's reports show. On the Property, not the Profile: a
         * report is of the Property and covers every Profile in it.
         */
        $this->registerSettingsFieldSet( array(
            'id'       => 'base.propertyReporting',
            'settings' => array( 'enableEcommerceReporting', 'currencyISO3' ),
        ) );

        // The Property's Data Retention screen (Classes\Retention).
        $this->registerSettingsFieldSet( array(
            'id'       => 'base.propertyRetention',
            'settings' => array( 'cube_retention_months' ),
        ) );

        /*
         * The tracking tag (PLAN 2.24.4), in the `tracking_tag` group so an active
         * module adds its own fieldset beside it. Each fieldset serves every
         * level: each screen shows the settings that level may hold.
         *
         * One per job, each with a description, so the Tracking Tag screen can
         * show them as collapsed groups a person opens only for the one they want.
         */
        foreach ( array(
            array( 'base.trackingPageViews', \OWA\Core\CoreAPI::t( 'Page views' ),
                \OWA\Core\CoreAPI::t( 'When the tracker sends a page view, and what identifies the page.' ),
                array( 'tracker_page_views', 'tracker_route_changes', 'tracker_url_fragments' ) ),
            array( 'base.trackingClicks', \OWA\Core\CoreAPI::t( 'Clicks and downloads' ),
                \OWA\Core\CoreAPI::t( 'Clicks on links and other elements, and which links count as downloads.' ),
                array( 'tracker_clicks', 'tracker_download_extensions' ) ),
            array( 'base.trackingForms', \OWA\Core\CoreAPI::t( 'Forms' ),
                \OWA\Core\CoreAPI::t( 'A visitor starting and submitting a form.' ),
                array( 'tracker_forms' ) ),
            array( 'base.trackingScroll', \OWA\Core\CoreAPI::t( 'Scroll depth' ),
                \OWA\Core\CoreAPI::t( 'How far down each page visitors get.' ),
                array( 'tracker_scroll', 'tracker_scroll_thresholds' ) ),
            array( 'base.trackingSearch', \OWA\Core\CoreAPI::t( 'Site search' ),
                \OWA\Core\CoreAPI::t( 'Searches made with your site’s own search box, read from the results page’s URL.' ),
                array( 'tracker_site_search', 'tracker_site_search_params' ) ),
            array( 'base.trackingErrors', \OWA\Core\CoreAPI::t( 'JavaScript errors' ),
                \OWA\Core\CoreAPI::t( 'Uncaught errors in your pages’ scripts.' ),
                array( 'tracker_exceptions' ) ),
            array( 'base.trackingVisit', \OWA\Core\CoreAPI::t( 'Visits and cookies' ),
                \OWA\Core\CoreAPI::t( 'How long the tracker’s cookies hold a visit and a visitor together, and the domain they are set on.' ),
                array( 'tracker_session_cookie_days', 'tracker_visitor_cookie_days', 'tracker_cookie_domain' ) ),
            array( 'base.trackingPrivacy', \OWA\Core\CoreAPI::t( 'Privacy signals' ),
                \OWA\Core\CoreAPI::t( 'Browsers that ask not to be tracked, with Do Not Track or Global Privacy Control.' ),
                array( 'tracker_honor_privacy_signals' ) ),
        ) as $order => list( $id, $legend, $description, $settings ) ) {

            $this->registerSettingsFieldSet( array(
                'id'          => $id,
                'group'       => 'tracking_tag',
                'order'       => $order,
                'legend'      => $legend,
                'description' => $description,
                'settings'    => $settings,
            ) );
        }
    }

    /**
     * Register this module's actions against their controllers.
     *
     * Registration is the only way an action is reached: CoreAPI::performAction()
     * resolves a request's 'do' param through this table alone, and answers any
     * name not in it as a missing page.
     *
     * Class names are the PSR-4 names, which Composer autoloads.
     */
    public function registerActions() {

        $this->registerScopedSettingsFieldSets();

        $this->registerAction( 'base.addSiteRest',                   'OWA\\Module\\Base\\Controller\\AddSiteRest' );
        $this->registerAction( 'base.addUserRest',                   'OWA\\Module\\Base\\Controller\\AddUserRest' );
        $this->registerAction( 'base.changeUserPasswordCli',         'OWA\\Module\\Base\\Controller\\ChangeUserPasswordCli' );
        $this->registerAction( 'base.appPasswordCreateCli',          'OWA\\Module\\Base\\Controller\\AppPasswordCreateCli' );
        $this->registerAction( 'base.appPasswordListCli',            'OWA\\Module\\Base\\Controller\\AppPasswordListCli' );
        $this->registerAction( 'base.appPasswordRevokeCli',          'OWA\\Module\\Base\\Controller\\AppPasswordRevokeCli' );
        $this->registerAction( 'base.corsPreflight',                 'OWA\\Module\\Base\\Controller\\CorsPreflight' );
        $this->registerAction( 'base.deleteUserRest',                'OWA\\Module\\Base\\Controller\\DeleteUserRest' );
        $this->registerAction( 'base.cubeRebuildCli',                'OWA\\Module\\Base\\Controller\\CubeRebuildCli' );
        $this->registerAction( 'base.v1DropCli',                     'OWA\\Module\\Base\\Controller\\V1DropCli' );
        $this->registerAction( 'base.stringsExtractCli',             'OWA\\Module\\Base\\Controller\\StringsExtractCli' );
        $this->registerAction( 'base.updateUaRegexesCli',                 'OWA\\Module\\Base\\Controller\\UpdateUaRegexesCli' );
        $this->registerAction( 'base.installBase',                   'OWA\\Module\\Base\\Controller\\InstallBase' );
        $this->registerAction( 'base.installCheckEnv',               'OWA\\Module\\Base\\Controller\\InstallCheckEnv' );
        $this->registerAction( 'base.installCli',                    'OWA\\Module\\Base\\Controller\\InstallCli' );
        $this->registerAction( 'base.installConfig',                 'OWA\\Module\\Base\\Controller\\InstallConfig' );
        $this->registerAction( 'base.installDefaultsEntry',          'OWA\\Module\\Base\\Controller\\InstallDefaultsEntry' );
        $this->registerAction( 'base.installFinish',                 'OWA\\Module\\Base\\Controller\\InstallFinish' );
        $this->registerAction( 'base.installStart',                  'OWA\\Module\\Base\\Controller\\InstallStart' );
        $this->registerAction( 'base.login',                         'OWA\\Module\\Base\\Controller\\Login' );
        $this->registerAction( 'base.loginForm',                     'OWA\\Module\\Base\\Controller\\LoginForm' );
        $this->registerAction( 'base.logout',                        'OWA\\Module\\Base\\Controller\\Logout' );
        $this->registerAction( 'base.moduleActivate',                'OWA\\Module\\Base\\Controller\\ModuleActivate' );
        $this->registerAction( 'base.moduleActivateCli',             'OWA\\Module\\Base\\Controller\\ModuleActivateCli' );
        $this->registerAction( 'base.moduleDeactivate',              'OWA\\Module\\Base\\Controller\\ModuleDeactivate' );
        $this->registerAction( 'base.moduleDeactivateCli',           'OWA\\Module\\Base\\Controller\\ModuleDeactivateCli' );
        $this->registerAction( 'base.moduleInstallCli',              'OWA\\Module\\Base\\Controller\\ModuleInstallCli' );
        $this->registerAction( 'base.notifyNewSession',              'OWA\\Module\\Base\\Controller\\NotifyNewSession' );
        $this->registerAction( 'base.optionsGeneral',                'OWA\\Module\\Base\\Controller\\OptionsGeneral' );
        $this->registerAction( 'base.visualizations',                'OWA\\Module\\Base\\Controller\\Visualizations' );
        $this->registerAction( 'base.visualizationSave',             'OWA\\Module\\Base\\Controller\\VisualizationSave' );
        $this->registerAction( 'base.visualizationEdit',             'OWA\\Module\\Base\\Controller\\VisualizationEdit' );
        $this->registerAction( 'base.visualizationFunnel',           'OWA\\Module\\Base\\Controller\\VisualizationFunnel' );
        $this->registerAction( 'base.visualizationPreview',          'OWA\\Module\\Base\\Controller\\VisualizationPreview' );
        $this->registerAction( 'base.goalEventDefinitions',          'OWA\\Module\\Base\\Controller\\GoalEventDefinitions' );
        $this->registerAction( 'base.goalEventDefinitionEdit',       'OWA\\Module\\Base\\Controller\\GoalEventDefinitionEdit' );
        $this->registerAction( 'base.goalEventDefinitionSave',       'OWA\\Module\\Base\\Controller\\GoalEventDefinitionSave' );
        $this->registerAction( 'base.goalEventDefinitionDelete',     'OWA\\Module\\Base\\Controller\\GoalEventDefinitionDelete' );
        $this->registerAction( 'base.optionsModules',                'OWA\\Module\\Base\\Controller\\OptionsModules' );
        $this->registerAction( 'base.optionsReset',                  'OWA\\Module\\Base\\Controller\\OptionsReset' );
        $this->registerAction( 'base.optionsUpdate',                 'OWA\\Module\\Base\\Controller\\OptionsUpdate' );
        $this->registerAction( 'base.optionsRetention',              'OWA\\Module\\Base\\Controller\\OptionsRetention' );
        $this->registerAction( 'base.optionsRetentionUpdate',        'OWA\\Module\\Base\\Controller\\OptionsRetentionUpdate' );
        $this->registerAction( 'base.propertyRetention',             'OWA\\Module\\Base\\Controller\\PropertyRetention' );
        $this->registerAction( 'base.propertyProfiles',              'OWA\\Module\\Base\\Controller\\PropertyProfiles' );
        $this->registerAction( 'base.propertyRetentionUpdate',       'OWA\\Module\\Base\\Controller\\PropertyRetentionUpdate' );
        $this->registerAction( 'base.retentionPreviewRest',          'OWA\\Module\\Base\\Controller\\RetentionPreviewRest' );
        $this->registerAction( 'base.overlayLauncher',               'OWA\\Module\\Base\\Controller\\OverlayLauncher' );
        $this->registerAction( 'base.passwordResetForm',             'OWA\\Module\\Base\\Controller\\PasswordResetForm' );
        $this->registerAction( 'base.passwordResetRequest',          'OWA\\Module\\Base\\Controller\\PasswordResetRequest' );
        $this->registerAction( 'base.processEvent',                  'OWA\\Module\\Base\\Controller\\ProcessEvent' );
        $this->registerAction( 'base.trackerIngestReplayCli',        'OWA\\Module\\Base\\Controller\\TrackerIngestReplayCli' );
        $this->registerAction( 'base.trackerIngestProvisionCli',     'OWA\\Module\\Base\\Controller\\TrackerIngestProvisionCli' );
        $this->registerAction( 'base.drainTrackerIngestCli',         'OWA\\Module\\Base\\Controller\\DrainTrackerIngestCli' );
        $this->registerAction( 'base.processRequest',                'OWA\\Module\\Base\\Controller\\ProcessRequest' );
        $this->registerAction( 'base.pruneEventQueueArchivesCli',    'OWA\\Module\\Base\\Controller\\PruneEventQueueArchivesCli' );
        $this->registerAction( 'base.partitionStatusCli',            'OWA\\Module\\Base\\Controller\\PartitionStatusCli' );
        $this->registerAction( 'base.cubeStatus',                    'OWA\\Module\\Base\\Controller\\CubeStatus' );
        $this->registerAction( 'base.systemHealth',                  'OWA\\Module\\Base\\Controller\\SystemHealth' );
        $this->registerAction( 'base.cubeStatusDetail',              'OWA\\Module\\Base\\Controller\\CubeStatusDetail' );
        $this->registerAction( 'base.customDimensions',              'OWA\\Module\\Base\\Controller\\CustomDimensions' );
        $this->registerAction( 'base.customDimensionEdit',           'OWA\\Module\\Base\\Controller\\CustomDimensionEdit' );
        $this->registerAction( 'base.customDimensionSave',           'OWA\\Module\\Base\\Controller\\CustomDimensionSave' );
        $this->registerAction( 'base.customDimensionDelete',         'OWA\\Module\\Base\\Controller\\CustomDimensionDelete' );
        $this->registerAction( 'base.customDimensionListCli',        'OWA\\Module\\Base\\Controller\\CustomDimensionListCli' );
        $this->registerAction( 'base.customDimensionApplyCli',       'OWA\\Module\\Base\\Controller\\CustomDimensionApplyCli' );
        $this->registerAction( 'base.customDimensionRegisterCli',    'OWA\\Module\\Base\\Controller\\CustomDimensionRegisterCli' );
        $this->registerAction( 'base.customDimensionDeregisterCli',  'OWA\\Module\\Base\\Controller\\CustomDimensionDeregisterCli' );
        $this->registerAction( 'base.scheduleRunCli',                'OWA\\Module\\Base\\Controller\\ScheduleRunCli' );
        $this->registerAction( 'base.scheduleStatusCli',             'OWA\\Module\\Base\\Controller\\ScheduleStatusCli' );
        $this->registerAction( 'base.instanceInfoCli',              'OWA\\Module\\Base\\Controller\\InstanceInfoCli' );
        $this->registerAction( 'base.serverCheckCli',               'OWA\\Module\\Base\\Controller\\ServerCheckCli' );
        $this->registerAction( 'base.partitionDropCli',              'OWA\\Module\\Base\\Controller\\PartitionDropCli' );
        $this->registerAction( 'base.partitionReorganizeCli',        'OWA\\Module\\Base\\Controller\\PartitionReorganizeCli' );
        $this->registerAction( 'base.partitionRotateCli',            'OWA\\Module\\Base\\Controller\\PartitionRotateCli' );
        $this->registerAction( 'base.report',                        'OWA\\Module\\Base\\Controller\\Report' );
        $this->registerAction( 'base.reportsRest',                   'OWA\\Module\\Base\\Controller\\ReportsRest' );
        $this->registerAction( 'base.resetSecretsCli',               'OWA\\Module\\Base\\Controller\\ResetSecretsCli' );
        $this->registerAction( 'base.siteAddAllowedUserRest',        'OWA\\Module\\Base\\Controller\\SiteAddAllowedUserRest' );
        $this->registerAction( 'base.reportingHome',                 'OWA\\Module\\Base\\Controller\\ReportingHome' );
        $this->registerAction( 'base.propertyProfile',               'OWA\\Module\\Base\\Controller\\PropertyProfile' );
        $this->registerAction( 'base.organizationProfile',           'OWA\\Module\\Base\\Controller\\OrganizationProfile' );
        $this->registerAction( 'base.profileSettings',               'OWA\\Module\\Base\\Controller\\ProfileSettings' );
        $this->registerAction( 'base.propertyAccess',                'OWA\\Module\\Base\\Controller\\PropertyAccess' );
        $this->registerAction( 'base.propertyAccessUpdate',          'OWA\\Module\\Base\\Controller\\PropertyAccessUpdate' );
        $this->registerAction( 'base.propertyDelete',                'OWA\\Module\\Base\\Controller\\PropertyDelete' );
        $this->registerAction( 'base.propertyEdit',                  'OWA\\Module\\Base\\Controller\\PropertyEdit' );
        $this->registerAction( 'base.organizationEdit',              'OWA\\Module\\Base\\Controller\\OrganizationEdit' );
        $this->registerAction( 'base.customReports',                 'OWA\\Module\\Base\\Controller\\CustomReports' );
        $this->registerAction( 'base.customReportEdit',              'OWA\\Module\\Base\\Controller\\CustomReportEdit' );
        $this->registerAction( 'base.customReportMarkFavorite',      'OWA\\Module\\Base\\Controller\\CustomReportMarkFavorite' );
        $this->registerAction( 'base.customReportSave',              'OWA\\Module\\Base\\Controller\\CustomReportSave' );
        $this->registerAction( 'base.customReportDelete',            'OWA\\Module\\Base\\Controller\\CustomReportDelete' );
        $this->registerAction( 'base.sitesAdd',                      'OWA\\Module\\Base\\Controller\\SitesAdd' );
        $this->registerAction( 'base.sitesAddCli',                   'OWA\\Module\\Base\\Controller\\SitesAddCli' );
        $this->registerAction( 'base.sitesDelete',                   'OWA\\Module\\Base\\Controller\\SitesDelete' );
        $this->registerAction( 'base.sitesEdit',                     'OWA\\Module\\Base\\Controller\\SitesEdit' );
        $this->registerAction( 'base.sitesEditSettings',             'OWA\\Module\\Base\\Controller\\SitesEditSettings' );
        $this->registerAction( 'base.trackerPublishCli',             'OWA\\Module\\Base\\Controller\\TrackerPublishCli' );
        $this->registerAction( 'base.jobsCli',                       'OWA\\Module\\Base\\Controller\\JobsCli' );
        $this->registerAction( 'base.jobsRetryCli',                  'OWA\\Module\\Base\\Controller\\JobsRetryCli' );
        $this->registerAction( 'base.jobsForgetCli',                 'OWA\\Module\\Base\\Controller\\JobsForgetCli' );
        $this->registerAction( 'base.jobsPruneCli',                  'OWA\\Module\\Base\\Controller\\JobsPruneCli' );
        $this->registerAction( 'base.sitesEditTagSettings',          'OWA\\Module\\Base\\Controller\\SitesEditTagSettings' );
        $this->registerAction( 'base.sitesInvocation',               'OWA\\Module\\Base\\Controller\\SitesInvocation' );
        $this->registerAction( 'base.sitesProfile',                  'OWA\\Module\\Base\\Controller\\SitesProfile' );
        $this->registerAction( 'base.sitesRest',                     'OWA\\Module\\Base\\Controller\\SitesRest' );
        $this->registerAction( 'base.updates',                       'OWA\\Module\\Base\\Controller\\Updates' );
        $this->registerAction( 'base.updatesApply',                  'OWA\\Module\\Base\\Controller\\UpdatesApply' );
        $this->registerAction( 'base.notificationsRest',              'OWA\\Module\\Base\\Controller\\NotificationsRest' );
        $this->registerAction( 'base.realtimeRest',                  'OWA\\Module\\Base\\Controller\\RealtimeRest' );
        $this->registerAction( 'base.reportRealtime',                'OWA\\Module\\Base\\Controller\\ReportRealtime' );
        $this->registerAction( 'base.notificationMarkReadRest',           'OWA\\Module\\Base\\Controller\\NotificationMarkReadRest' );
        $this->registerAction( 'base.notificationDismissRest',       'OWA\\Module\\Base\\Controller\\NotificationDismissRest' );
        $this->registerAction( 'base.notificationsFetchCli',        'OWA\\Module\\Base\\Controller\\NotificationsFetchCli' );
        $this->registerAction( 'base.updatesApplyCli',               'OWA\\Module\\Base\\Controller\\UpdatesApplyCli' );
        $this->registerAction( 'base.users',                         'OWA\\Module\\Base\\Controller\\Users' );
        $this->registerAction( 'base.usersAdd',                      'OWA\\Module\\Base\\Controller\\UsersAdd' );
        $this->registerAction( 'base.usersChangePassword',           'OWA\\Module\\Base\\Controller\\UsersChangePassword' );
        $this->registerAction( 'base.usersDelete',                   'OWA\\Module\\Base\\Controller\\UsersDelete' );
        $this->registerAction( 'base.usersEdit',                     'OWA\\Module\\Base\\Controller\\UsersEdit' );
        $this->registerAction( 'base.usersNewAccount',               'OWA\\Module\\Base\\Controller\\UsersNewAccount' );
        $this->registerAction( 'base.usersPasswordEntry',            'OWA\\Module\\Base\\Controller\\UsersPasswordEntry' );
        $this->registerAction( 'base.usersProfile',                  'OWA\\Module\\Base\\Controller\\UsersProfile' );
        $this->registerAction( 'base.myProfile',                 'OWA\\Module\\Base\\Controller\\MyProfile' );
        $this->registerAction( 'base.myProfileSave',             'OWA\\Module\\Base\\Controller\\MyProfileSave' );
        $this->registerAction( 'base.myAppPasswords',            'OWA\\Module\\Base\\Controller\\MyAppPasswords' );
        $this->registerAction( 'base.myAppPasswordCreateAjax',   'OWA\\Module\\Base\\Controller\\MyAppPasswordCreateAjax' );
        $this->registerAction( 'base.myAppPasswordRevoke',       'OWA\\Module\\Base\\Controller\\MyAppPasswordRevoke' );
        $this->registerAction( 'base.userAppPasswordRevoke',     'OWA\\Module\\Base\\Controller\\UserAppPasswordRevoke' );
        $this->registerAction( 'base.usersResetPassword',            'OWA\\Module\\Base\\Controller\\UsersResetPassword' );
        $this->registerAction( 'base.usersRest',                     'OWA\\Module\\Base\\Controller\\UsersRest' );
        $this->registerAction( 'base.usersSetPassword',              'OWA\\Module\\Base\\Controller\\UsersSetPassword' );
    }

    /**
     * Register CLI Commands
     *
     * The following lines register various command line interface (CLI) controller.
     */
    public function registerCliCommands() {

        $this->registerCliCommand('update', 'base.updatesApplyCli');
        $this->registerCliCommand('strings-extract', 'base.stringsExtractCli');
        $this->registerCliCommand('v1-drop', 'base.v1DropCli');
        $this->registerCliCommand('fetch-notifications', 'base.notificationsFetchCli');
        $this->registerCliCommand('update-ua-regexes', 'base.updateUaRegexesCli');
        $this->registerCliCommand('drain-tracker-ingest', 'base.drainTrackerIngestCli');
        $this->registerCliCommand('tracker-ingest-replay', 'base.trackerIngestReplayCli');
        $this->registerCliCommand('tracker-ingest-provision', 'base.trackerIngestProvisionCli');
        $this->registerCliCommand('install', 'base.installCli');
        $this->registerCliCommand('activate', 'base.moduleActivateCli');
        $this->registerCliCommand('deactivate', 'base.moduleDeactivateCli');
        $this->registerCliCommand('install-module', 'base.moduleInstallCli');
        $this->registerCliCommand('add-site', 'base.sitesAddCli');
        $this->registerCliCommand('prune-event-queue-archives', 'base.pruneEventQueueArchivesCli');
        $this->registerCliCommand('partition-status', 'base.partitionStatusCli');
        $this->registerCliCommand('partition-drop', 'base.partitionDropCli');
        $this->registerCliCommand('partition-reorganize', 'base.partitionReorganizeCli');
        $this->registerCliCommand('partition-rotate', 'base.partitionRotateCli');
        $this->registerCliCommand('change-password', 'base.changeUserPasswordCli');
        $this->registerCliCommand('app-password-create', 'base.appPasswordCreateCli');
        $this->registerCliCommand('app-password-list', 'base.appPasswordListCli');
        $this->registerCliCommand('app-password-revoke', 'base.appPasswordRevokeCli');
        $this->registerCliCommand('reset-secrets', 'base.resetSecretsCli');
        $this->registerCliCommand('schedule-run', 'base.scheduleRunCli');
        $this->registerCliCommand('schedule-status', 'base.scheduleStatusCli');
        $this->registerCliCommand('instance-info', 'base.instanceInfoCli');
        $this->registerCliCommand('server-check', 'base.serverCheckCli');
        $this->registerCliCommand('cube-rebuild', 'base.cubeRebuildCli');
        $this->registerCliCommand('jobs', 'base.jobsCli');
        $this->registerCliCommand('jobs-retry', 'base.jobsRetryCli');
        $this->registerCliCommand('jobs-forget', 'base.jobsForgetCli');
        $this->registerCliCommand('jobs-prune', 'base.jobsPruneCli');
        $this->registerCliCommand('publish-trackers', 'base.trackerPublishCli');
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
     * Each job below says why it ships; ScheduleCliTest names the list
     * exactly, so adding one is a decision rather than a detail. An
     * installation retunes or turns one off in OWA_SCHEDULED_JOBS -- see
     * Settings::applyConfigConstants().
     */
    public function registerJobs() {

        // The NAME is deliberately not the command name. They are separate
        // fields -- a command can be scheduled more than once under different
        // names -- and when the only shipped job used the same string for both,
        // nothing in the documentation could show which one OWA_SCHEDULED_JOBS
        // keys on. It keys on the name.
        //
        // Registered with EMPTY params. partition-rotate takes no retention
        // argument: it applies raw_retention_months and each Property's
        // cube_retention_months, both 0 (keep everything) by default, so an
        // installation deletes history only once an administrator sets one.
        // ScheduleCliTest pins the empty array.
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
            \OWA\Core\Cron::dailySpreadFor( $this->jobSeed( 'rotate-partitions' ) ), array(),
            'Keeps the event and reporting tables partitioned ahead of the calendar, and merges old periods '
          . 'so the tables stay within the server\'s open-file limit. Deletes nothing unless told to keep '
          . 'less history (raw_retention_months, and cube_retention_months per Property).' );

        /*
         * The tracking intake's drain (PLAN 2.30.4), every minute, on the
         * servers whose role runs the scheduler. A minute with nothing queued
         * costs a few stat() calls (isProbablyEmpty()).
         */
        $this->registerJob( 'drain-tracker-ingest', 'drain-tracker-ingest', '* * * * *', array( 'scheduled' => 1 ),
            'Ingests the beacons waiting in the tracker-ingest queue: every beacon when queueing is on, and '
          . 'any whose write failed and is waiting to be retried. First forwards any beacons the AWS SQS queue '
          . 'refused and the file queue kept. A minute with nothing waiting costs a few file checks.' );

        /*
         * Dead letters back to the intake once each, daily (PLAN 2.30.4): a
         * beacon that died in an outage longer than the back-off gets one more
         * round, and one already replayed is kept for cmd=tracker-ingest-replay:
         * on disk until it is replayed, on SQS for the queue's fourteen-day
         * retention. A dead letter older than raw's retention window is removed
         * instead (TrackerIngest::replay()).
         */
        $this->registerJob( 'replay-tracker-ingest', 'tracker-ingest-replay',
            \OWA\Core\Cron::dailySpreadFor( $this->jobSeed( 'replay-tracker-ingest' ) ), array( 'scheduled' => 1 ),
            'Gives each beacon in the tracker-ingest dead-letter queue one more try, once: one that failed '
          . 'during an outage longer than the retries gets ingested after all. One already replayed is kept '
          . 'for cmd=tracker-ingest-replay: in the file queue until it is replayed, in AWS SQS for the queue\'s '
          . 'fourteen-day retention, after which SQS deletes it. One older than raw_retention_months is deleted instead.' );

        /*
         * The intake's archived batches (archive_old_events), daily, under
         * raw's retention window: a batch is kept as long as the events in it
         * would be, so with raw_retention_months unset nothing is deleted.
         * By hand, cmd=prune-event-queue-archives interval= prunes by age.
         */
        $this->registerJob( 'prune-tracker-ingest-archive', 'prune-event-queue-archives',
            \OWA\Core\Cron::dailySpreadFor( $this->jobSeed( 'prune-tracker-ingest-archive' ) ), array( 'scheduled' => 1 ),
            'Deletes the tracking intake\'s archived batch files older than raw_retention_months, the window '
          . 'event data is kept for. Deletes nothing when that is not set.' );

        // Finished one-off jobs (PLAN 2.30.5): done after 7 days, failed after 30.
        $this->registerJob(
            'prune-job-queue', 'jobs-prune',
            \OWA\Core\Cron::dailySpreadFor( $this->jobSeed( 'prune-job-queue' ) ), array(),
            sprintf( 'Deletes finished one-off jobs from the job queue: done ones after %d days, failed ones after %d.',
                \OWA\Module\Base\Classes\JobQueue::KEEP_DONE / 86400, \OWA\Module\Base\Classes\JobQueue::KEEP_FAILED / 86400 ) );

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
            \OWA\Core\Cron::minutelySpreadFor( $this->jobSeed( 'rebuild-cube' ), 5 ), array(),
            'Builds each Property\'s reporting cube from new events, so reports are at most a few minutes '
          . 'behind. Skips the days that have not changed.' );

        /*
         * No job for Profiles' tracking bundles (PLAN 2.30.7). A save publishes
         * its own -- a Profile's at once, anything wider as a queued
         * publish-trackers job -- and a new release is applied with
         * cmd=update, which republishes what it changed (update()).
         */

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
            array(),
            'Adds the columns for newly registered custom dimensions to the reporting cubes. Adding one '
          . 'rebuilds the table, which is too slow for the request that registered it. With nothing pending '
          . 'it is one quick read.' );

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
            \OWA\Core\Cron::dailySpreadFor( $this->jobSeed( 'fetch-notifications' ) ), array(),
            'Fetches OWA\'s release announcements for the notifications bell, once a day.' );

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
    public function registerApiMethods() {

    	$this->registerRestApiRoute( 'v1', 'sites', 'GET', 'OWA\\Module\\Base\\Controller\\SitesRest' );
        $this->registerRestApiRoute( 'v1', 'sites', 'POST', 'OWA\\Module\\Base\\Controller\\AddSiteRest' );
        $this->registerRestApiRoute( 'v1', 'users', 'GET', 'OWA\\Module\\Base\\Controller\\UsersRest' );
        $this->registerRestApiRoute( 'v1', 'users', 'POST', 'OWA\\Module\\Base\\Controller\\AddUserRest' );
		$this->registerRestApiRoute( 'v1', 'users', 'DELETE', 'OWA\\Module\\Base\\Controller\\DeleteUserRest', [ 'params_order' => ['user_id'] ] );
		$this->registerRestApiRoute( 'v1', 'siteUsers', 'POST', 'OWA\\Module\\Base\\Controller\\SiteAddAllowedUserRest' );
		$this->registerRestApiRoute( 'v1', 'notifications', 'GET', 'OWA\\Module\\Base\\Controller\\NotificationsRest' );
		$this->registerRestApiRoute( 'v1', 'realtime', 'GET', 'OWA\\Module\\Base\\Controller\\RealtimeRest' );
		$this->registerRestApiRoute( 'v1', 'notifications', 'POST', 'OWA\\Module\\Base\\Controller\\NotificationMarkReadRest', [ 'params_order' => ['notificationId'] ] );
		$this->registerRestApiRoute( 'v1', 'notifications', 'DELETE', 'OWA\\Module\\Base\\Controller\\NotificationDismissRest', [ 'params_order' => ['notificationId'] ] );
		$this->registerRestApiRoute( 'v1', 'reports', 'GET', 'OWA\\Module\\Base\\Controller\\ReportsRest', [ 'params_order' => ['report_name'] ] );
		$this->registerRestApiRoute( 'v1', 'retentionPreview', 'GET', 'OWA\\Module\\Base\\Controller\\RetentionPreviewRest' );
    }

    /**
     * Registers Admin panels
     *
     */
    public function registerAdminPanels() {

        $this->registerSettingsPage(array(
                'do'             => 'base.optionsGeneral',
                'title'          => \OWA\Core\CoreAPI::t( 'General' ),
                'group'          => 'General',
                'order'          => 1,
                'fieldsets'      => array(
                    'base.tracking', 'base.announcements', 'base.reporting', 'base.restApi' ),
                'fieldset_groups' => array( 'tracking_tag' ) )
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
            'legend'   => \OWA\Core\CoreAPI::t( 'Tracking Request Processing' ),
            'settings' => array(
                'spam_detection',
                'spam_threshold',
                'log_named_users',
                'excluded_ips',
                'anonymize_ips',
                'query_string_filters',
                'campaignUtmParams',
            ),
        ) );

        $this->registerSettingsFieldSet( array(
            'id'       => 'base.announcements',
            'legend'   => \OWA\Core\CoreAPI::t( 'Visitor Announcements' ),
            'settings' => array( 'announce_visitors', 'notice_email' ),
        ) );

        $this->registerSettingsFieldSet( array(
            'id'       => 'base.reporting',
            'legend'   => \OWA\Core\CoreAPI::t( 'Reporting' ),
            'settings' => array( 'timezone', 'attribution_lookback_days' ),
        ) );

        // The group is what the wiki's Application Passwords page generates its
        // settings table from (settings-rest_api); no page renders it by group.
        $this->registerSettingsFieldSet( array(
            'id'       => 'base.restApi',
            'group'    => 'rest_api',
            'legend'   => \OWA\Core\CoreAPI::t( 'REST API' ),
            'settings' => array( 'app_passwords_enabled', 'app_passwords_require_https' ),
        ) );

        // How long data is kept (Classes\Retention). Its own page: lowering the
        // first of these deletes data, and that should not sit among routine settings.
        $this->registerSettingsPage( array(
                'do'        => 'base.optionsRetention',
                'title'     => \OWA\Core\CoreAPI::t( 'Data retention' ),
                'group'     => 'General',
                'order'     => 6,
                'fieldsets' => array( 'base.retention' ) )
        );

        $this->registerSettingsFieldSet( array(
            'id'       => 'base.retention',
            'legend'   => \OWA\Core\CoreAPI::t( 'Data Retention' ),
            'description' => \OWA\Core\CoreAPI::t( 'Each night\'s maintenance run (rotate-partitions) removes data outside these windows. '
                . 'Every Property can set its own reporting window on its Data Retention page.' ),
            'settings' => array( 'raw_retention_months', 'cube_retention_months' ),
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
                'title'          => \OWA\Core\CoreAPI::t( 'Modules' ),
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
                'title'          => \OWA\Core\CoreAPI::t( 'Reporting cubes' ),
                'group'          => 'General',
                'order'          => 4)
        );

        // Is the background work happening: the scheduler, its jobs, the job queue, the tracker intake.
        $this->registerSettingsPage(array(
                'do'             => 'base.systemHealth',
                'title'          => \OWA\Core\CoreAPI::t( 'System health' ),
                'group'          => 'General',
                'order'          => 5)
        );

        /*
        */
    }


    /**
     * And the v1 dimension vocabulary, removed for the same reason.
     *
     * config/dimensions.php declares v2's, every one a column read against the
     * cube. Report configs naming an old name are caught by the report
     * characterization tests rather than by a runtime fallback.
     */
    public function registerDimensions() {}

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
    public function registerReports() {

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
        $this->registerReport( 'realtime', array(
            'controller'  => 'base.reportRealtime',
            'description' => 'Activity on the Property in the last thirty minutes, read from incoming events as they arrive and refreshed while the page is open.',
        ) );
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
        /*
         * FORMS STARTED AND SUBMITTED, by the page the form was on. Grouped by
         * page and not by form: form_id, form_name and form_destination ride
         * `params`, so grouping by one is a custom dimension the install
         * registers.
         */
        $this->registerReport( 'forms', 'reports/forms.json' );
        $this->registerReport( 'devices', 'reports/devices.json' );
        $this->registerReport( 'ecommerce', 'reports/ecommerce.json' );
        $this->registerReport( 'ecommerce-conversion-rate', 'reports/ecommerce-conversion-rate.json' );
        $this->registerReport( 'entry-pages', 'reports/entry-pages.json' );
        $this->registerReport( 'exit-pages', 'reports/exit-pages.json' );
        $this->registerReport( 'geolocation', 'reports/geolocation.json' );
        $this->registerReport( 'goals', 'reports/goals.json' );
        $this->registerReport( 'keywords', 'reports/keywords.json' );
        $this->registerReport( 'languages', 'reports/languages.json' );
        $this->registerReport( 'latest-visits', 'reports/latest-visits.json' );
        $this->registerReport( 'os', 'reports/os.json' );
        $this->registerReport( 'page-types', 'reports/page-types.json' );
        $this->registerReport( 'pages', 'reports/pages.json' );
        $this->registerReport( 'referring-sites', 'reports/referring-sites.json' );
        $this->registerReport( 'revenue', 'reports/revenue.json' );
        $this->registerReport( 'screen-resolutions', 'reports/screen-resolutions.json' );
        $this->registerReport( 'search-engines', 'reports/search-engines.json' );
        $this->registerReport( 'sources', 'reports/sources.json' );
        $this->registerReport( 'traffic', 'reports/traffic.json' );
        $this->registerReport( 'transactions', 'reports/transactions.json' );
        $this->registerReport( 'user-acquisition', 'reports/user-acquisition.json' );
        $this->registerReport( 'visitors', 'reports/visitors.json' );
        $this->registerReport( 'visitors-loyalty', 'reports/visitors-loyalty.json' );
    }

    public function registerNavigation() {

        $this->addNavigationSubGroup('Dashboard', $this->reportRef( 'dashboard' ), \OWA\Core\CoreAPI::t( 'Dashboard' ), 1, 'view_reports', 'Reports','fa fa-tachometer-alt');

        // Second, after the dashboard.
        $this->addNavigationSubGroup('Realtime', $this->reportRef( 'realtime' ), \OWA\Core\CoreAPI::t( 'Realtime' ), 2, 'view_reports', 'Reports','fa fa-bolt');

        /*
         * The custom report roster. Gated on view_reports rather than on
         * edit_reports, because a reader who cannot author one can still be
         * sent one -- the roster is where they find what they have been given.
         */
        $this->addNavigationSubGroup('Custom Reports', array( 'do' => 'base.customReports' ),
            \OWA\Core\CoreAPI::t( 'Custom Reports' ), 9, 'view_site_list', 'Reports', 'fa fa-sliders-h');

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
        $this->addNavigationSubGroup('Ecommerce', $this->reportRef( 'ecommerce' ), \OWA\Core\CoreAPI::t( 'Ecommerce' ), 6, 'view_reports_ecommerce', 'Reports','fa fa-shopping-cart');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'revenue' ), \OWA\Core\CoreAPI::t( 'Revenue' ), 2, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'transactions' ), \OWA\Core\CoreAPI::t( 'Transactions' ), 3, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'avg-order-value' ), \OWA\Core\CoreAPI::t( 'Average Order Value' ), 4, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'ecommerce-conversion-rate' ), \OWA\Core\CoreAPI::t( 'Conversion Rate' ), 5, 'view_reports_ecommerce');
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
        $this->addNavigationSubGroup('Content', $this->reportRef( 'content' ), \OWA\Core\CoreAPI::t( 'Content' ), 5, 'view_reports', 'Reports','fa fa-newspaper');
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'pages' ), \OWA\Core\CoreAPI::t( 'Pages' ), 1);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'page-types' ), \OWA\Core\CoreAPI::t( 'Page Types' ), 2);
        /*
         * The Feeds link was here. Feed requests are not a v2 event -- the type
         * is refused at the door -- and nothing has written one since 2021. A
         * nav entry to a report with no data source is worse than no entry.
         */
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'entry-pages' ), \OWA\Core\CoreAPI::t( 'Entry Pages' ), 3);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'exit-pages' ), \OWA\Core\CoreAPI::t( 'Exit Pages' ), 4);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'clicks' ), \OWA\Core\CoreAPI::t( 'Clicks' ), 5);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'scroll-depth' ), \OWA\Core\CoreAPI::t( 'Scroll Depth' ), 6);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'site-search' ), \OWA\Core\CoreAPI::t( 'Site Search' ), 7);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'forms' ), \OWA\Core\CoreAPI::t( 'Forms' ), 8);


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
        $this->addNavigationSubGroup( 'Visitors', $this->reportRef( 'visitors' ), \OWA\Core\CoreAPI::t( 'Visitors' ), 4, 'view_reports', 'Reports','fa fa-user-friends');
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'geolocation' ), \OWA\Core\CoreAPI::t( 'Geo-location' ), 1);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'languages' ), \OWA\Core\CoreAPI::t( 'Languages' ), 2);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'visitors-loyalty' ), \OWA\Core\CoreAPI::t( 'Visitor Loyalty' ), 3);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'devices' ), \OWA\Core\CoreAPI::t( 'Device Types' ), 4);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'screen-resolutions' ), \OWA\Core\CoreAPI::t( 'Screen Resolutions' ), 5);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'browsers' ), \OWA\Core\CoreAPI::t( 'Browser Types' ), 6);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'os' ), \OWA\Core\CoreAPI::t( 'Operating Systems' ), 7);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'latest-visits' ), \OWA\Core\CoreAPI::t( 'Latest Visits' ), 8);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'events' ), \OWA\Core\CoreAPI::t( 'Events' ), 9);

        //Traffic
        $this->addNavigationSubGroup('Traffic', $this->reportRef( 'traffic' ), \OWA\Core\CoreAPI::t( 'Traffic' ), 3, 'view_reports', 'Reports','fa fa-random');
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'channels' ), \OWA\Core\CoreAPI::t( 'Channels' ), 0);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'user-acquisition' ), \OWA\Core\CoreAPI::t( 'User Acquisition' ), 2);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'model-comparison' ), \OWA\Core\CoreAPI::t( 'Model Comparison' ), 7);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'keywords' ), \OWA\Core\CoreAPI::t( 'Search Terms' ), 1);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'search-engines' ), \OWA\Core\CoreAPI::t( 'Search Engines' ), 3);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'referring-sites' ), \OWA\Core\CoreAPI::t( 'Referring Web Sites' ), 4);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'campaigns' ), \OWA\Core\CoreAPI::t( 'Campaigns' ), 5);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'ads' ), \OWA\Core\CoreAPI::t( 'Ad Performance' ), 6);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'creative-performance' ), \OWA\Core\CoreAPI::t( 'Creative Performance' ), 8);

        //Goals
        $this->addNavigationSubGroup('Goals', $this->reportRef( 'goals' ), \OWA\Core\CoreAPI::t( 'Goals' ), 7, 'view_reports', 'Reports','fa fa-bullseye');

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
    public function _registerEventHandlers() {

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
         *   - v1's GOALS. conversionHandlers evaluated them into owa_session's
         *     goal_N columns. v2 adds a goal event, a row of its own, at ingest
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
        // Install-level tag settings saved: every Profile's bundle may change.
        $this->registerEventHandler('base.install_settings_saved', $this, 'tagSettingsSavedHandler');
        // User management
        $this->registerEventHandler(array('base.set_password', 'base.reset_password', 'base.new_user_account'), 'userHandlers');
    }

    public function _registerEventProcessors() {

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

    public function _registerEntities() {

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
            'goal_event_definition',
            'goal_event_definition_condition',
                'user',
                'scheduled_job',
                'notification',
                'notification_state',
                'custom_report',
                'custom_report_favorite',
                'job_lock',
                // One-off admin jobs, run by the scheduler (PLAN 2.30.5).
                'job_queue',
                // Application passwords for the REST API.
                'app_password',
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
                // Beacons spam detection blocked, per day, Profile and rule (Classes\Spam\Blocks).
                'spam_block',
                // How far the v1 migration has got, and what it wrote
                // (Classes\Migration).
                'migration_progress',
                'migration_tally',
                'migration_day_visitor')
            );

    }

    /**
     * Queue one publish of every bundle when a save at install level touched
     * a tag setting -- Base's or an active module's, anything in a
     * `tracking_tag` fieldset (PLAN 2.30.7).
     *
     * @param  \OWA\Module\Base\Classes\Event $event the settings save: its module and keys
     * @return int an OWA_EHS_ handler status
     */
    public function tagSettingsSavedHandler( $event ) {

        $module = (string) $event->get( 'module' );
        $keys   = (array) $event->get( 'keys' );

        foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'tracking_tag' ) as $set ) {

            if ( ( $set['module'] ?? '' ) === $module
                 && array_intersect( $keys, (array) ( $set['settings'] ?? array() ) ) ) {

                \OWA\Module\Base\Classes\TrackerBundle::scheduleFullPublish();

                break;
            }
        }

        return OWA_EHS_EVENT_HANDLED;
    }

    /**
     * @param  \OWA\Module\Base\Classes\Event $event
     * @return void
     */
    public function installCompleteHandler($event) {

        //owa_coreAPI::debug('test handler: '.print_r($event, true));
    }
    

}


?>
