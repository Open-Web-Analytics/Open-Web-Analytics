<?php
namespace OWA\Module\Base;

/**
 * Base Package Module
 */

class Module extends \OWA\Core\Module {

    const NAME = 'base';

    const ENTITIES = [
        'site',
        'organization',
        'property',
        /*
         * 'configuration' is NOT here: Update043 unpacked that table into
         * install-scope rows of owa_setting and dropped it, and its presence is what
         * tells Settings the blob is still the store of record.
         *
         * The entity class stays: the rollback recreates the table, and entityFactory
         * resolves by PSR-4 rather than from this list.
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
        // One-off admin jobs, run by the scheduler.
        'job_queue',
        // Application passwords for the REST API.
        'app_password',
        // Failed sign-ins (Classes\LoginThrottle).
        'login_failure',
        'site_user',
        /*
         * v2. Registered unconditionally so cmd=core-update-schema creates them and
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
        'migration_day_visitor',
    ];

    /*
     * Declared, not set in the constructor, so cmd=version-info reads it
     * without constructing the module (Core\VersionInfo).
     */
    public $required_schema_version = 99;

    /**
     * Constructor
     *
     */
    public function __construct() {

        $this->display_name = 'Open Web Analytics';
        $this->group = 'Base';
        $this->author = 'Peter Adams';
        $this->version = '11';
        $this->description = 'Base functionality for OWA.';
        $this->config_required = false;
        parent::__construct();
    }

    /**
     * The schema 1.14.0 left behind, and the oldest this release upgrades.
     *
     * v2's updates start at 34, so an older installation upgrades to 1.14 first.
     */
    const OLDEST_UPGRADABLE_SCHEMA = 33;

    /**
     * Refuse an installation older than 1.14.0 before applying anything.
     *
     * No recorded version is refused too. Module::install() records one when it
     * creates every table, so its absence means tables made by something older.
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
         * What a new version needs beyond its schema: the
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
     * the one that last applied an update: the hash of the built
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
         * The tracking intake. Its type is written here and the
         * backend is the only plug: a queue type a module registers serves its
         * own queues, and that module's constant points this one at it
         * (TrackerIngest::backend()).
         */
        $this->registerEventQueue( \OWA\Module\Base\Classes\TrackerIngest::QUEUE, [

            'queue_type'   => 'file',
            'path'         => \OWA\Core\CoreAPI::getSetting( 'base', 'async_log_dir' ),
            'inbox'        => \OWA\Module\Base\Classes\TrackerIngest::inboxDir(),
            'max_receives' => \OWA\Module\Base\Classes\TrackerIngest::MAX_RECEIVES,
        ]);

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
            [ '\OWA\Module\Base\Classes\Beacon\Compat', 'contributeClientProperties' ] );

        \OWA\Core\CoreAPI::registerFilter( 'tracking_properties_derived',
            [ '\OWA\Module\Base\Classes\Beacon\Compat', 'contributeDerivedProperties' ] );

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
            [ '\OWA\Module\Base\Classes\MaterializedEvents', 'sessionStart' ], 10 );

        \OWA\Core\CoreAPI::registerFilter(
            \OWA\Module\Base\Classes\Ingest::TRACKING_EVENTS_PRE_SAVE,
            [ '\OWA\Module\Base\Classes\MaterializedEvents', 'firstVisit' ], 10 );

        \OWA\Core\CoreAPI::registerFilter(
            \OWA\Module\Base\Classes\Ingest::TRACKING_EVENTS_PRE_SAVE,
            [ '\OWA\Module\Base\Classes\PurchaseDeduplication', 'drop' ], 50 );

        \OWA\Core\CoreAPI::registerFilter(
            \OWA\Module\Base\Classes\Ingest::TRACKING_EVENTS_PRE_SAVE,
            [ '\OWA\Module\Base\Classes\GoalMarking', 'mark' ], 100 );
    }


    /**
     * Fieldsets rendered below the install (config/scoped_fieldsets.php).
     *
     * Registered with the actions, not in registerAdminPanels(): that runs only
     * when the admin nav is built, and the Profile and Property screens and
     * their save controllers read these without building it.
     *
     * @return void
     */
    public function registerScopedSettingsFieldSets() {

        $this->registerSettingsFieldSetsFromConfig( 'scoped_fieldsets' );
    }

    /**
     * Register this module's actions against their controllers (config/actions.php).
     *
     * Registration is the only way an action is reached: CoreAPI::performAction()
     * resolves a request's 'do' param through this table alone, and answers any
     * name not in it as a missing page.
     */
    public function registerActions() {

        $this->registerScopedSettingsFieldSets();
        $this->registerActionsFromConfig();
    }

    /**
     * Register CLI commands (config/cli_commands.php).
     */
    public function registerCliCommands() {

        $this->registerCliCommandsFromConfig();
    }

    /**
     * Register scheduled jobs (config/jobs.php), run by cmd=schedule-run.
     */
    public function registerJobs() {

        $this->registerJobsFromConfig();
    }

    /**
     * Register REST API routes (config/rest_routes.php).
     */
    public function registerApiMethods() {

        $this->registerRestApiRoutesFromConfig();
    }

    /**
     * Register the install-wide settings screens and their fieldsets
     * (config/admin_panels.php).
     */
    public function registerAdminPanels() {

        $this->registerAdminPanelsFromConfig();
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
        $this->registerReport( 'realtime', [
            'controller'  => 'base.reportRealtime',
            'description' => 'Activity on the Property in the last thirty minutes, read from incoming events as they arrive and refreshed while the page is open.',
        ] );
        $this->registerReport( 'document', 'reports/document.json' );
        /*
         * EVENTS, grouped by name. A tracked action is a `custom_event` row like any
         * other event, and what separates it from a page view is event_type -- the
         * column `eventName` is registered against. So "what happened on this site,
         * and how often" is one report over one dimension.
         *
         * An action's own name, label and group ride `params`, and params ARE the
         * custom dimensions -- site-defined keys, promoted to a cube column by
         * Classes\Cube\Dimensions.
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

    /**
     * Register the report nav (config/navigation.php).
     */
    public function registerNavigation() {

        $this->registerNavigationFromConfig();
    }

    /*
     * The combined reporting stylesheet is produced by webpack (reportingCssConfig
     * in webpack.config.js).
     */

    /**
     * Registers Event Handlers with queue queue
     *
     */
    public function _registerEventHandlers() {

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

        // Install-level tag settings saved: every Profile's bundle may change.
        $this->registerEventHandler('base.install_settings_saved', $this, 'tagSettingsSavedHandler');
        // User management
        $this->registerEventHandler(['base.set_password', 'base.reset_password', 'base.new_user_account',
            'base.email_address_changed'], 'userHandlers');
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

    /**
     * Queue one publish of every bundle when a save at install level touched
     * a tag setting -- Base's or an active module's, anything in a
     * `tracking_tag` fieldset.
     *
     * @param  \OWA\Module\Base\Classes\Event $event the settings save: its module and keys
     * @return int an OWA_EHS_ handler status
     */
    public function tagSettingsSavedHandler( $event ) {

        $module = (string) $event->get( 'module' );
        $keys   = (array) $event->get( 'keys' );

        foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'tracking_tag' ) as $set ) {

            if ( ( $set['module'] ?? '' ) === $module
                 && array_intersect( $keys, (array) ( $set['settings'] ?? [] ) ) ) {

                \OWA\Module\Base\Classes\TrackerBundle::scheduleFullPublish();

                break;
            }
        }

        return OWA_EHS_EVENT_HANDLED;
    }

}
