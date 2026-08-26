<?php

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

/**
 * Backward-compatibility bridge for the PSR-4 namespace migration (Phase 6).
 * =========================================================================
 *
 * OWA's ~340 framework classes are being renamed from the global-namespace
 * `owa_` prefix convention (owa_coreAPI, owa_document, ...) into real PSR-4
 * namespaces (OWA\Core\CoreAPI, OWA\Module\Base\Entity\Document, ...). Every
 * legacy `owa_*` name must keep resolving for a full major-version deprecation
 * window — third-party modules, the WordPress plugin, and serialized state all
 * reference the old names, and OWA's own factories synthesize them at runtime
 * ('owa_' . $file, see owa_lib::factory / owa_coreAPI::moduleSpecificFactory).
 *
 * HOW THE BRIDGE WORKS — a LAZY forward-alias autoloader.
 * -------------------------------------------------------
 * When any code (a factory, a third-party `new owa_document`, a `class_exists`
 * with autoload enabled) asks for a legacy `owa_*` name that has already been
 * migrated, this autoloader looks the name up in the old->new map, ensures the
 * new namespaced class is loaded (via Composer's PSR-4/classmap loader, which
 * owa_env.php registered immediately before requiring this file), and declares
 * a `class_alias(<new>, <old>)`. From that point the old name resolves to the
 * new class: `new owa_document` works, `instanceof` works in BOTH directions,
 * `extends owa_entity` works. (Verified with a standalone probe before this
 * file was written.)
 *
 * WHY LAZY, not eager. The original migration scoping assumed "no autoloader
 * today, so aliases must be declared eagerly at file-load." Phase-6 stage 0
 * falsified that: Composer's autoloader IS registered on every boot
 * (owa_env.php requires vendor/autoload.php) and a classmap over the whole tree
 * makes every class discoverable. A lazy autoloader therefore needs NO change
 * to the factory synthesis seam (owa_lib.php / owa_coreAPI factory $class_ns
 * defaults stay 'owa_') and costs nothing for names that are never requested.
 *
 * REGISTRATION ORDER. This autoloader is registered AFTER Composer's, so a
 * request for a new namespaced name resolves via Composer first; this bridge
 * only ever fires for a LEGACY `owa_*` name, which Composer cannot resolve
 * once the class has been renamed. The `owa_` prefix short-circuit keeps it a
 * no-op for every non-legacy class name.
 *
 * THE MAP. owa_compat_class_map() below is the single source of truth of
 * old->new renames. It is EMPTY at stage 1 (nothing renamed yet — the bridge
 * is inert and the safety-net suites stay green). Each later migration stage
 * appends its entries here as it renames a directory. A `class_exists(<old>,
 * false)` guard in the alias step prevents redefining an old name that some
 * code still declares directly during the transition.
 *
 * RESIDUAL BREAK (documented, not worked around): a module doing string
 * equality on a class name — `get_class($x) === 'owa_foo'` or
 * `$x::class === 'owa_foo'` — sees the NEW name and breaks. That is rare, a
 * code smell versus `instanceof` (which is unaffected), and a one-line author
 * fix. It is covered by the migration wiki page, not by code here.
 */

/**
 * The authoritative legacy-name -> new-namespaced-name map.
 *
 * EMPTY until renames begin (stage 2+). Add one entry per renamed class:
 *   'owa_document' => 'OWA\\Module\\Base\\Entity\\Document',
 *
 * @return array<string, string>
 */
function owa_compat_class_map(): array
{
    return [
        // --- populated as classes are migrated, one entry per rename ---

        // root framework classes -> OWA\Core\ (Phase 6 stage 2, roots batch)
        'owa_base' => 'OWA\\Core\\Base',
        'owa_coreAPI' => 'OWA\\Core\\CoreAPI',
        'owa_db' => 'OWA\\Core\\Db',
        'owa_lib' => 'OWA\\Core\\Lib',
        'owa_auth' => 'OWA\\Core\\Auth',
        'owa_caller' => 'OWA\\Core\\Caller',
        'owa_install' => 'OWA\\Core\\Install',
        'owa_location' => 'OWA\\Core\\Location',
        'owa_observer' => 'OWA\\Core\\Observer',
        'owa_template' => 'OWA\\Core\\Template',
        'owa_requestContainer' => 'OWA\\Core\\RequestContainer',
        'owa_http' => 'OWA\\Core\\Http',
        'owa_controller' => 'OWA\\Core\\Controller',
        'owa_adminController' => 'OWA\\Core\\AdminController',
        'owa_reportController' => 'OWA\\Core\\ReportController',
        'owa_entity' => 'OWA\\Core\\Entity',
        'owa_metric' => 'OWA\\Core\\Metric',
        'owa_module' => 'OWA\\Core\\Module',
        'owa_view' => 'OWA\\Core\\View',

        // owa_view.php subclasses -> OWA\Core\View\ (Phase 6 stage 3)
        'owa_genericTableView' => 'OWA\\Core\\View\\GenericTable',
        'owa_sparklineJsView' => 'OWA\\Core\\View\\SparklineJs',
        'owa_mailView' => 'OWA\\Core\\View\\Mail',
        'owa_adminView' => 'OWA\\Core\\View\\Admin',
        'owa_restApiView' => 'OWA\\Core\\View\\RestApi',
        'owa_jsonView' => 'OWA\\Core\\View\\Json',
        'owa_jsonResultsView' => 'OWA\\Core\\View\\JsonResults',
        'owa_adminPageView' => 'OWA\\Core\\View\\AdminPage',
        'owa_cliView' => 'OWA\\Core\\View\\Cli',

        // validators -> OWA\Core\Validation\ (Phase 6 stage 3; base + 11 plugins)
        'owa_validation' => 'OWA\\Core\\Validation\\Validation',
        'owa_emailAddressValidation' => 'OWA\\Core\\Validation\\EmailAddress',
        'owa_entityDoesNotExistValidation' => 'OWA\\Core\\Validation\\EntityDoesNotExist',
        'owa_entityExistsValidation' => 'OWA\\Core\\Validation\\EntityExists',
        'owa_inArrayValidation' => 'OWA\\Core\\Validation\\InArray',
        'owa_isNotCurrentUserValidation' => 'OWA\\Core\\Validation\\IsNotCurrentUser',
        'owa_requiredValidation' => 'OWA\\Core\\Validation\\Required',
        'owa_stringLengthValidation' => 'OWA\\Core\\Validation\\StringLength',
        'owa_stringMatchValidation' => 'OWA\\Core\\Validation\\StringMatch',
        'owa_subStringMatchValidation' => 'OWA\\Core\\Validation\\SubStringMatch',
        'owa_subStringPositionValidation' => 'OWA\\Core\\Validation\\SubStringPosition',
        'owa_userNameValidation' => 'OWA\\Core\\Validation\\UserName',

        // db driver plugin -> OWA\Core\Db\ (Phase 6 stage 3)
        'owa_db_mysql' => 'OWA\\Core\\Db\\Mysql',
        // 'pdo' is kept as a friendly alias for the MySQL-over-PDO driver.
        'owa_db_pdo' => 'OWA\\Core\\Db\\PdoMysql',
        'owa_db_pdo_mysql' => 'OWA\\Core\\Db\\PdoMysql',

        // module.php registry classes -> OWA\Module\<Mod>\Module (Phase 6 stage 3)
        'owa_baseModule' => 'OWA\\Module\\Base\\Module',
        'owa_domstreamModule' => 'OWA\\Module\\Domstream\\Module',
        'owa_fileCacheModule' => 'OWA\\Module\\FileCache\\Module',
        'owa_helloModule' => 'OWA\\Module\\Hello\\Module',
        'owa_maxmind_geoipModule' => 'OWA\\Module\\MaxmindGeoip\\Module',
        'owa_memcachedCacheModule' => 'OWA\\Module\\MemcachedCache\\Module',
        'owa_remoteQueueModule' => 'OWA\\Module\\RemoteQueue\\Module',

        // modules/base/entities (Phase 6 stage 2)
        'owa_document' => 'OWA\\Module\\Base\\Entity\\Document',
        'owa_action_fact' => 'OWA\\Module\\Base\\Entity\\ActionFact',
        'owa_ad_dim' => 'OWA\\Module\\Base\\Entity\\AdDim',
        'owa_campaign_dim' => 'OWA\\Module\\Base\\Entity\\CampaignDim',
        'owa_click' => 'OWA\\Module\\Base\\Entity\\Click',
        'owa_commerce_line_item_fact' => 'OWA\\Module\\Base\\Entity\\CommerceLineItemFact',
        'owa_commerce_transaction_fact' => 'OWA\\Module\\Base\\Entity\\CommerceTransactionFact',
        'owa_configuration' => 'OWA\\Module\\Base\\Entity\\Configuration',
        'owa_domstream' => 'OWA\\Module\\Base\\Entity\\Domstream',
        'owa_exit' => 'OWA\\Module\\Base\\Entity\\ExitPage',
        'owa_feed_request' => 'OWA\\Module\\Base\\Entity\\FeedRequest',
        'owa_host' => 'OWA\\Module\\Base\\Entity\\Host',
        'owa_impression' => 'OWA\\Module\\Base\\Entity\\Impression',
        'owa_location_dim' => 'OWA\\Module\\Base\\Entity\\LocationDim',
        'owa_os' => 'OWA\\Module\\Base\\Entity\\Os',
        'owa_queue_item' => 'OWA\\Module\\Base\\Entity\\QueueItem',
        'owa_job_lock' => 'OWA\\Module\\Base\\Entity\\JobLock',
        'owa_notification' => 'OWA\\Module\\Base\\Entity\\Notification',
        'owa_notification_state' => 'OWA\\Module\\Base\\Entity\\NotificationState',
        'owa_scheduled_job' => 'OWA\\Module\\Base\\Entity\\ScheduledJob',
        'owa_referer' => 'OWA\\Module\\Base\\Entity\\Referer',
        'owa_request' => 'OWA\\Module\\Base\\Entity\\Request',
        'owa_search_term_dim' => 'OWA\\Module\\Base\\Entity\\SearchTermDim',
        'owa_session' => 'OWA\\Module\\Base\\Entity\\Session',
        'owa_site' => 'OWA\\Module\\Base\\Entity\\Site',
        'owa_site_user' => 'OWA\\Module\\Base\\Entity\\SiteUser',
        'owa_source_dim' => 'OWA\\Module\\Base\\Entity\\SourceDim',
        'owa_ua' => 'OWA\\Module\\Base\\Entity\\Ua',
        'owa_user' => 'OWA\\Module\\Base\\Entity\\User',
        'owa_visitor' => 'OWA\\Module\\Base\\Entity\\Visitor',

        // modules/base/metrics (Phase 6 stage 2)
        'owa_actions' => 'OWA\\Module\\Base\\Metric\\Actions',
        'owa_actionsPerVisit' => 'OWA\\Module\\Base\\Metric\\ActionsPerVisit',
        'owa_actionsValue' => 'OWA\\Module\\Base\\Metric\\ActionsValue',
        'owa_bounceRate' => 'OWA\\Module\\Base\\Metric\\BounceRate',
        'owa_bounces' => 'OWA\\Module\\Base\\Metric\\Bounces',
        'owa_clickBrowserTypes' => 'OWA\\Module\\Base\\Metric\\ClickBrowserTypes',
        'owa_configurableMetric' => 'OWA\\Module\\Base\\Metric\\ConfigurableMetric',
        'owa_domClicks' => 'OWA\\Module\\Base\\Metric\\DomClicks',
        'owa_ecommerceConversionRate' => 'OWA\\Module\\Base\\Metric\\EcommerceConversionRate',
        'owa_feedReaders' => 'OWA\\Module\\Base\\Metric\\FeedReaders',
        'owa_feedRequests' => 'OWA\\Module\\Base\\Metric\\FeedRequests',
        'owa_feedSubscriptions' => 'OWA\\Module\\Base\\Metric\\FeedSubscriptions',
        'owa_goalAbandonRateAll' => 'OWA\\Module\\Base\\Metric\\GoalAbandonRateAll',
        'owa_goalCompletionsAll' => 'OWA\\Module\\Base\\Metric\\GoalCompletionsAll',
        'owa_goalConversionRateAll' => 'OWA\\Module\\Base\\Metric\\GoalConversionRateAll',
        'owa_goalNCompletions' => 'OWA\\Module\\Base\\Metric\\GoalNCompletions',
        'owa_goalNStarts' => 'OWA\\Module\\Base\\Metric\\GoalNStarts',
        'owa_goalNValue' => 'OWA\\Module\\Base\\Metric\\GoalNValue',
        'owa_goalStartsAll' => 'OWA\\Module\\Base\\Metric\\GoalStartsAll',
        'owa_goalValueAll' => 'OWA\\Module\\Base\\Metric\\GoalValueAll',
        'owa_latestDomstreams' => 'OWA\\Module\\Base\\Metric\\LatestDomstreams',
        'owa_lineItemQuantity' => 'OWA\\Module\\Base\\Metric\\LineItemQuantity',
        'owa_lineItemQuantityFromSessionFact' => 'OWA\\Module\\Base\\Metric\\LineItemQuantityFromSessionFact',
        'owa_lineItemRevenue' => 'OWA\\Module\\Base\\Metric\\LineItemRevenue',
        'owa_lineItemRevenueFromSessionFact' => 'OWA\\Module\\Base\\Metric\\LineItemRevenueFromSessionFact',
        'owa_newVisitors' => 'OWA\\Module\\Base\\Metric\\NewVisitors',
        'owa_pagesPerVisit' => 'OWA\\Module\\Base\\Metric\\PagesPerVisit',
        'owa_repeatVisitors' => 'OWA\\Module\\Base\\Metric\\RepeatVisitors',
        'owa_revenuePerTransaction' => 'OWA\\Module\\Base\\Metric\\RevenuePerTransaction',
        'owa_revenuePerVisit' => 'OWA\\Module\\Base\\Metric\\RevenuePerVisit',
        'owa_sessionBrowserTypes' => 'OWA\\Module\\Base\\Metric\\SessionBrowserTypes',
        'owa_shippingRevenue' => 'OWA\\Module\\Base\\Metric\\ShippingRevenue',
        'owa_shippingRevenueFromSessionFact' => 'OWA\\Module\\Base\\Metric\\ShippingRevenueFromSessionFact',
        'owa_taxRevenue' => 'OWA\\Module\\Base\\Metric\\TaxRevenue',
        'owa_taxRevenueFromSessionFact' => 'OWA\\Module\\Base\\Metric\\TaxRevenueFromSessionFact',
        'owa_topReferers' => 'OWA\\Module\\Base\\Metric\\TopReferers',
        'owa_topVisitors' => 'OWA\\Module\\Base\\Metric\\TopVisitors',
        'owa_transactionRevenue' => 'OWA\\Module\\Base\\Metric\\TransactionRevenue',
        'owa_transactionRevenueFromSessionFact' => 'OWA\\Module\\Base\\Metric\\TransactionRevenueFromSessionFact',
        'owa_transactions' => 'OWA\\Module\\Base\\Metric\\Transactions',
        'owa_transactionsFromSessionFact' => 'OWA\\Module\\Base\\Metric\\TransactionsFromSessionFact',
        'owa_uniqueActions' => 'OWA\\Module\\Base\\Metric\\UniqueActions',
        'owa_uniqueLineItems' => 'OWA\\Module\\Base\\Metric\\UniqueLineItems',
        'owa_uniqueLineItemsFromSessionFact' => 'OWA\\Module\\Base\\Metric\\UniqueLineItemsFromSessionFact',
        'owa_uniquePageViews' => 'OWA\\Module\\Base\\Metric\\UniquePageViews',
        'owa_visitDuration' => 'OWA\\Module\\Base\\Metric\\VisitDuration',
        'owa_visitors' => 'OWA\\Module\\Base\\Metric\\Visitors',

        // modules/base/classes (Phase 6 stage 2). Abstract framework bases ->
        // OWA\Core\ (they straddle the Core/Module seam); the rest ->
        // OWA\Module\Base\Classes\. validation.php HELD for the Core\Validation
        // consolidation with plugins/validations/.
        'owa_factTable' => 'OWA\\Core\\Entity\\FactTable',
        'owa_calculatedMetric' => 'OWA\\Core\\Metric\\CalculatedMetric',
        'owa_cliController' => 'OWA\\Core\\Controller\\Cli',
        'owa_installController' => 'OWA\\Core\\Controller\\Install',
        'owa_cacheType' => 'OWA\\Core\\CacheType',
        'owa_eventQueue' => 'OWA\\Core\\EventQueue',
        'owa_update' => 'OWA\\Core\\Update',
        'owa_browscap' => 'OWA\\Module\\Base\\Classes\\Browscap',
        'owa_cache' => 'OWA\\Module\\Base\\Classes\\Cache',
        'owa_chartData' => 'OWA\\Module\\Base\\Classes\\ChartData',
        'owa_dbColumn' => 'OWA\\Module\\Base\\Classes\\DbColumn',
        'owa_date' => 'OWA\\Module\\Base\\Classes\\Date',
        'owa_dbEventQueue' => 'OWA\\Module\\Base\\Classes\\DbEventQueue',
        'owa_error' => 'OWA\\Module\\Base\\Classes\\Error',
        'owa_event' => 'OWA\\Module\\Base\\Classes\\Event',
        'owa_eventDispatch' => 'OWA\\Module\\Base\\Classes\\EventDispatch',
        'owa_fileEventQueue' => 'OWA\\Module\\Base\\Classes\\FileEventQueue',
        'owa_geolocation' => 'OWA\\Module\\Base\\Classes\\Geolocation',
        'owa_goalManager' => 'OWA\\Module\\Base\\Classes\\GoalManager',
        'owa_httpEventQueue' => 'OWA\\Module\\Base\\Classes\\HttpEventQueue',
        'owa_installManager' => 'OWA\\Module\\Base\\Classes\\InstallManager',
        'owa_logConsole' => 'OWA\\Module\\Base\\Classes\\LogConsole',
        'owa_logEmail' => 'OWA\\Module\\Base\\Classes\\LogEmail',
        'owa_logFile' => 'OWA\\Module\\Base\\Classes\\LogFile',
        'owa_mailer' => 'OWA\\Module\\Base\\Classes\\Mailer',
        'owa_memoryCache' => 'OWA\\Module\\Base\\Classes\\MemoryCache',
        'owa_paginatedResultSet' => 'OWA\\Module\\Base\\Classes\\PaginatedResultSet',
        'owa_pagination' => 'OWA\\Module\\Base\\Classes\\Pagination',
        'owa_pslReader' => 'OWA\\Module\\Base\\Classes\\PslReader',
        'owa_resultSetManager' => 'OWA\\Module\\Base\\Classes\\ResultSetManager',
        'owa_sanitize' => 'OWA\\Module\\Base\\Classes\\Sanitize',
        'owa_service' => 'OWA\\Module\\Base\\Classes\\Service',
        'owa_serviceUser' => 'OWA\\Module\\Base\\Classes\\ServiceUser',
        'owa_settings' => 'OWA\\Module\\Base\\Classes\\Settings',
        'owa_siteManager' => 'OWA\\Module\\Base\\Classes\\SiteManager',
        'owa_state' => 'OWA\\Module\\Base\\Classes\\State',
        'owa_timePeriod' => 'OWA\\Module\\Base\\Classes\\TimePeriod',
        'owa_trackingEventHelpers' => 'OWA\\Module\\Base\\Classes\\TrackingEventHelpers',
        'owa_userManager' => 'OWA\\Module\\Base\\Classes\\UserManager',
        'owa_validator' => 'OWA\\Module\\Base\\Classes\\Validator',

        // modules/base/handlers (Phase 6 stage 2) -> OWA\Module\Base\Handler\*
        // (all extend owa_observer; registered by short name, factory synthesizes
        // 'owa_'.$name -> resolved here via moduleGenericFactory).
        'owa_actionHandler' => 'OWA\\Module\\Base\\Handler\\ActionHandler',
        'owa_adHandlers' => 'OWA\\Module\\Base\\Handler\\AdHandlers',
        'owa_campaignHandlers' => 'OWA\\Module\\Base\\Handler\\CampaignHandlers',
        'owa_clickHandlers' => 'OWA\\Module\\Base\\Handler\\ClickHandlers',
        'owa_commerceTransactionHandlers' => 'OWA\\Module\\Base\\Handler\\CommerceTransactionHandlers',
        'owa_conversionHandlers' => 'OWA\\Module\\Base\\Handler\\ConversionHandlers',
        'owa_documentHandlers' => 'OWA\\Module\\Base\\Handler\\DocumentHandlers',
        'owa_feedRequestHandlers' => 'OWA\\Module\\Base\\Handler\\FeedRequestHandlers',
        'owa_hostHandlers' => 'OWA\\Module\\Base\\Handler\\HostHandlers',
        'owa_locationHandlers' => 'OWA\\Module\\Base\\Handler\\LocationHandlers',
        'owa_notifyHandlers' => 'OWA\\Module\\Base\\Handler\\NotifyHandlers',
        'owa_osHandlers' => 'OWA\\Module\\Base\\Handler\\OsHandlers',
        'owa_refererHandlers' => 'OWA\\Module\\Base\\Handler\\RefererHandlers',
        'owa_requestHandlers' => 'OWA\\Module\\Base\\Handler\\RequestHandlers',
        'owa_searchTermHandlers' => 'OWA\\Module\\Base\\Handler\\SearchTermHandlers',
        'owa_sessionCommerceSummaryHandlers' => 'OWA\\Module\\Base\\Handler\\SessionCommerceSummaryHandlers',
        'owa_sessionHandlers' => 'OWA\\Module\\Base\\Handler\\SessionHandlers',
        'owa_sourceHandlers' => 'OWA\\Module\\Base\\Handler\\SourceHandlers',
        'owa_userAgentHandlers' => 'OWA\\Module\\Base\\Handler\\UserAgentHandlers',
        'owa_userHandlers' => 'OWA\\Module\\Base\\Handler\\UserHandlers',
        'owa_visitorHandlers' => 'OWA\\Module\\Base\\Handler\\VisitorHandlers',
        'owa_visitorUpdateHandlers' => 'OWA\\Module\\Base\\Handler\\VisitorUpdateHandlers',

        // modules/base/updates (Phase 6 stage 2) -> OWA\Module\Base\Update\*.
        // Files are numeric (003.php ...); updateFactory synthesizes the legacy
        // key 'owa_'.$module.'_'.$filename.'_update' (owa_base_003_update), so
        // THAT exact string is the bridge key.
        'owa_base_003_update' => 'OWA\\Module\\Base\\Update\\Update003',
        'owa_base_004_update' => 'OWA\\Module\\Base\\Update\\Update004',
        'owa_base_005_update' => 'OWA\\Module\\Base\\Update\\Update005',
        'owa_base_006_update' => 'OWA\\Module\\Base\\Update\\Update006',
        'owa_base_007_update' => 'OWA\\Module\\Base\\Update\\Update007',
        'owa_base_008_update' => 'OWA\\Module\\Base\\Update\\Update008',
        'owa_base_009_update' => 'OWA\\Module\\Base\\Update\\Update009',
        'owa_base_010_update' => 'OWA\\Module\\Base\\Update\\Update010',
        'owa_base_011_update' => 'OWA\\Module\\Base\\Update\\Update011',

        // non-base modules' leaf classes (Phase 6 stage 2). Each is reached by a
        // string-based factory lookup (registerImplementation / registerFilter /
        // registerRestApiRoute / registerEventHandler / admin-panel 'do'), so the
        // registration literals are untouched and these bridge keys cover them.
        // module.php files (the module-registry classes themselves) stay global —
        // deferred to the module.php special-case stage.
        'owa_domstreamsRestController' => 'OWA\\Module\\Domstream\\Controller\\DomstreamsRestController',
        'owa_domstreamsRestView' => 'OWA\\Module\\Domstream\\View\\DomstreamsRest',
        'owa_domstreamHandlers' => 'OWA\\Module\\Domstream\\Handler\\DomstreamHandlers',
        'owa_fileCache' => 'OWA\\Module\\FileCache\\Classes\\FileCache',
        'owa_exampleSettingsController' => 'OWA\\Module\\Hello\\ExampleSettingsController',
        'owa_exampleSettingsView' => 'OWA\\Module\\Hello\\ExampleSettingsView',
        'owa_maxmind' => 'OWA\\Module\\MaxmindGeoip\\Classes\\Maxmind',
        'owa_memcachedCache' => 'OWA\\Module\\MemcachedCache\\Classes\\MemcachedCache',

        // modules/base/controllers (Phase 6 stage 3). AFFIX->NAMESPACE: the
        // Controller/View suffix becomes the sub-namespace, so the class short
        // name drops BOTH owa_ and the suffix. The 9 files were each a
        // Controller+View PAIR sharing one file; split one-class-per-file (the
        // View extracted to a sibling <name>View.php). Controllers reached by
        // literal 'owa_*RestController'/'owa_*CliController' registration
        // strings (registerRestApiRoute / registerAction) + the corsPreflight
        // simpleFactory literal; Views by setView('base.<x>') -> moduleFactory
        // synthesizing 'owa_'.<file>.'View'. All legacy names bridged here.
        'owa_addSiteRestController' => 'OWA\\Module\\Base\\Controller\\AddSiteRest',
        'owa_addSiteRestView' => 'OWA\\Module\\Base\\View\\AddSiteRest',
        'owa_addUserRestController' => 'OWA\\Module\\Base\\Controller\\AddUserRest',
        'owa_addUserRestView' => 'OWA\\Module\\Base\\View\\AddUserRest',
        'owa_corsPreflightController' => 'OWA\\Module\\Base\\Controller\\CorsPreflight',
        'owa_corsPreflightView' => 'OWA\\Module\\Base\\View\\CorsPreflight',
        'owa_deleteUserRestController' => 'OWA\\Module\\Base\\Controller\\DeleteUserRest',
        'owa_deleteUserRestView' => 'OWA\\Module\\Base\\View\\DeleteUserRest',
        'owa_reportsRestController' => 'OWA\\Module\\Base\\Controller\\ReportsRest',
        'owa_reportsRestView' => 'OWA\\Module\\Base\\View\\ReportsRest',
        'owa_resetSecretsCliController' => 'OWA\\Module\\Base\\Controller\\ResetSecretsCli',
        'owa_resetSecretsCliView' => 'OWA\\Module\\Base\\View\\ResetSecretsCli',
        'owa_siteAddAllowedUserRestController' => 'OWA\\Module\\Base\\Controller\\SiteAddAllowedUserRest',
        'owa_siteAddAllowedUserRestView' => 'OWA\\Module\\Base\\View\\SiteAddAllowedUserRest',
        'owa_notificationsRestController' => 'OWA\\Module\\Base\\Controller\\NotificationsRest',
        'owa_notificationsRestView' => 'OWA\\Module\\Base\\View\\NotificationsRest',
        'owa_notificationMarkReadRestController' => 'OWA\\Module\\Base\\Controller\\NotificationMarkReadRest',
        'owa_notificationMarkReadRestView' => 'OWA\\Module\\Base\\View\\NotificationMarkReadRest',
        'owa_notificationDismissRestController' => 'OWA\\Module\\Base\\Controller\\NotificationDismissRest',
        'owa_notificationDismissRestView' => 'OWA\\Module\\Base\\View\\NotificationDismissRest',
        'owa_notificationsFetchCliController' => 'OWA\\Module\\Base\\Controller\\NotificationsFetchCli',
        'owa_sitesRestController' => 'OWA\\Module\\Base\\Controller\\SitesRest',
        'owa_sitesRestView' => 'OWA\\Module\\Base\\View\\SitesRest',
        'owa_usersRestController' => 'OWA\\Module\\Base\\Controller\\UsersRest',
        'owa_usersRestView' => 'OWA\\Module\\Base\\View\\UsersRest',

        // modules/base flat pages — SINGLE-class files (Phase 6 stage 3).
        // In-place affix->namespace (no split needed): Controller suffix ->
        // ...Base\\Controller\\<Name>, View suffix -> ...Base\\View\\<Name>.
        'owa_entityInstallController' => 'OWA\\Module\\Base\\Controller\\EntityInstall',
        'owa_errorView' => 'OWA\\Module\\Base\\View\\Error',
        'owa_flushCacheCliController' => 'OWA\\Module\\Base\\Controller\\FlushCacheCli',
        'owa_updateUaRegexesCliController' => 'OWA\\Module\\Base\\Controller\\UpdateUaRegexesCli',
        'owa_updateGeoipDbCliController' => 'OWA\\Module\\MaxmindGeoip\\Controller\\UpdateGeoipDbCli',
        'owa_optionsGeoipController' => 'OWA\\Module\\MaxmindGeoip\\Controller\\OptionsGeoip',
        'owa_optionsGeoipUpdateController' => 'OWA\\Module\\MaxmindGeoip\\Controller\\OptionsGeoipUpdate',
        'owa_optionsGeoipView' => 'OWA\\Module\\MaxmindGeoip\\View\\OptionsGeoip',
        'owa_flushProcessedEventsCliController' => 'OWA\\Module\\Base\\Controller\\FlushProcessedEventsCli',
        'owa_genericCliView' => 'OWA\\Module\\Base\\View\\GenericCli',
        'owa_installView' => 'OWA\\Module\\Base\\View\\Install',
        'owa_installBaseController' => 'OWA\\Module\\Base\\Controller\\InstallBase',
        'owa_installCliController' => 'OWA\\Module\\Base\\Controller\\InstallCli',
        'owa_installConfigController' => 'OWA\\Module\\Base\\Controller\\InstallConfig',
        'owa_installConfigEntryView' => 'OWA\\Module\\Base\\View\\InstallConfigEntry',
        'owa_loginController' => 'OWA\\Module\\Base\\Controller\\Login',
        'owa_logoutController' => 'OWA\\Module\\Base\\Controller\\Logout',
        'owa_moduleActivateController' => 'OWA\\Module\\Base\\Controller\\ModuleActivate',
        'owa_moduleActivateCliController' => 'OWA\\Module\\Base\\Controller\\ModuleActivateCli',
        'owa_moduleDeactivateController' => 'OWA\\Module\\Base\\Controller\\ModuleDeactivate',
        'owa_moduleDeactivateCliController' => 'OWA\\Module\\Base\\Controller\\ModuleDeactivateCli',
        'owa_moduleInstallCliController' => 'OWA\\Module\\Base\\Controller\\ModuleInstallCli',
        'owa_notifyNewSessionPlainTextView' => 'OWA\\Module\\Base\\View\\NotifyNewSessionPlainText',
        'owa_optionsView' => 'OWA\\Module\\Base\\View\\Options',
        'owa_optionsFlushCacheController' => 'OWA\\Module\\Base\\Controller\\OptionsFlushCache',
        'owa_optionsGoalEditController' => 'OWA\\Module\\Base\\Controller\\OptionsGoalEdit',
        'owa_optionsResetController' => 'OWA\\Module\\Base\\Controller\\OptionsReset',
        'owa_optionsUpdateController' => 'OWA\\Module\\Base\\Controller\\OptionsUpdate',
        'owa_passwordResetRequestController' => 'OWA\\Module\\Base\\Controller\\PasswordResetRequest',
        'owa_pixelView' => 'OWA\\Module\\Base\\View\\Pixel',
        'owa_processEventController' => 'OWA\\Module\\Base\\Controller\\ProcessEvent',
        'owa_processEventQueueController' => 'OWA\\Module\\Base\\Controller\\ProcessEventQueue',
        'owa_processFirstRequestController' => 'OWA\\Module\\Base\\Controller\\ProcessFirstRequest',
        'owa_processRequestController' => 'OWA\\Module\\Base\\Controller\\ProcessRequest',
        'owa_pruneEventQueueArchivesCliController' => 'OWA\\Module\\Base\\Controller\\PruneEventQueueArchivesCli',
        'owa_sitesDeleteController' => 'OWA\\Module\\Base\\Controller\\SitesDelete',
        'owa_sitesEditController' => 'OWA\\Module\\Base\\Controller\\SitesEdit',
        'owa_sitesEditAllowedUsersController' => 'OWA\\Module\\Base\\Controller\\SitesEditAllowedUsers',
        'owa_sitesEditSettingsController' => 'OWA\\Module\\Base\\Controller\\SitesEditSettings',
        'owa_updatesApplyController' => 'OWA\\Module\\Base\\Controller\\UpdatesApply',
        'owa_updatesApplyCliController' => 'OWA\\Module\\Base\\Controller\\UpdatesApplyCli',
        'owa_usersAddController' => 'OWA\\Module\\Base\\Controller\\UsersAdd',
        'owa_usersChangePasswordController' => 'OWA\\Module\\Base\\Controller\\UsersChangePassword',
        'owa_usersDeleteController' => 'OWA\\Module\\Base\\Controller\\UsersDelete',
        'owa_usersEditController' => 'OWA\\Module\\Base\\Controller\\UsersEdit',

        // modules/base flat pages — Controller/View PAIR files (Phase 6 stage 3).
        // Split one-class-per-file (order-agnostic), then affix->namespace.
        'owa_changeUserPasswordCliController' => 'OWA\\Module\\Base\\Controller\\ChangeUserPasswordCli',
        'owa_changeUserPasswordCliView' => 'OWA\\Module\\Base\\View\\ChangeUserPasswordCli',
        'owa_crawlDocumentCliController' => 'OWA\\Module\\Base\\Controller\\CrawlDocumentCli',
        'owa_crawlDocumentCliView' => 'OWA\\Module\\Base\\View\\CrawlDocumentCli',
        'owa_installCheckEnvController' => 'OWA\\Module\\Base\\Controller\\InstallCheckEnv',
        'owa_installCheckEnvView' => 'OWA\\Module\\Base\\View\\InstallCheckEnv',
        'owa_installDefaultsEntryController' => 'OWA\\Module\\Base\\Controller\\InstallDefaultsEntry',
        'owa_installDefaultsEntryView' => 'OWA\\Module\\Base\\View\\InstallDefaultsEntry',
        'owa_installFinishController' => 'OWA\\Module\\Base\\Controller\\InstallFinish',
        'owa_installFinishView' => 'OWA\\Module\\Base\\View\\InstallFinish',
        'owa_installStartController' => 'OWA\\Module\\Base\\Controller\\InstallStart',
        'owa_installStartView' => 'OWA\\Module\\Base\\View\\InstallStart',
        'owa_loginFormController' => 'OWA\\Module\\Base\\Controller\\LoginForm',
        'owa_loginFormView' => 'OWA\\Module\\Base\\View\\LoginForm',
        'owa_notifyNewSessionController' => 'OWA\\Module\\Base\\Controller\\NotifyNewSession',
        'owa_notifyNewSessionView' => 'OWA\\Module\\Base\\View\\NotifyNewSession',
        'owa_optionsGeneralController' => 'OWA\\Module\\Base\\Controller\\OptionsGeneral',
        'owa_optionsGeneralView' => 'OWA\\Module\\Base\\View\\OptionsGeneral',
        'owa_optionsGoalEntryController' => 'OWA\\Module\\Base\\Controller\\OptionsGoalEntry',
        'owa_optionsGoalEntryView' => 'OWA\\Module\\Base\\View\\OptionsGoalEntry',
        'owa_optionsGoalsController' => 'OWA\\Module\\Base\\Controller\\OptionsGoals',
        'owa_optionsGoalsView' => 'OWA\\Module\\Base\\View\\OptionsGoals',
        'owa_optionsModulesController' => 'OWA\\Module\\Base\\Controller\\OptionsModules',
        'owa_optionsModulesView' => 'OWA\\Module\\Base\\View\\OptionsModules',
        'owa_overlayLauncherController' => 'OWA\\Module\\Base\\Controller\\OverlayLauncher',
        'owa_overlayLauncherView' => 'OWA\\Module\\Base\\View\\OverlayLauncher',
        'owa_passwordResetFormController' => 'OWA\\Module\\Base\\Controller\\PasswordResetForm',
        'owa_passwordResetFormView' => 'OWA\\Module\\Base\\View\\PasswordResetForm',
        'owa_reportDomstreamsController' => 'OWA\\Module\\Base\\Controller\\ReportDomstreams',
        'owa_reportDomstreamsView' => 'OWA\\Module\\Base\\View\\ReportDomstreams',
        'owa_reportGoalFunnelController' => 'OWA\\Module\\Base\\Controller\\ReportGoalFunnel',
        'owa_reportGoalFunnelView' => 'OWA\\Module\\Base\\View\\ReportGoalFunnel',
        'owa_sitesController' => 'OWA\\Module\\Base\\Controller\\Sites',
        'owa_sitesView' => 'OWA\\Module\\Base\\View\\Sites',
        'owa_sitesAddController' => 'OWA\\Module\\Base\\Controller\\SitesAdd',
        'owa_sitesAddView' => 'OWA\\Module\\Base\\View\\SitesAdd',
        'owa_sitesAddCliController' => 'OWA\\Module\\Base\\Controller\\SitesAddCli',
        'owa_sitesAddCliView' => 'OWA\\Module\\Base\\View\\SitesAddCli',
        'owa_sitesInvocationController' => 'OWA\\Module\\Base\\Controller\\SitesInvocation',
        'owa_sitesInvocationView' => 'OWA\\Module\\Base\\View\\SitesInvocation',
        'owa_sitesProfileController' => 'OWA\\Module\\Base\\Controller\\SitesProfile',
        'owa_sitesProfileView' => 'OWA\\Module\\Base\\View\\SitesProfile',
        'owa_updatesController' => 'OWA\\Module\\Base\\Controller\\Updates',
        'owa_updatesView' => 'OWA\\Module\\Base\\View\\Updates',
        'owa_usersController' => 'OWA\\Module\\Base\\Controller\\Users',
        'owa_usersView' => 'OWA\\Module\\Base\\View\\Users',
        'owa_usersNewAccountController' => 'OWA\\Module\\Base\\Controller\\UsersNewAccount',
        'owa_usersNewAccountView' => 'OWA\\Module\\Base\\View\\UsersNewAccount',
        'owa_usersPasswordEntryController' => 'OWA\\Module\\Base\\Controller\\UsersPasswordEntry',
        'owa_usersPasswordEntryView' => 'OWA\\Module\\Base\\View\\UsersPasswordEntry',
        'owa_usersProfileController' => 'OWA\\Module\\Base\\Controller\\UsersProfile',
        'owa_usersProfileView' => 'OWA\\Module\\Base\\View\\UsersProfile',
        'owa_usersResetPasswordController' => 'OWA\\Module\\Base\\Controller\\UsersResetPassword',
        'owa_usersResetPasswordView' => 'OWA\\Module\\Base\\View\\UsersResetPassword',
        'owa_usersSetPasswordController' => 'OWA\\Module\\Base\\Controller\\UsersSetPassword',
        'owa_usersSetPasswordView' => 'OWA\\Module\\Base\\View\\UsersSetPassword',

        // modules/base flat pages — EDGE cases (Phase 6 stage 3):
        // report.php (owa_reportView + 3 dimensional subviews) and the
        // asymmetric apiRequest.php (owa_apiRequestController + owa_apiErrorView).
        'owa_apiRequestController' => 'OWA\\Module\\Base\\Controller\\ApiRequest',
        'owa_apiErrorView' => 'OWA\\Module\\Base\\View\\ApiError',
        'owa_reportView' => 'OWA\\Module\\Base\\View\\Report',
        'owa_reportDimensionView' => 'OWA\\Module\\Base\\View\\ReportDimension',
        'owa_reportDimensionDetailView' => 'OWA\\Module\\Base\\View\\ReportDimensionDetail',
    ];
}

// The aliasing autoloader can be disabled (for testing that OWA runs fully on
// its new namespaced names, and to preview the v2.0 bridge drop) by defining
// OWA_DISABLE_COMPAT_BRIDGE = true before this file loads. The map function
// above stays available either way (the factories read it as their old->new
// translator). Default is ON — the bridge remains the third-party contract.
if (defined('OWA_DISABLE_COMPAT_BRIDGE') && OWA_DISABLE_COMPAT_BRIDGE) {
    return;
}

spl_autoload_register(function (string $class): void {

    // Only ever act on legacy global-namespace owa_* names. A namespaced name
    // (OWA\...) contains a backslash and is Composer's job, not ours.
    if (strncmp($class, 'owa_', 4) !== 0) {
        return;
    }

    $map = owa_compat_class_map();
    $new = $map[$class] ?? null;

    // PHP class names are case-insensitive, and legacy OWA code references some
    // owa_* names in the "wrong" case (e.g. deleteUserRestController.php extends
    // owa_usersdeleteController, whose canonical class is owa_usersDeleteController).
    // That resolved fine while the class was globally declared; now it lives only
    // behind this bridge, so fall back to a case-insensitive lookup to preserve
    // the original semantics.
    if ($new === null) {
        static $ciMap = null;
        if ($ciMap === null) {
            $ciMap = [];
            foreach ($map as $old => $target) {
                $ciMap[strtolower($old)] = $target;
            }
        }
        $new = $ciMap[strtolower($class)] ?? null;
    }

    if ($new === null) {
        return; // not a migrated class — leave it to the require_once loaders
    }

    // Make sure the new class is actually loaded (Composer PSR-4/classmap).
    // Guard the alias so we never redefine an old name that is still declared
    // directly somewhere during the transition.
    if (class_exists($new) && !class_exists($class, false)) {
        class_alias($new, $class);
    }
});
