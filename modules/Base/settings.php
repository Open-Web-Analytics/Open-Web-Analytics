<?php

/**
 * What Base stores, and what boot needs of it.
 *
 * GENERATED from getDefaultSettingsArray() plus the settings that are stored
 * without one, rather than hand-written: 115 entries, and a hand-written file
 * would have been partial by accident. A key Base owns but does not declare
 * leaves the boot query's wholesale clause and is then never read, so the
 * completeness is the point.
 *
 * Three kinds, and only two of them ever reach the database:
 *
 *   STATIC (97)   a default and nothing else. Nothing can persist one, so
 *                 nothing ever queries for it. This is most of them, and it
 *                 is why a catalogue this size costs nothing.
 *
 *   STORABLE (18) something persists it. `autoload` on the ten the request
 *                 path reads -- boot fetches those; the other eight arrive in
 *                 one batch the first time anything asks.
 *
 *   SCOPED (7)    storable, and overridable per Property or Profile. These are
 *                 the keys the Observation Settings screen writes, plus the
 *                 goal data the GoalManager keeps per site.
 *
 * `schema_version` and `is_active` are NOT here. Core adds those to every
 * module, eager and without a default -- a default would let
 * pruneRedundantPersistedSettings() drop them and the module would look
 * uninstalled.
 *
 * Four settings are declared with no default for the same reason:
 * install_complete, domain_aliases, goals and goal_groups are stored and have
 * never had a code default, and inventing one would put them within reach of
 * the prune.
 *
 * The 21 config-file-only settings -- paths, stream targets, database
 * credentials, report_wrapper -- are declared STATIC, which is the same
 * guarantee configFileOnlySettings() gives by listing them: never read from
 * the database. A stored error_log_file or report_wrapper is an RCE primitive,
 * so that list stays as a test asserting this file agrees with it.
 *
 * SEVEN SETTINGS ARE DECLARED WITH NO DEFAULT although the code has one:
 * config_file, db_class_dir, templates_dir, plugin_dir, module_dir,
 * search_engines.ini and query_strings.ini. getDefaultSettingsArray() builds
 * those from OWA_DIR, so their value depends on where the installation lives
 * -- baking one into a static file made the declaration disagree with the code
 * the moment the checkout moved, which the configless CI run caught by running
 * from a temporary directory. The code default still applies; the declaration
 * simply does not restate it.
 */

return array(

    'module' => 'base',

    'settings' => array(

        'action_url' => array( 'default' => '' ),
        'allow_slowly_changing_dimensions' => array( 'default' => true ),
        'allowed_queued_event_types' => array( 'default' => array() ),
        'announce_visitors' => array( 'default' => false, 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'anonymize_ips' => array( 'default' => false, 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'app_ns' => array( 'default' => '' ),
        'archive_old_events' => array( 'default' => true ),
        'assets_url' => array( 'default' => '' ),
        'async_error_log_file' => array( 'default' => 'events_error.txt' ),
        'async_lock_file' => array( 'default' => 'owa.lock' ),
        'async_log_dir' => array( 'default' => '' ),
        'async_log_file' => array( 'default' => 'events.txt' ),
        'base_url' => array( 'default' => '' ),
        'cacheType' => array( 'default' => '' ),
        'cache_objects' => array( 'default' => false ),
        'capabilities' => array( 'default' => array( 'admin' => array( 'install_schema', 'view_site_list', 'view_reports', 'view_reports_ecommerce', 'edit_settings', 'edit_sites', 'edit_users', 'edit_modules', 'edit_reports', 'edit_own_email' ), 'analyst' => array( 'install_schema', 'view_site_list', 'view_reports', 'view_reports_ecommerce' ), 'viewer' => array( 'install_schema', 'view_site_list', 'view_reports' ), 'everyone' => array( 'install_schema' ) ) ),
        'capabilitiesThatRequireSiteAccess' => array( 'default' => array( 'view_reports', 'view_reports_ecommerce', 'edit_sites' ) ),
        'clean_query_string' => array( 'default' => true ),
        'config_file' => array(),
        'configuration_id' => array( 'default' => '1' ),
        'cookie_domain' => array( 'default' => false ),
        'cookie_persistence' => array( 'default' => true ),
        'cube_rebuild_window_days' => array( 'default' => 7 ),
        'currencyISO3' => array( 'default' => 'USD' ),
        'currencyLocal' => array( 'default' => 'en_US' ),
        'db_class_dir' => array(),
        'db_force_new_connections' => array( 'default' => true ),
        'db_host' => array( 'default' => '' ),
        'db_make_persistant_connections' => array( 'default' => false ),
        'db_name' => array( 'default' => '' ),
        'db_password' => array( 'default' => '' ),
        'db_port' => array( 'default' => 3306 ),
        'db_supported_types' => array( 'default' => array( 'mysql' => 'MySQL' ) ),
        'db_type' => array( 'default' => '' ),
        'db_user' => array( 'default' => '' ),
        'default_cache_expiration_period' => array( 'default' => 604800 ),
        'default_page' => array( 'default' => '', 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'default_reporting_period' => array( 'default' => 'last_seven_days' ),
        'disableAllEndpoints' => array( 'default' => false ),
        'disabledEndpoints' => array( 'default' => array( 'queue.php' ) ),
        'domain_aliases' => array( 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'enableEcommerceReporting' => array( 'default' => false, 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'error_handler' => array( 'default' => 'production' ),
        'error_log_file' => array( 'default' => '' ),
        'excluded_ips' => array( 'default' => '', 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'feed_subscription_param' => array( 'default' => 'sid' ),
        'geolocation_lookup' => array( 'default' => false ),
        'geolocation_service' => array( 'default' => '' ),
        'goal_groups' => array( 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'goals' => array( 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'images_url' => array( 'default' => '' ),
        'install_complete' => array( 'storable' => true, 'autoload' => true ),
        'is_embedded_admin_user_password_reset' => array( 'storable' => true ),
        'link_template' => array( 'default' => '%s?%s' ),
        'log_named_users' => array( 'default' => true, 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'log_owa_user_names' => array( 'default' => true ),
        'log_robots' => array( 'default' => false, 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'log_visitor_pii' => array( 'default' => true ),
        'logo_image_path' => array( 'default' => 'base/i/owa-logo-100w.png' ),
        'mailer-from' => array( 'default' => '' ),
        'mailer-fromName' => array( 'default' => 'OWA Server' ),
        'mailer-host' => array( 'default' => '' ),
        'mailer-password' => array( 'default' => '' ),
        'mailer-port' => array( 'default' => '' ),
        'mailer-smtpAuth' => array( 'default' => false ),
        'mailer-use-smtp' => array( 'default' => false ),
        'mailer-username' => array( 'default' => '' ),
        'maxCustomVars' => array( 'default' => 5 ),
        'memcachedPersistantConnections' => array( 'default' => true ),
        'memcachedServers' => array( 'default' => array() ),
        'module_dir' => array(),
        'modules' => array( 'default' => array( 'base' ) ),
        'nonce_expiration_period' => array( 'default' => 7200 ),
        'notice_email' => array( 'default' => '', 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'ns' => array( 'default' => 'owa_' ),
        'numGoalGroups' => array( 'default' => 5 ),
        'numGoals' => array( 'default' => 15 ),
        'owa_news_url' => array( 'default' => 'https://api.github.com/repositories/3891123/releases?page=1&per_page=5' ),
        'owa_user_agent' => array( 'default' => 'Open Web Analytics Bot master' ),
        'p3p_policy' => array( 'default' => 'NOI ADM DEV PSAi COM NAV OUR OTRo STP IND DEM', 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'partition_detail_months' => array( 'default' => 36 ),
        'partition_max_partitions' => array( 'default' => 0 ),
        'password_length' => array( 'default' => 4 ),
        'plugin_dir' => array(),
        'public_path' => array( 'default' => '' ),
        'public_url' => array( 'default' => '' ),
        'query_string_filters' => array( 'default' => '', 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'query_strings.ini' => array(),
        'queue_events' => array( 'default' => false ),

        /*
         * NO DEFAULT, because the code has none -- and storable because
         * queue_e2e_helper and the queue itself persist it at runtime.
         *
         * Missing from the first version of this file. It is not in
         * getDefaultSettingsArray(), so generating from there did not find it,
         * and it was not stored on the machine the file was generated on
         * either. The e2e queue suite caught it: the helper persisted it, the
         * value was never read back because an undeclared key of a declared
         * module is not fetched, and every beacon went straight to the facts
         * instead of the queue.
         */
        'queue_incoming_tracking_events' => array( 'storable' => true, 'autoload' => true ),
        'queue_max_retry_age' => array( 'default' => 86400 ),
        'queue_max_retry_count' => array( 'default' => 25 ),
        'remote_event_queue_endpoint' => array( 'default' => '' ),
        'report_wrapper' => array( 'default' => 'wrapper_default.php' ),
        'request_mode' => array( 'default' => 'web_app' ),
        'reserved_words' => array( 'default' => array( 'do' => 'action' ) ),
        'resolve_hosts' => array( 'default' => true, 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'scheduled_jobs' => array( 'default' => array() ),
        'scheduler_enabled' => array( 'default' => true ),
        'search_engines.ini' => array(),
        'session_length' => array( 'default' => 1800 ),
        'site_id' => array( 'default' => '' ),
        'slowly_changing_dimension_entities' => array( 'default' => array() ),
        'source_param' => array( 'default' => 'source' ),
        'start_page' => array( 'default' => 'base.reportingHome' ),
        'templates_dir' => array(),
        'theme' => array( 'default' => '' ),
        'timezone' => array( 'default' => 'America/Los_Angeles', 'storable' => true, 'autoload' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'tracking_event_types' => array( 'default' => array( 'dom.click', 'ecommerce.transaction', 'base.page_request', 'dom.stream', 'base.feed_request', 'track.action' ) ),
        'ua-regexes' => array( 'default' => '' ),
        'update_session_user_name' => array( 'default' => true ),
        'useStaticConfigOnly' => array( 'default' => false ),
        'use_32bit_hash' => array( 'default' => false, 'storable' => true ),
        'user_id_illegal_chars' => array( 'default' => array( ' ', ';', '\'', '"', '|', ')', '(' ) ),
        'v2_event_types' => array( 'default' => array( 'user_engagement', 'scroll', 'file_download', 'form_start', 'form_submit', 'view_search_results', 'exception', 'custom_event' ) ),
        'wiki_url' => array( 'default' => 'https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki' ),    ),
);
