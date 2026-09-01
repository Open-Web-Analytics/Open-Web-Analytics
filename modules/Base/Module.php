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
        $this->required_schema_version = 21;
        return parent::__construct();
    }

    function init() {

	    // create event queues

        // register queue type implementations
        $this->registerImplementation('event_queue_types', 'file', 'owa_fileEventQueue', 'classes/fileEventQueue.php');
        $this->registerImplementation('event_queue_types', 'database', 'owa_dbEventQueue', 'classes/dbEventQueue.php');
        $this->registerImplementation('event_queue_types', 'http', 'owa_httpEventQueue', 'classes/httpEventQueue.php');
        
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

        $regular = \OWA\Module\Base\Classes\TrackingEventHelpers::clientProperties();

        $this->registerTrackingProperties( 'regular', $regular );

        $derived = \OWA\Module\Base\Classes\TrackingEventHelpers::serverProperties();

        $this->registerTrackingProperties( 'derived', $derived );

     }

    /**
     * Register Filters
     *
     * The following lines register filter methods.
     */
    function registerFilters() {

        if ( defined( 'OWA_MAIL_EXCEPTIONS' ) ) {

            $this->registerFilter('post_processed_tracking_event', $this, 'checkEventForType');
        }

        $this->registerFilter('tracker_tag_cmds', $this, 'addTrackerCmds', 0);
    }

    function addTrackerCmds( $cmds ) {

        $cmds[] = "owa_cmds.push(['trackPageView']);";
        $cmds[] = "owa_cmds.push(['trackClicks']);";

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
    function registerActions() {

        $this->registerAction( 'base.addSiteRest',                   'OWA\\Module\\Base\\Controller\\AddSiteRest',                  'Controller/AddSiteRest.php' );
        $this->registerAction( 'base.addUserRest',                   'OWA\\Module\\Base\\Controller\\AddUserRest',                  'Controller/AddUserRest.php' );
        $this->registerAction( 'base.apiRequest',                    'OWA\\Module\\Base\\Controller\\ApiRequest',                   'Controller/ApiRequest.php' );
        $this->registerAction( 'base.changeUserPasswordCli',         'OWA\\Module\\Base\\Controller\\ChangeUserPasswordCli',        'Controller/ChangeUserPasswordCli.php' );
        $this->registerAction( 'base.corsPreflight',                 'OWA\\Module\\Base\\Controller\\CorsPreflight',                'Controller/CorsPreflight.php' );
        $this->registerAction( 'base.crawlDocumentCli',              'OWA\\Module\\Base\\Controller\\CrawlDocumentCli',             'Controller/CrawlDocumentCli.php' );
        $this->registerAction( 'base.deleteUserRest',                'OWA\\Module\\Base\\Controller\\DeleteUserRest',               'Controller/DeleteUserRest.php' );
        $this->registerAction( 'base.entityInstall',                 'OWA\\Module\\Base\\Controller\\EntityInstall',                'Controller/EntityInstall.php' );
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
        $this->registerAction( 'base.optionsGoalEdit',               'OWA\\Module\\Base\\Controller\\OptionsGoalEdit',              'Controller/OptionsGoalEdit.php' );
        $this->registerAction( 'base.optionsGoalEntry',              'OWA\\Module\\Base\\Controller\\OptionsGoalEntry',             'Controller/OptionsGoalEntry.php' );
        $this->registerAction( 'base.optionsGoals',                  'OWA\\Module\\Base\\Controller\\OptionsGoals',                 'Controller/OptionsGoals.php' );
        $this->registerAction( 'base.optionsModules',                'OWA\\Module\\Base\\Controller\\OptionsModules',               'Controller/OptionsModules.php' );
        $this->registerAction( 'base.optionsReset',                  'OWA\\Module\\Base\\Controller\\OptionsReset',                 'Controller/OptionsReset.php' );
        $this->registerAction( 'base.optionsUpdate',                 'OWA\\Module\\Base\\Controller\\OptionsUpdate',                'Controller/OptionsUpdate.php' );
        $this->registerAction( 'base.overlayLauncher',               'OWA\\Module\\Base\\Controller\\OverlayLauncher',              'Controller/OverlayLauncher.php' );
        $this->registerAction( 'base.passwordResetForm',             'OWA\\Module\\Base\\Controller\\PasswordResetForm',            'Controller/PasswordResetForm.php' );
        $this->registerAction( 'base.passwordResetRequest',          'OWA\\Module\\Base\\Controller\\PasswordResetRequest',         'Controller/PasswordResetRequest.php' );
        $this->registerAction( 'base.processEvent',                  'OWA\\Module\\Base\\Controller\\ProcessEvent',                 'Controller/ProcessEvent.php' );
        $this->registerAction( 'base.processEventQueue',             'OWA\\Module\\Base\\Controller\\ProcessEventQueue',            'Controller/ProcessEventQueue.php' );
        $this->registerAction( 'base.processFirstRequest',           'OWA\\Module\\Base\\Controller\\ProcessFirstRequest',          'Controller/ProcessFirstRequest.php' );
        $this->registerAction( 'base.processRequest',                'OWA\\Module\\Base\\Controller\\ProcessRequest',               'Controller/ProcessRequest.php' );
        $this->registerAction( 'base.pruneEventQueueArchivesCli',    'OWA\\Module\\Base\\Controller\\PruneEventQueueArchivesCli',   'Controller/PruneEventQueueArchivesCli.php' );
        $this->registerAction( 'base.partitionStatusCli',            'OWA\\Module\\Base\\Controller\\PartitionStatusCli',         'Controller/PartitionStatusCli.php' );
        $this->registerAction( 'base.rederiveDimensionIdsCli',       'OWA\\Module\\Base\\Controller\\RederiveDimensionIdsCli',    'Controller/RederiveDimensionIdsCli.php' );
        $this->registerAction( 'base.scheduleRunCli',                'OWA\\Module\\Base\\Controller\\ScheduleRunCli',             'Controller/ScheduleRunCli.php' );
        $this->registerAction( 'base.scheduleStatusCli',             'OWA\\Module\\Base\\Controller\\ScheduleStatusCli',          'Controller/ScheduleStatusCli.php' );
        $this->registerAction( 'base.partitionInitCli',              'OWA\\Module\\Base\\Controller\\PartitionInitCli',           'Controller/PartitionInitCli.php' );
        $this->registerAction( 'base.partitionDropCli',              'OWA\\Module\\Base\\Controller\\PartitionDropCli',           'Controller/PartitionDropCli.php' );
        $this->registerAction( 'base.partitionReorganizeCli',        'OWA\\Module\\Base\\Controller\\PartitionReorganizeCli',     'Controller/PartitionReorganizeCli.php' );
        $this->registerAction( 'base.partitionRotateCli',            'OWA\\Module\\Base\\Controller\\PartitionRotateCli',         'Controller/PartitionRotateCli.php' );
        $this->registerAction( 'base.report',                        'OWA\\Module\\Base\\Controller\\Report',                        'Controller/Report.php' );
        $this->registerAction( 'base.reportDomstreams',              'OWA\\Module\\Base\\Controller\\ReportDomstreams',             'Controller/ReportDomstreams.php' );
        $this->registerAction( 'base.reportGoalFunnel',              'OWA\\Module\\Base\\Controller\\ReportGoalFunnel',             'Controller/ReportGoalFunnel.php' );
        $this->registerAction( 'base.reportsRest',                   'OWA\\Module\\Base\\Controller\\ReportsRest',                  'Controller/ReportsRest.php' );
        $this->registerAction( 'base.resetSecretsCli',               'OWA\\Module\\Base\\Controller\\ResetSecretsCli',              'Controller/ResetSecretsCli.php' );
        $this->registerAction( 'base.siteAddAllowedUserRest',        'OWA\\Module\\Base\\Controller\\SiteAddAllowedUserRest',       'Controller/SiteAddAllowedUserRest.php' );
        $this->registerAction( 'base.sites',                         'OWA\\Module\\Base\\Controller\\Sites',                        'Controller/Sites.php' );
        $this->registerAction( 'base.propertyProfile',               'OWA\\Module\\Base\\Controller\\PropertyProfile',              'Controller/PropertyProfile.php' );
        $this->registerAction( 'base.organizationProfile',           'OWA\\Module\\Base\\Controller\\OrganizationProfile',          'Controller/OrganizationProfile.php' );
        $this->registerAction( 'base.propertyEdit',                  'OWA\\Module\\Base\\Controller\\PropertyEdit',                  'Controller/PropertyEdit.php' );
        $this->registerAction( 'base.organizationEdit',              'OWA\\Module\\Base\\Controller\\OrganizationEdit',              'Controller/OrganizationEdit.php' );
        $this->registerAction( 'base.customReports',                 'OWA\\Module\\Base\\Controller\\CustomReports',                'Controller/CustomReports.php' );
        $this->registerAction( 'base.customReportEdit',              'OWA\\Module\\Base\\Controller\\CustomReportEdit',             'Controller/CustomReportEdit.php' );
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
        $this->registerCliCommand('rederive-dimension-ids', 'base.rederiveDimensionIdsCli');
        $this->registerCliCommand('partition-init', 'base.partitionInitCli');
        $this->registerCliCommand('partition-drop', 'base.partitionDropCli');
        $this->registerCliCommand('partition-reorganize', 'base.partitionReorganizeCli');
        $this->registerCliCommand('partition-rotate', 'base.partitionRotateCli');
        $this->registerCliCommand('change-password', 'base.changeUserPasswordCli');
        $this->registerCliCommand('update-document', 'base.crawlDocumentCli');
        $this->registerCliCommand('reset-secrets', 'base.resetSecretsCli');
        $this->registerCliCommand('schedule-run', 'base.scheduleRunCli');
        $this->registerCliCommand('schedule-status', 'base.scheduleStatusCli');
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
        $this->registerJob( 'rotate-partitions', 'partition-rotate', '@monthly', array() );

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
        $seed = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' );

        if ( $seed === '' ) {

            $seed = defined( 'OWA_DIR' ) ? OWA_DIR : php_uname( 'n' );
        }

        $this->registerJob(
            'fetch-notifications', 'fetch-notifications',
            \OWA\Core\Cron::dailySpreadFor( $seed ), array() );

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
		$this->registerRestApiRoute( 'v1', 'notifications', 'POST', 'OWA\\Module\\Base\\Controller\\NotificationMarkReadRest', 'Controller/NotificationMarkReadRest.php', [ 'params_order' => ['notificationId'] ] );
		$this->registerRestApiRoute( 'v1', 'notifications', 'DELETE', 'OWA\\Module\\Base\\Controller\\NotificationDismissRest', 'Controller/NotificationDismissRest.php', [ 'params_order' => ['notificationId'] ] );
		$this->registerRestApiRoute( 'v1', 'reports', 'GET', 'OWA\\Module\\Base\\Controller\\ReportsRest', 'Controller/ReportsRest.php', [ 'params_order' => ['report_name'] ] );
    }

    /**
     * Registers Admin panels
     *
     */
    function registerAdminPanels() {

        $this->addAdminPanel(array(
                'do'             => 'base.optionsGeneral',
                'priviledge'     => 'admin',
                'anchortext'     => 'Main Configuration',
                'group'            => 'General',
                'order'            => 1)
        );

        $this->addAdminPanel(array(
                'do'             => 'base.users',
                'priviledge'     => 'admin',
                'anchortext'     => 'User Management',
                'group'            => 'General',
                'order'            => 2)
        );



        $this->addAdminPanel(array(
                'do'             => 'base.sites',
                'priviledge'     => 'admin',
                'anchortext'     => 'Tracked Sites',
                'group'            => 'General',
                'order'            => 3)
        );
        /*
         * No Properties panel. The hierarchy is navigated from the site control
         * above the report nav, which is where someone is when they need it;
         * an admin-menu roster was a second place to browse the same tree.
         * The per-Property edit screen is reached from that control.
         */

        $this->addAdminPanel(array(
                'do'             => 'base.optionsModules',
                'priviledge'     => 'admin',
                'anchortext'     => 'Modules',
                'group'            => 'General',
                'order'            => 3)
        );

        /*
        $this->addAdminPanel(array(
                'do'             => 'base.optionsGoals',
                'priviledge'     => 'admin',
                'anchortext'     => 'Goal Settings',
                'group'            => 'General',
                'order'            => 3)
        );
        */
    }


    /**
     * Register Metrics
     *
     * The following lines register various data metrics.
     */
    function registerMetrics() {

        $fact_table_entities = array(
            'base.session',
            'base.request',
            'base.action_fact',
            'base.domstream',
            'base.click',
            'base.commerce_transaction_fact',
            'base.commerce_line_item_fact'
        );

        // page views
        $this->registerMetricDefinition(array(
            'name'            => 'pageViews',
            'label'            => 'Page Views',
            'description'    => 'The total number of pages viewed.',
            'group'            => 'Site Usage',
            'entity'        => 'base.request',
            'metric_type'    => 'count',
            'data_type'        => 'integer',
            'column'        => 'id'

        ));

        $this->registerMetricDefinition(array(
            'name'            => 'pageViews',
            'label'            => 'Page Views',
            'description'    => 'The total number of pages viewed.',
            'group'            => 'Site Usage',
            'entity'        => 'base.session',
            'metric_type'    => 'sum',
            'data_type'        => 'integer',
            'column'        => 'num_pageviews'

        ));


        // unique visitors
        foreach($fact_table_entities as $factEntity ) {

            $this->registerMetricDefinition(array(
                'name'            => 'uniqueVisitors',
                'label'            => 'Unique Visitors',
                'description'    => 'The total number of unique visitors.',
                'group'            => 'Site Usage',
                'entity'        => $factEntity,
                'metric_type'    => 'distinct_count',
                'data_type'        => 'integer',
                'column'        => 'visitor_id'

            ));
            
            $this->registerMetricDefinition(array(
                'name'            => 'visitors',
                'label'            => 'Visitors',
                'description'    => 'The total number of visitors.',
                'group'            => 'Site Usage',
                'entity'        => $factEntity,
                'metric_type'    => 'count',
                'data_type'        => 'integer',
                'column'        => 'visitor_id'

            ));

        }

        // visits

        // owa_session uses a different column name and has it's own metric registration above.
        $this->registerMetricDefinition(array(
            'name'            => 'visits',
            'label'            => 'Visits',
            'description'    => 'The total number of visits/sessions.',
            'group'            => 'Site Usage',
            'entity'        => 'base.session',
            'metric_type'    => 'distinct_count', // 'count', 'distinct_count', 'sum', or 'calculated'
            'data_type'        => 'integer', // 'integer', 'currency'
            'column'        => 'id'

        ));

        $this->registerMetricDefinition(array(
            'name'            => 'visits',
            'label'            => 'Visits',
            'description'    => 'The total number of visits/sessions.',
            'group'            => 'Site Usage',
            'entity'        => 'base.request',
            'metric_type'    => 'distinct_count', // 'count', 'distinct_count', 'sum', or 'calculated'
            'data_type'        => 'integer', // 'integer', 'currency'
            'column'        => 'session_id'

        ));

        $this->registerMetricDefinition( array(
            'name'        => 'newVisitors',
            'label'       => 'New Visitors',
            'description' => 'The total number of new visitors',
            'group'       => 'Site Usage',
            'metric_type' => 'boolean_true_count',
            'data_type'   => 'integer',
            'entity'      => 'base.session',
            'column'      => 'is_new_visitor',
        ) );

        $this->registerMetric(
            'repeatVisitors',
            'base.repeatVisitors',
            '',
            'Repeat Visitors',
            'The total number of repeat visitors',
            'Site Usage'
        );

        $this->registerMetricDefinition( array(
            'name'        => 'bounces',
            'label'       => 'Bounces',
            'description' => 'The total number of visits with a single page view',
            'group'       => 'Site Usage',
            'metric_type' => 'boolean_true_count',
            'data_type'   => 'integer',
            'entity'      => 'base.session',
            'column'      => 'is_bounce',
        ) );

        $this->registerMetricDefinition( array(
            'name'              => 'visitDuration',
            'label'             => 'Visit Duration',
            'description'       => 'The average duration of visits.',
            'group'             => 'Site Usage',
            'metric_type'       => 'avg_difference',
            'data_type'         => 'timestamp',
            'entity'            => 'base.session',
            'column'            => 'last_req',
            'subtrahend_column' => 'timestamp',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'uniquePageViews',
            'label'       => 'Unique Page Views',
            'description' => 'The total number of unique pages viewed.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'entity'      => 'base.request',
            'column'      => 'document_id',
        ) );

        $this->registerMetricDefinition( array(
            'name'          => 'bounceRate',
            'label'         => 'Bounce Rate',
            'description'   => 'The percentage of visits that were bounces.',
            'group'         => 'Site Usage',
            'metric_type'   => 'calculated',
            'data_type'     => 'percentage',
            'formula'       => 'bounces / visits',
            'child_metrics' => array( 'bounces', 'visits' ),
        ) );

        $this->registerMetricDefinition( array(
            'name'          => 'pagesPerVisit',
            'label'         => 'Pages Per Visit',
            'description'   => 'The average pages viewed per visit.',
            'group'         => 'Site Usage',
            'metric_type'   => 'calculated',
            'data_type'     => 'decimal',
            'formula'       => 'round(pageViews / visits, 2)',
            'child_metrics' => array( 'pageViews', 'visits' ),
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'actions',
            'label'       => 'Actions',
            'description' => 'The total number of action events.',
            'group'       => 'Actions',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'entity'      => 'base.action_fact',
            'column'      => 'id',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'uniqueActions',
            'label'       => 'Unique Actions',
            'description' => 'Total number of unique action events.',
            'group'       => 'Actions',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'entity'      => 'base.action_fact',
            'column'      => 'action_name',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'actionsValue',
            'label'       => 'Action Value',
            'description' => 'Total value of all action events.',
            'group'       => 'Actions',
            'metric_type' => 'sum',
            'data_type'   => 'integer',
            'entity'      => 'base.action_fact',
            'column'      => 'numeric_value',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'feedRequests',
            'label'       => 'Feed Requests',
            'description' => 'Total number of feed requests.',
            'group'       => 'Feeds',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'entity'      => 'base.feed_request',
            'column'      => 'id',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'feedReaders',
            'label'       => 'Feed Readers',
            'description' => 'Total number of feed readers.',
            'group'       => 'Feeds',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'entity'      => 'base.feed_request',
            'column'      => 'feed_reader_guid',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'feedSubscriptions',
            'label'       => 'Feed Subscriptions',
            'description' => 'Total number of feed subscribers.',
            'group'       => 'Feeds',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'entity'      => 'base.feed_request',
            'column'      => 'subscription_id',
        ) );

        // goals
        $gcount = \OWA\Core\CoreAPI::getSetting('base', 'numGoals');
        for ($num = 1; $num <= $gcount;$num++) {
            $params = array('goal_number' => $num);

            $metric_name = 'goal'.$num.'Completions';
            $this->registerMetric(
                $metric_name,
                'base.goalNCompletions',
                $params,
                "Goal $num Completions",
                "The total number of goal $num completions.",
                'Goals'
            );

            $metric_name = 'goal'.$num.'Starts';
            $this->registerMetric(
                $metric_name,
                'base.goalNStarts',
                $params,
                "Goal $num Starts",
                "The total number of goal $num starts.",
                'Goals'
            );

            $metric_name = 'goal'.$num.'Value';
            $this->registerMetric(
                $metric_name,
                'base.goalNValue',
                $params,
                "Goal $num Value",
                "The total value of goal $num achieved.",
                'Goals'
            );
        }

        $this->registerMetricDefinition( array(
            'name'        => 'goalCompletionsAll',
            'label'       => 'Goal Completions',
            'description' => 'The total number of goal completions.',
            'group'       => 'Goals',
            'metric_type' => 'sum',
            'data_type'   => 'integer',
            'entity'      => 'base.session',
            'column'      => 'num_goals',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'goalStartsAll',
            'label'       => 'Goal Starts',
            'description' => 'The total number of goal starts.',
            'group'       => 'Goals',
            'metric_type' => 'sum',
            'data_type'   => 'integer',
            'entity'      => 'base.session',
            'column'      => 'num_goal_starts',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'goalValueAll',
            'label'       => 'Goal Value',
            'description' => 'The total value of all goals achieved.',
            'group'       => 'Goals',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.session',
            'column'      => 'goals_value',
        ) );

        $this->registerMetricDefinition( array(
            'name'          => 'goalConversionRateAll',
            'label'         => 'Goal Conversion Rate',
            'description'   => 'The rate of goals achieved in all visits.',
            'group'         => 'Goals',
            'metric_type'   => 'calculated',
            'data_type'     => 'percentage',
            'formula'       => 'goalCompletionsAll / visits',
            'child_metrics' => array( 'goalCompletionsAll', 'visits' ),
        ) );

        $this->registerMetricDefinition( array(
            'name'          => 'goalAbandonRateAll',
            'label'         => 'Goal Abandon Rate',
            'description'   => 'The rate of goal abandons in all visits.',
            'group'         => 'Goals',
            'metric_type'   => 'calculated',
            'data_type'     => 'percentage',
            'formula'       => 'goalStartsAll / goalCompletionsAll',
            'child_metrics' => array( 'goalCompletionsAll', 'goalStartsAll' ),
        ) );

        // ecommerce metrics
        $this->registerMetricDefinition( array(
            'name'        => 'lineItemQuantity',
            'label'       => 'Item Quantity',
            'description' => 'The total umber of items purchased.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'integer',
            'entity'      => 'base.commerce_line_item_fact',
            'column'      => 'quantity',
        ) );
        $this->registerMetricDefinition( array(
            'name'        => 'lineItemQuantity',
            'label'       => 'Item Quantity',
            'description' => 'The total umber of items purchased.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'integer',
            'entity'      => 'base.session',
            'column'      => 'commerce_items_quantity',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'lineItemRevenue',
            'label'       => 'Item Revenue',
            'description' => 'Total revenue from items purchased.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.commerce_line_item_fact',
            'column'      => 'item_revenue',
        ) );
        $this->registerMetricDefinition( array(
            'name'        => 'lineItemRevenue',
            'label'       => 'Item Revenue',
            'description' => 'Total revenue from items purchased.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.session',
            'column'      => 'commerce_items_revenue',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'transactions',
            'label'       => 'Transactions',
            'description' => 'Total number of transactions.',
            'group'       => 'E-commerce',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'entity'      => 'base.commerce_transaction_fact',
            'column'      => 'id',
        ) );
        $this->registerMetricDefinition( array(
            'name'        => 'transactions',
            'label'       => 'Transactions',
            'description' => 'Total number of transactions.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'integer',
            'entity'      => 'base.session',
            'column'      => 'commerce_trans_count',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'transactionRevenue',
            'label'       => 'Revenue',
            'description' => 'Total revenue from all transactions.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.commerce_transaction_fact',
            'column'      => 'total_revenue',
        ) );
        $this->registerMetricDefinition( array(
            'name'        => 'transactionRevenue',
            'label'       => 'Revenue',
            'description' => 'Total revenue from all transactions.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.session',
            'column'      => 'commerce_trans_revenue',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'taxRevenue',
            'label'       => 'Tax Revenue',
            'description' => 'Total revenue from taxes.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.commerce_transaction_fact',
            'column'      => 'tax_revenue',
        ) );
        $this->registerMetricDefinition( array(
            'name'        => 'taxRevenue',
            'label'       => 'Tax Revenue',
            'description' => 'Total revenue from taxes.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.session',
            'column'      => 'commerce_tax_revenue',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'shippingRevenue',
            'label'       => 'Shipping Revenue',
            'description' => 'Total revenue from shipping.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.commerce_transaction_fact',
            'column'      => 'shipping_revenue',
        ) );
        $this->registerMetricDefinition( array(
            'name'        => 'shippingRevenue',
            'label'       => 'Shipping Revenue',
            'description' => 'Total revenue from shipping.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'entity'      => 'base.session',
            'column'      => 'commerce_shipping_revenue',
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'uniqueLineItems',
            'label'       => 'Unique Items',
            'description' => 'Total number of unique items purchased.',
            'group'       => 'E-commerce',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'entity'      => 'base.commerce_transaction_fact',
            'column'      => 'sku',
        ) );
        $this->registerMetricDefinition( array(
            'name'        => 'uniqueLineItems',
            'label'       => 'Unique Items',
            'description' => 'Total number of unique items purchased.',
            'group'       => 'E-commerce',
            'metric_type' => 'sum',
            'data_type'   => 'integer',
            'entity'      => 'base.session',
            'column'      => 'commerce_items_count',
        ) );

        $this->registerMetricDefinition( array(
            'name'          => 'revenuePerTransaction',
            'label'         => 'Revenue Per Transaction',
            'description'   => 'Average revenue per transaction.',
            'group'         => 'E-commerce',
            'metric_type'   => 'calculated',
            'data_type'     => 'currency',
            'formula'       => 'transactionRevenue / transactions',
            'child_metrics' => array( 'transactionRevenue', 'transactions' ),
        ) );

        $this->registerMetricDefinition( array(
            'name'          => 'revenuePerVisit',
            'label'         => 'Revenue Per Visit',
            'description'   => 'Average revenue generated per visit.',
            'group'         => 'E-commerce',
            'metric_type'   => 'calculated',
            'data_type'     => 'currency',
            'formula'       => 'transactionRevenue / visits',
            'child_metrics' => array( 'transactionRevenue', 'visits' ),
        ) );

        $this->registerMetricDefinition( array(
            'name'          => 'ecommerceConversionRate',
            'label'         => 'E-commerce Conversion Rate',
            'description'   => 'The rate of visits that resulted in an e-commerce transaction.',
            'group'         => 'E-commerce',
            'metric_type'   => 'calculated',
            'data_type'     => 'percentage',
            'formula'       => 'transactions / visits',
            'child_metrics' => array( 'transactions', 'visits' ),
        ) );

        $this->registerMetricDefinition( array(
            'name'        => 'domClicks',
            'label'       => 'Clicks',
            'description' => 'Total number of clicks on DOM elements.',
            'group'       => 'Clicks',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'entity'      => 'base.click',
            'column'      => 'id',
        ) );
    }

    /**
     * Register Dimensions
     *
     * The following lines register various data dimensions.
     * To register a dimenison use the registerDimension method.
     * See owa_module class for documentation on this method.
     */
    function registerDimensions() {

        // fact table entity names used by a number of dimensions.
        $fact_table_entities = array(
            'base.action_fact',
            'base.request',
            'base.session',
            'base.domstream',
            'base.click',
            'base.commerce_transaction_fact',
            'base.commerce_line_item_fact'
        );


        // Time Dimensions
        $this->registerDimension(
            'date',
            $fact_table_entities,
            'yyyymmdd',
            'Date',
            'time',
            'The full date.',
            '',
            true,
            'yyyymmdd'
        );

        $this->registerDimension(
            'day',
            $fact_table_entities,
            'day',
            'Day',
            'time',
            'The day of the month (1-31).',
            '',
            true
        );

        $this->registerDimension(
            'month',
            $fact_table_entities,
            'month',
            'Month',
            'time',
            // yyyymm -- 202608, not 8. The COLUMN is called month and the
            // description said 1-12 for fifteen years, which is the reason to
            // avoid it as a chart axis and is not true: it orders correctly
            // across a year boundary, which is what a trend by month needs.
            'The month, as yyyymm.',
            '',
            true,
            'yyyymm'
        );

        $this->registerDimension(
            'year',
            $fact_table_entities,
            'year',
            'Year',
            'time',
            'The year.',
            '',
            true
        );

        $this->registerDimension(
            'dayofweek',
            $fact_table_entities,
            'dayofweek',
            'Day of Week',
            'time',
            'The day of the week (1-7).',
            '',
            true);

        $this->registerDimension(
            'dayofyear',
            $fact_table_entities,
            'dayofyear',
            'Day of Year',
            'time',
            'The day of the year (1-365).',
            '',
            true
        );

        $this->registerDimension(
            'weekofyear',
            $fact_table_entities,
            'weekofyear',
            'Week of Year',
            'time',
            'The week of the year (1-52).',
            '',
            true
        );

        $this->registerDimension(
            'date',
            'base.feed_request',
            'yyyymmdd',
            'Date',
            'time',
            'The date.',
            '',
            true,
            'yyyymmdd'
        );

        $this->registerDimension(
            'day',
            'base.feed_request',
            'day',
            'Day',
            'time',
            'The day.',
            '',
            true
        );

        $this->registerDimension(
            'month',
            'base.feed_request',
            'month',
            'Month',
            'time',
            'The month.',
            '',
            true
        );

        $this->registerDimension(
            'year',
            'base.feed_request',
            'year',
            'Year',
            'time',
            'The year.',
            '',
            true
        );

        $this->registerDimension(
            'dayofweek',
            'base.feed_request',
            'dayofweek',
            'Day of Week',
            'time',
            'The day of the week.',
            '',
            true
        );

        $this->registerDimension(
            'dayofyear',
            'base.feed_request',
            'dayofyear',
            'Day of Year',
            'time',
            'The day of the year.',
            '',
            true
        );

        $this->registerDimension(
            'weekofyear',
            'base.feed_request',
            'weekofyear',
            'Week of Year',
            'date',
            'The week of the year.',
            '',
            true
        );

        // Site Dimensions
        $this->registerDimension(
            'siteId',
            $fact_table_entities,
            'site_id',
            'Site ID',
            'site',
            'The ID of the the web site.',
            '',
            true
        );

        $this->registerDimension(
            'siteDomain',
            'base.site',
            'domain',
            'Site Domain',
            'site',
            'The domain of the web site.'
        );

        $this->registerDimension(
            'siteName',
            'base.site',
            'name',
            'Site Name',
            'site',
            'The name of the site.'
        );

        $this->registerDimension(
            'siteId',
            'base.feed_request',
            'site_id',
            'Site ID',
            'site',
            'The ID of the the web site.',
            '',
            true
        );

        // Visitor Dimensions
        $this->registerDimension(
            'visitorId',
            'base.visitor',
            'id',
            'Visitor ID',
            'visitor',
            'The ID of the visitor.'
        );

        /*
         * Denormalized, like every other column that lives ON the fact row --
         * `date` is registered against this same list the same way.
         *
         * It was registered normalized, which means "join this dimension's own
         * table through a foreign key". There is no foreign key here because
         * user_name is not a separate table: it is a tracking property with
         * required => true, so every event carries it and it is written to all
         * seven fact tables. The result was a dimension that related to nothing,
         * offered by the picker and then refused at save as an impossible
         * combination.
         *
         * Note this reads the value AS AT THE EVENT. Its sibling userEmail is
         * registered against base.visitor and so reads the visitor's stored
         * identity, which VisitorHandlers writes only when the visitor row is
         * created. The two answer different questions on purpose.
         */
        $this->registerDimension(
            'userName',
            $fact_table_entities,
            'user_name',
            'User Name',
            'visitor',
            'The name or ID of the user.',
            '',
            true
        );

        $this->registerDimension(
            'userEmail',
            'base.visitor',
            'user_email',
            'Email Address',
            'visitor',
            'The email address of the user.'
        );

        $this->registerDimension(
            'isRepeatVisitor',
            $fact_table_entities,
            'is_repeat_visitor',
            'Repeat Visitor',
            'visitor',
            'Repeat Site Visitor.',
            '',
            true,
            // Declared boolean so it FORMATS as Yes/No wherever it is shown.
            // The column stores 1 for true and NULL for false, so without this
            // a pie slice is labelled with an empty string.
            'boolean'
        );

        $this->registerDimension(
            'isNewVisitor',
            $fact_table_entities,
            'is_new_visitor',
            'New Visitor',
            'visitor',
            'New Site Visitor.',
            '',
            true
        );

        // Visit/Session Dimensions
        $this->registerDimension(
            'sessionId',
            'base.session',
            'id',
            'Session ID',
            'visit-special',
            'The ID of the session/visit.'
        );

        $this->registerDimension(
            'entryPageUrl',
            'base.document',
            'url',
            'Entry Page URL',
            'visit',
            'The URL of the entry page.',
            'first_page_id'
        );

        $this->registerDimension(
            'entryPagePath',
            'base.document',
            'uri',
            'Entry Page Path',
            'visit',
            'The URI of the entry page.',
            'first_page_id'
        );

        $this->registerDimension(
            'entryPageTitle',
            'base.document',
            'page_title',
            'Entry Page Title',
            'visit',
            'The title of the entry page.',
            'first_page_id'
        );

        $this->registerDimension(
            'entryPageType',
            'base.document',
            'page_type',
            'Entry Page Type',
            'visit',
            'The page type of the entry page.',
            'first_page_id'
        );

        $this->registerDimension(
            'exitPageUrl',
            'base.document',
            'url',
            'Exit Page URL',
            'visit',
            'The URL of the exit page.',
            'last_page_id'
        );

        $this->registerDimension(
            'exitPagePath',
            'base.document',
            'uri',
            'Exit Page Path',
            'visit',
            'The URI of the exit page.',
            'last_page_id'
        );

        $this->registerDimension(
            'exitPageTitle',
            'base.document',
            'page_title',
            'Exit Page Title',
            'visit',
            'The title of the exit page.',
            'last_page_id'
        );

        $this->registerDimension(
            'exitPageType',
            'base.document',
            'page_type',
            'Exit Page Type',
            'visit',
            'The page type of the exit page.',
            'last_page_id'
        );

        $this->registerDimension(
            'daysSinceLastVisit',
            $fact_table_entities,
            'days_since_prior_session',
            'Days Since Last Visit',
            'visit',
            'The number of days since the last visit.',
            '',
            true
        );

        $this->registerDimension(
            'daysSinceFirstVisit',
            $fact_table_entities,
            'days_since_first_session',
            'Days Since First Visit',
            'visit',
            'The number of days since the first visit of the user.',
            '',
            true
        );

        $this->registerDimension(
            'priorVisitCount',
            $fact_table_entities,
            'num_prior_sessions',
            'Prior Visits',
            'visit',
            'The number of prior visits, excluding the current one.',
            '',
            true
        );

        $this->registerDimension(
            'pagesViewsInVisit',
            'base.session',
            'num_pageviews',
            'Pages Viewed In Visit',
            'visit',
            'The number of pages viewed in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'revenueInVisit',
            'base.session',
            'commerce_trans_revenue',
            'Revenue in Visit',
            'visit',
            'Revenue generate from e-commerce transactions in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'itemRevenueInVisit',
            'base.session',
            'commerce_item_revenue',
            'Item Revenue in Visit',
            'visit',
            'Revenue generate from e-commerce transaction items in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'shippingRevenueInVisit',
            'base.session',
            'commerce_shipping_revenue',
            'Shipping Revenue in Visit',
            'visit',
            'Revenue generate from e-commerce shipping in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'taxRevenueInVisit',
            'base.session',
            'commerce_tax_revenue',
            'Tax Revenue in Visit',
            'visit',
            'Revenue generate from e-commerce tax in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'transactionsInVisit',
            'base.session',
            'commerce_trans_count',
            'Transactions in Visit',
            'visit',
            'Number of e-commerce transactions completed in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'itemQuantityInVisit',
            'base.session',
            'commerce_items_quantity',
            'Item Quantity in Visit',
            'visit',
            'Number of e-commerce items purchased completed in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'distinctItemsInVisit',
            'base.session',
            'commerce_items_count',
            'Distinct Items in Visit',
            'visit',
            'Number of distinct items purchased in Visit.',
            '',
            true
        );

        $this->registerDimension(
            'goalsInVisit',
            'base.session',
            'num_goals',
            'Goals in Visit',
            'visit',
            'Goals completed in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'goalStartsInVisit',
            'base.session',
            'num_goal_starts',
            'Goal Starts in Visit',
            'visit',
            'Goals started in a visit.',
            '',
            true
        );

        $this->registerDimension(
            'goalValueInVisit',
            'base.session',
            'goals_value',
            'Goal Value in Visit',
            'visit',
            'Total value from all goals in a visit.',
            '',
            true
        );



        // System/Technology Dimensions
        $this->registerDimension(
            'browserVersion',
            'base.ua',
            'browser',
            'Browser Version',
            'system',
            'The browser version of the visitor.'
        );

        $this->registerDimension(
            'browserType',
            'base.ua',
            'browser_type',
            'Browser Type',
            'system',
            'The browser type of the visitor.'
        );

        $this->registerDimension(
            'osType',
            'base.os',
            'name',
            'Operating System',
            'system',
            'The operating System of the visitor.'
        );

        $this->registerDimension(
            'language',
            $fact_table_entities,
            'language',
            'Language',
            'system',
            'The language of the visit.',
            '',
            true
        );

        // Geo Dimensions
        $this->registerDimension(
            'city',
            'base.location_dim',
            'city',
            'City',
            'geo',
            'The city of the visitor.'
        );

        $this->registerDimension(
            'country',
            'base.location_dim',
            'country',
            'Country',
            'geo',
            'The country of the visitor.'
        );

        $this->registerDimension(
            'latitude',
            'base.location_dim',
            'latitude',
            'Latitude',
            'geo',
            'The latitude of the visitor.'
        );

        $this->registerDimension(
            'longitude',
            'base.location_dim',
            'longitude',
            'Longitude',
            'geo',
            'The longitude of the visitor.'
        );

        $this->registerDimension(
            'countryCode',
            'base.location_dim',
            'country_code',
            'Country Code',
            'geo',
            'The ISO country code of the visitor.'
        );

        $this->registerDimension(
            'stateRegion',
            'base.location_dim',
            'state',
            'State/Region',
            'geo',
            'The state or region of the visitor.'
        );

        // Network Dimensions
        $this->registerDimension(
            'ipAddress',
            $fact_table_entities,
            'ip_address',
            'IP Address',
            'network',
            'The IP address of the visitor.',
            '',
            true
        );

        $this->registerDimension(
            'hostName',
            'base.host',
            'host',
            'Host Name',
            'network',
            'The host name of the network used by the visitor.'
        );

        // Campaign Dimensions
        $this->registerDimension(
            'medium',
            $fact_table_entities,
            'medium',
            'Medium',
            'campaign',
            'The medium where visit originated from.',
            '',
            true
        );

        $this->registerDimension(
            'source',
            'base.source_dim',
            'source_domain',
            'Source',
            'campaign',
            'The traffic source of the visit.'
        );

        $this->registerDimension(
            'campaign',
            'base.campaign_dim',
            'name',
            'Campaign',
            'campaign',
            'The campaign that originated the visit.'
        );

        $this->registerDimension(
            'ad',
            'base.ad_dim',
            'name',
            'Ad',
            'campaign',
            'The name of the ad that originated the visit.'
        );

        $this->registerDimension(
            'adType',
            'base.ad_dim',
            'type',
            'Ad Type',
            'campaign',
            'The type of ad that originated the visit.'
        );

        $this->registerDimension(
            'referralPageUrl',
            'base.referer',
            'url',
            'Referral Page URL',
            'campaign',
            'The url of the referring web page.'
        );

        $this->registerDimension(
            'referralPageTitle',
            'base.referer',
            'page_title',
            'Referral Page Title',
            'campaign',
            'The title of the referring web page.'
        );

        $this->registerDimension(
            'referralSearchTerms',
            'base.search_term_dim',
            'terms',
            'Search Terms',
            'campaign',
            'The referring search terms.',
            'referring_search_term_id'
        );

        $this->registerDimension(
            'referralLinkText',
            'base.referer',
            'refering_anchortext',
            'Referral Link Text',
            'campaign',
            'The text of the referring link.'
        );

        $this->registerDimension(
            'isSearchEngine',
            'base.referer',
            'is_searchengine',
            'Search Engine',
            'campaign',
            'Is traffic source a search engine.'
        );

        $this->registerDimension(
            'referralWebSite',
            'base.referer',
            'site',
            'Referral Web Site',
            'campaign',
            'The full domain of the referring web site.'
        );

        $this->registerDimension(
            'latestAttributions',
            'base.session',
            'latest_attributions',
            'Latest Attributions',
            'campaign-special',
            'The latest campaign attributions.',
            '',
            true
        );

        // Page Content
        $this->registerDimension(
            'priorPageUrl',
            'base.document',
            'url',
            'Prior Page URL',
            'content',
            'The URL of the prior page.',
            'prior_document_id'
        );

        $this->registerDimension(
            'priorPagePath',
            'base.document',
            'uri',
            'Prior Page Path',
            'content',
            'The URI of the prior page.',
            'prior_document_id'
        );

        $this->registerDimension(
            'priorPageTitle',
            'base.document',
            'page_title',
            'Prior Page Title',
            'content',
            'The title of the prior page.',
            'prior_document_id'
        );

        $this->registerDimension(
            'priorPageType',
            'base.document',
            'page_type',
            'Prior Page Type',
            'content',
            'The page type of the prior page.',
            'prior_document_id'
        );

        $this->registerDimension(
            'pageUrl',
            'base.document',
            'url',
            'Page URL',
            'content',
            'The URL of the web page.',
            'document_id'
        );

        $this->registerDimension(
            'pagePath',
            'base.document',
            'uri',
            'Page Path',
            'content',
            'The path of the web page.',
            'document_id'
        );

        $this->registerDimension(
            'pageTitle',
            'base.document',
            'page_title',
            'Page Title',
            'content',
            'The title of the web page.',
            'document_id'
        );

        $this->registerDimension(
            'pageType',
            'base.document',
            'page_type',
            'Page Type',
            'content',
            'The page type of the web page.',
            'document_id'
        );

        // Action Event Dimensions
        $this->registerDimension(
            'actionName',
            'base.action_fact',
            'action_name',
            'Action Name',
            'actions',
            'The name of the action.',
            '',
            true
        );

        $this->registerDimension(
            'actionGroup',
            'base.action_fact',
            'action_group',
            'Action Group',
            'actions',
            'The group that an action belongs to.',
            '',
            true
        );

        $this->registerDimension(
            'actionLabel',
            'base.action_fact',
            'action_label',
            'Action Label',
            'actions',
            'The label associated with an action.',
            '',
            true
        );

        // Ecommerce Dimensions
        $this->registerDimension(
            'productName',
            'base.commerce_line_item_fact',
            'product_name',
            'Product Name',
            'ecommerce',
            'The name of the product purchased.',
            '',
            true
        );

        $this->registerDimension(
            'productSku',
            'base.commerce_line_item_fact',
            'sku',
            'Product SKU',
            'ecommerce',
            'The SKU code of the product purchased.',
            '',
            true
        );

        $this->registerDimension(
            'productCategory',
            'base.commerce_line_item_fact',
            'category',
            'Product Category',
            'ecommerce',
            'The category of product purchased.',
            '',
            true
        );

        $this->registerDimension(
            'transactionOriginator',
            'base.commerce_transaction_fact',
            'order_source',
            'Originator',
            'ecommerce',
            'The store or location that originated the transaction.',
            '',
            true
        );

        $this->registerDimension(
            'transactionId',
            'base.commerce_transaction_fact',
            'order_id',
            'Transaction ID',
            'ecommerce',
            'The id of the e-commerce transaction.',
            '',
            true
        );

        $this->registerDimension(
            'transactionGateway',
            'base.commerce_transaction_fact',
            'gateway',
            'Payment Gateway',
            'ecommerce',
            'The payment gateway/provider used to clear the transaction.',
            '',
            true
        );

        $this->registerDimension(
            'daysToTransaction',
            'base.commerce_transaction_fact',
            'days_since_first_session',
            'Days To Purchase',
            'ecommerce',
            'The number of days since the first visit and an e-commerce transaction.',
            '',
            true
        );

        $this->registerDimension(
            'daysToTransaction',
            'base.commerce_transaction_fact',
            'days_since_first_session',
            'Days To Purchase',
            'ecommerce',
            'The number of days since the first visit and an e-commerce transaction.',
            '',
            true
        );

        $this->registerDimension(
            'visitsToTransaction',
            'base.commerce_transaction_fact',
            'num_prior_sessions',
            'Visits To Purchase',
            'ecommerce',
            'The number of visits before the transaction occurred.',
            '',
            true
        );

        $this->registerDimension(
            'timestamp',
            'base.commerce_transaction_fact',
            'timestamp',
            'Time',
            'ecommerce-special',
            'The timestamp of the transaction.',
            '',
            true
        );

        // Click Dimensions
        /*
         * The click's coordinates on the page.
         *
         * Declared so a heatmap is an ordinary dimensional query --
         * `metrics=domClicks&dimensions=clickX,clickY&constraints=pagePath==/x`
         * -- rather than a bespoke report with hand-built SQL. pagePath already
         * resolves through document_id, which owa_click carries, so the join is
         * the registry's to make.
         *
         * Grouping is the point, not a side effect: one page on a live install
         * holds 345,620 clicks, and the heatmap only ever needed each distinct
         * point and how often it was hit. As a dimension pair that is a GROUP
         * BY, and the count arrives as the metric.
         */
        $this->registerDimension(
            'clickX',
            'base.click',
            'click_x',
            'Click X',
            'dom',
            'The horizontal position of the click on the page.',
            '',
            true
        );

        $this->registerDimension(
            'clickY',
            'base.click',
            'click_y',
            'Click Y',
            'dom',
            'The vertical position of the click on the page.',
            '',
            true
        );

        $this->registerDimension(
            'domElementId',
            'base.click',
            'dom_element_id',
            'Dom ID',
            'dom',
            'The id of the dom element.',
            '',
            true
        );

        $this->registerDimension(
            'domElementName',
            'base.click',
            'dom_element_name',
            'Dom Name',
            'dom',
            'The name of the dom element.',
            '',
            true
        );

        $this->registerDimension(
            'domElementText',
            'base.click',
            'dom_element_text',
            'Dom Text',
            'dom',
            'The text associated the dom element.',
            '',
            true
        );

        $this->registerDimension(
            'domElementValue',
            'base.click',
            'dom_element_value',
            'Dom Value',
            'dom',
            'The value of the dom element.',
            '',
            true
        );

        $this->registerDimension(
            'domElementTag',
            'base.click',
            'dom_element_tag',
            'Dom Tag',
            'dom',
            'The html tag of the dom element.',
            '',
            true
        );

        $this->registerDimension(
            'domElementClass',
            'base.click',
            'dom_element_class',
            'Dom Class',
            'dom',
            'The class of the dom element.',
            '',
            true
        );

        // Feed Dimensions
        $this->registerDimension(
            'feedType',
            'base.feed_request',
            'feed_format',
            'Feed Type',
            'feed',
            'The type or format of the feed.',
            '',
            true
        );

        // Custom variable Dimensions
        $cv_max = \OWA\Core\CoreAPI::getSetting( 'base', 'maxCustomVars' );
        for ($i = 1; $i <= $cv_max;$i++) {

            $cvar_name_col = 'cv'.$i.'_name';
            $cvar_name_label = "Custom Var $i Name";
            $cvar_name_description = "The name of custom variable $i.";
            $this->registerDimension(
                    'customVarName'.$i,
                    array(
                        'base.action_fact',
                        'base.request',
                        'base.session',
                        'base.domstream',
                        'base.click',
                        'base.commerce_transaction_fact',
                        'base.commerce_line_item_fact'
                    ),
                    $cvar_name_col,
                    $cvar_name_label,
                    'custom variables',
                    $cvar_name_description,
                    '',
                    true,
                    'string'
            );

            $cvar_value_col = 'cv'.$i.'_value';
            $cvar_value_label = "Custom Var $i Value";
            $cvar_value_description = "The value of custom variable $i.";
            $this->registerDimension(
                    'customVarValue'.$i,
                    array(
                        'base.action_fact',
                        'base.request',
                        'base.session',
                        'base.domstream',
                        'base.click',
                        'base.commerce_transaction_fact',
                        'base.commerce_line_item_fact'
                    ),
                    $cvar_value_col,
                    $cvar_value_label,
                    'custom variables',
                    $cvar_value_description,
                    '',
                    true,
                    'string'
            );
        }
    }

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

        $this->registerReport( 'action-detail', 'reports/action-detail.json' );
        $this->registerReport( 'action-group', 'reports/action-group.json' );
        $this->registerReport( 'action-groups', 'reports/action-groups.json' );
        $this->registerReport( 'action-tracking', 'reports/action-tracking.json' );
        $this->registerReport( 'ad-detail', 'reports/ad-detail.json' );
        $this->registerReport( 'ad-type-detail', 'reports/ad-type-detail.json' );
        $this->registerReport( 'ad-types', 'reports/ad-types.json' );
        $this->registerReport( 'ads', 'reports/ads.json' );
        $this->registerReport( 'anchortext', 'reports/anchortext.json' );
        $this->registerReport( 'attribution-history', 'reports/attribution-history.json' );
        $this->registerReport( 'avg-order-value', 'reports/avg-order-value.json' );
        $this->registerReport( 'browser-detail', 'reports/browser-detail.json' );
        $this->registerReport( 'browsers', 'reports/browsers.json' );
        $this->registerReport( 'campaign-detail', 'reports/campaign-detail.json' );
        $this->registerReport( 'campaigns', 'reports/campaigns.json' );
        $this->registerReport( 'content', 'reports/content.json' );
        $this->registerReport( 'creative-performance', 'reports/creative-performance.json' );
        $this->registerReport( 'dashboard', 'reports/dashboard.json' );
        $this->registerReport( 'days-to-purchase', 'reports/days-to-purchase.json' );
        $this->registerReport( 'document', 'reports/document.json' );
        $this->registerReport( 'clicks', 'reports/clicks.json' );
        $this->registerReport( 'dom-clicks', 'reports/dom-clicks.json' );
        $this->registerReport( 'domstreams', array( 'controller' => 'base.reportDomstreams' ) );
        $this->registerReport( 'ecommerce', 'reports/ecommerce.json' );
        $this->registerReport( 'ecommerce-conversion-rate', 'reports/ecommerce-conversion-rate.json' );
        $this->registerReport( 'entry-pages', 'reports/entry-pages.json' );
        $this->registerReport( 'exit-pages', 'reports/exit-pages.json' );
        $this->registerReport( 'feeds', 'reports/feeds.json' );
        $this->registerReport( 'geolocation', 'reports/geolocation.json' );
        $this->registerReport( 'goal-funnel', array( 'controller' => 'base.reportGoalFunnel' ) );
        $this->registerReport( 'goals', 'reports/goals.json' );
        $this->registerReport( 'host-detail', 'reports/host-detail.json' );
        $this->registerReport( 'hosts', 'reports/hosts.json' );
        $this->registerReport( 'keyword-detail', 'reports/keyword-detail.json' );
        $this->registerReport( 'keywords', 'reports/keywords.json' );
        $this->registerReport( 'latest-visits', 'reports/latest-visits.json' );
        $this->registerReport( 'os', 'reports/os.json' );
        $this->registerReport( 'os-detail', 'reports/os-detail.json' );
        $this->registerReport( 'page-type-detail', 'reports/page-type-detail.json' );
        $this->registerReport( 'page-types', 'reports/page-types.json' );
        $this->registerReport( 'pages', 'reports/pages.json' );
        $this->registerReport( 'product-categories', 'reports/product-categories.json' );
        $this->registerReport( 'product-category-detail', 'reports/product-category-detail.json' );
        $this->registerReport( 'product-detail', 'reports/product-detail.json' );
        $this->registerReport( 'product-sku-detail', 'reports/product-sku-detail.json' );
        $this->registerReport( 'product-skus', 'reports/product-skus.json' );
        $this->registerReport( 'products', 'reports/products.json' );
        $this->registerReport( 'referral-detail', 'reports/referral-detail.json' );
        $this->registerReport( 'referral-link-text-detail', 'reports/referral-link-text-detail.json' );
        $this->registerReport( 'referring-sites', 'reports/referring-sites.json' );
        $this->registerReport( 'revenue', 'reports/revenue.json' );
        $this->registerReport( 'search-engine-detail', 'reports/search-engine-detail.json' );
        $this->registerReport( 'search-engines', 'reports/search-engines.json' );
        $this->registerReport( 'source-detail', 'reports/source-detail.json' );
        $this->registerReport( 'sources', 'reports/sources.json' );
        $this->registerReport( 'traffic', 'reports/traffic.json' );
        $this->registerReport( 'transactions', 'reports/transactions.json' );
        $this->registerReport( 'visitors', 'reports/visitors.json' );
        $this->registerReport( 'visitors-age', 'reports/visitors-age.json' );
        $this->registerReport( 'visitors-loyalty', 'reports/visitors-loyalty.json' );
        $this->registerReport( 'visitors-recency', 'reports/visitors-recency.json' );
        $this->registerReport( 'visits-to-purchase', 'reports/visits-to-purchase.json' );
    }

    function registerNavigation() {

        $this->addNavigationSubGroup('Dashboard', $this->reportRef( 'dashboard' ), 'Dashboard', 1, 'view_reports', 'Reports','fa fa-tachometer-alt');

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
        $this->addNavigationSubGroup('Ecommerce', $this->reportRef( 'ecommerce' ), 'Ecommerce', 5, 'view_reports_ecommerce', 'Reports','fa fa-shopping-cart');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'revenue' ), 'Revenue', 2, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'transactions' ), 'Transactions', 3, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'avg-order-value' ), 'Average Order Value', 4, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'ecommerce-conversion-rate' ), 'Conversion Rate', 5, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'products' ), 'Products', 6, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'product-skus' ), 'Product SKUs', 7, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'product-categories' ), 'Product Categories', 8, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'visits-to-purchase' ), 'Visits To Purchase', 9, 'view_reports_ecommerce');
        $this->addNavigationLinkInSubGroup('Ecommerce', $this->reportRef( 'days-to-purchase' ), 'Days To Purchase', 10, 'view_reports_ecommerce');

        //Content
        $this->addNavigationSubGroup('Content', $this->reportRef( 'content' ), 'Content', 4, 'view_reports', 'Reports','fa fa-newspaper');
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'pages' ), 'Pages', 1);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'page-types' ), 'Page Types', 2);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'feeds' ), 'Feeds', 7);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'entry-pages' ), 'Entry Pages', 3);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'exit-pages' ), 'Exit Pages', 4);
        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'clicks' ), 'Clicks', 5);


        //Actions
        $this->addNavigationSubGroup('Action Tracking', $this->reportRef( 'action-tracking' ), 'Action Tracking', 1, 'view_reports', 'Reports','fa fa-hand-pointer');
        $this->addNavigationLinkInSubGroup('Action Tracking', $this->reportRef( 'action-groups' ), 'Action Groups', 2);

        //Visitors
        $this->addNavigationSubGroup( 'Visitors', $this->reportRef( 'visitors' ), 'Visitors', 3, 'view_reports', 'Reports','fa fa-user-friends');
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'geolocation' ), 'Geo-location', 1);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'hosts' ), 'Domains', 2);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'visitors-loyalty' ), 'Visitor Loyalty', 3);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'visitors-recency' ), 'Visitor Recency', 4);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'visitors-age' ), 'Visitor Age', 5);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'browsers' ), 'Browser Types', 6);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'os' ), 'Operating Systems', 7);
        $this->addNavigationLinkInSubGroup( 'Visitors', $this->reportRef( 'latest-visits' ), 'Latest Visits', 8);

        //Traffic
        $this->addNavigationSubGroup('Traffic', $this->reportRef( 'traffic' ), 'Traffic', 2, 'view_reports', 'Reports','fa fa-random');
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'keywords' ), 'Search Terms', 1);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'anchortext' ), 'Inbound Link Text', 2);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'search-engines' ), 'Search Engines', 3);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'referring-sites' ), 'Referring Web Sites', 4);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'campaigns' ), 'Campaigns', 5);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'ads' ), 'Ad Performance', 6);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'ad-types' ), 'Ad Types', 7);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'creative-performance' ), 'Creative Performance', 8);
        $this->addNavigationLinkInSubGroup( 'Traffic', $this->reportRef( 'attribution-history' ), 'Attribution History', 8);

        //Goals
        $this->addNavigationSubGroup('Goals', $this->reportRef( 'goals' ), 'Goals', 5, 'view_reports', 'Reports','fa fa-bullseye');
        $this->addNavigationLinkInSubGroup( 'Goals', $this->reportRef( 'goal-funnel' ), 'Funnel Visualization', 1);

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

        // Page Requests
        $this->registerEventHandler(array('base.page_request', 'base.first_page_request'), 'requestHandlers');
        // Sessions
        $this->registerEventHandler(array('base.page_request_logged', 'base.first_page_request_logged'), 'sessionHandlers');
        // Clicks
        $this->registerEventHandler('dom.click', 'clickHandlers');
        // Feed requests
        $this->registerEventHandler('base.feed_request', 'feedRequestHandlers');

        // actions
        $this->registerEventHandler('track.action', 'actionHandler');

        // ecommerce

        // handles new ecommerce transactions
        $this->registerEventHandler('ecommerce.transaction', 'commerceTransactionHandlers');

        // updates session once ecommerce transactions are persisted
        $this->registerEventHandler(array(
                'ecommerce.transaction_persisted',
                'ecommerce.async_transaction_persisted'),
            'sessionCommerceSummaryHandlers'
        );

        $this->registerEventHandler('base.new_session', 'visitorUpdateHandlers');


        // register standard dimension handlers to listen for events
        // that populate fact tables.

        // Note: ecommerce.async_transaction_persisted events are ommited here
        // because it the event gets alll non ecommerce dimensional properties
        // from a previously persisted session entity
        $fact_events = array(
            'base.page_request_logged',
            'base.first_page_request_logged',
            'base.new_session',
            'dom.stream_logged',
            'dom.click_logged',
            'track.action_logged',
            'ecommerce.transaction_persisted'
        );

        $standard_dimension_handlers = array(
            'refererHandlers',
            'searchTermHandlers',
            'osHandlers',
            'sourceHandlers',
            'campaignHandlers',
            'adHandlers',
            'userAgentHandlers',
            'hostHandlers',
            'visitorHandlers',
            'locationHandlers'
        );

        foreach ($standard_dimension_handlers as $handler) {

            $this->registerEventHandler($fact_events, $handler);
        }

        // Documents
        $this->registerEventHandler(
            array(
                'base.page_request_logged',
                'base.first_page_request_logged',
                'base.feed_request_logged',
                'track.action',
                'dom.stream',
                'dom.click',
                'ecommerce.transaction'
            ),
            'documentHandlers'
        );

        // Goal Conversions
        $this->registerEventHandler(
            array(
                'base.new_session',
                'base.session_update',
                'ecommerce.transaction_persisted'
            ),
            'conversionHandlers'
        );

        // Nofifcation handler
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'announce_visitors' )
            && \OWA\Core\CoreAPI::getSetting( 'base', 'notice_email' )
            //&& ( owa_coreAPI::getSetting( 'base', 'request_mode' ) === 'web_app' )
            && ! defined('OWA_CLI')
            
        ) {

            $this->registerEventHandler( 'base.new_session', 'notifyHandlers' );
        }

        // install complete handler
        $this->registerEventHandler('install_complete', $this, 'installCompleteHandler');
        // User management
        $this->registerEventHandler(array('base.set_password', 'base.reset_password', 'base.new_user_account'), 'userHandlers');
    }

    function _registerEventProcessors() {
        
        
        $this->addEventProcessor( \OWA\Core\CoreAPI::getSetting( 'base', 'tracking_event_types' ) , 'base.processRequest');
        
        // @todo still needed?
        $this->addEventProcessor('base.first_page_request', 'base.processFirstRequest');
    }

    function _registerEntities() {

        $this->registerEntity(array(
                'request',
                'session',
                'document',
                'feed_request',
                'click',
                'ua',
                'referer',
                'site',
                'organization',
                'property',
                'visitor',
                'host',
                'os',
                'impression',
                'configuration',
                'user',
                'domstream',
                'action_fact',
                'search_term_dim',
                'ad_dim',
                'source_dim',
                'campaign_dim',
                'location_dim',
                'commerce_transaction_fact',
                'commerce_line_item_fact',
                'queue_item',
                'scheduled_job',
                'notification',
                'notification_state',
                'custom_report',
                'job_lock',
                'site_user')
            );

    }

    function installCompleteHandler($event) {

        //owa_coreAPI::debug('test handler: '.print_r($event, true));
    }
    
    function checkEventForType( $event ) {

        $type = $event->getEventType();

        if ( $type === 'unknown_event_type' ) {

            $e = \OWA\Core\CoreAPI::errorSingleton();
            $e->mailErrorMsg( print_r( $event->getProperties(), true ), 'Unknown Event Type' );
        }

        return $event;
    }

}


?>
