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
 * `schema_version` and `is_active` are NOT here. Core\Module::settingsRegistry()
 * adds those to every module, eager and without a default -- a default would let
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
        'announce_visitors' => array(
            'default'  => false,
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Announce New Visitors Via E-mail',
            'description' =>
                'Announces each new visitor to your web site via e-mail. If you have '
                . 'a lot of visitors then you probably want to keep this feature turned '
                . 'off.',
        ),
        'anonymize_ips' => array(
            'default'  => false,
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Anonymize IP Addresses',
            'description' =>
                'Anonymizes the IP addresses of visitors by removing the last octet '
                . 'from their IP address.',
        ),
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
        /*
         * 'secret' so that no screen can print it. It is static and governed by
         * OWA_DB_PASSWORD, so it should never appear on a settings page at all
         * -- but a governed field renders read-only by design, and this is the
         * one governed value where showing what is in force would be a leak
         * rather than a courtesy.
         */
        'db_password' => array( 'default' => '', 'secret' => true ),
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
        'excluded_ips' => array(
            'default'  => '',
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Excluded IP Addresses',
            'description' =>
                'Enter a comma seperated list of the IP addresses that you wish to '
                . 'exclude from tracking.',
        ),
        'feed_subscription_param' => array( 'default' => 'sid' ),
        'geolocation_lookup' => array( 'default' => false ),
        'geolocation_service' => array( 'default' => '' ),
        'goal_groups' => array( 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'goals' => array( 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ) ),
        'images_url' => array( 'default' => '' ),
        'install_complete' => array( 'storable' => true, 'autoload' => true ),
        'is_embedded_admin_user_password_reset' => array( 'storable' => true ),
        'link_template' => array( 'default' => '%s?%s' ),
        'log_named_users' => array(
            'default'  => true,
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Log Requests From Named Users',
            'description' =>
                'Controls the logging of requests made by named users.',
        ),
        'log_robots' => array(
            'default'  => false,
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Log Requests From Known Robots',
            'description' =>
                'Controls the logging of page requests made by known robots and '
                . 'spiders. Turning this feature on will dramatically increase the '
                . 'number of requests that are processed and logged.',
        ),
        /*
         * Whether user_id is stored. It gated user_name and user_email, which are
         * custom user properties now (PLAN.html §2.26.1), so it moves to the one
         * identity field left in the release vocabulary -- see gateUserId().
         */
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
        'notice_email' => array(
            'default'  => '',
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Notice E-mail Address',
            'description' =>
                'This is the e-mail address that new visitor e-mails will be sent to.',
        ),
        'ns' => array( 'default' => 'owa_' ),

        /*
         * The URL parameter names a campaign tag arrives under.
         *
         * EMPTY MEANS ns-PREFIXED, which is what OWA has always done: owa_source,
         * owa_medium and so on, honouring a custom `ns`. Setting it names the
         * parameters explicitly instead, which is how a site opts into GA's --
         * utm_source, utm_medium, utm_campaign, utm_term, utm_content -- without
         * having to change its links.
         *
         * PROPERTY-SCOPED, because a Property is a website and its links are its
         * own. The install default covers the common case of one convention
         * everywhere; a Property that arrived from a GA setup overrides it.
         *
         * It has to be a SERVER setting. The tracker used to parse the tags and
         * had setCampaignSourceKey() and friends for exactly this, but the parse
         * moved server-side and the server built its own ns-prefixed list -- so a
         * site calling those setters was renaming a key nothing read, and its
         * campaigns silently stopped being attributed.
         *
         * Keyed by ROLE, not by parameter name, so the two ends cannot disagree
         * about which tag is the medium.
         */
        'campaignKeys' => array(
            'default'  => array(),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
        ),
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
        'query_string_filters' => array(
            'default'  => '',
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'URL Parameters',
            'description' =>
                'This setting controls the URL parameters that OWA should ignore when '
                . 'processing requests. This is useful for avoiding duplicate URLs due '
                . 'to the use of tracking or others state parameters in your URLs. '
                . 'Parameter names should be separated by comma.',
        ),
        'query_strings.ini' => array(),
        /*
         * COMPUTED AT BOOT, never stored, and declared anyway.
         *
         * setupPaths() derives these from OWA_DIR and the configured main_url,
         * and RequestContainer/Browscap read them back. They have no literal
         * default, so generating this file from getDefaultSettingsArray() did
         * not produce them -- and a key read on a module that HAS declared is a
         * key whose stored value would never be fetched. Declaring them static
         * says that deliberately rather than by omission, and keeps the
         * catalogue complete enough for the read sweep to need no exceptions.
         */
        'images_absolute_url' => array(),
        'is_embedded'         => array(),
        'log_url'             => array(),
        'main_url'            => array(),
        'modules_url'         => array(),
        'rest_api_url'        => array(),
        'tracking_mode'       => array(),
        'ua_regexes_dir'      => array(),

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
        'resolve_hosts' => array(
            'default'  => true,
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Resolve Host Names',
            'description' =>
                'Controls the resolution of host names (e.g. verizon.com) from '
                . 'visitor\'s raw IP addresses.',
        ),
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
        /*
         * 'timezone' is a type of its own, not a select with 400 options in the
         * declaration. The list is the IANA zones grouped by country, built from
         * conf/country2Timezones.php at render time -- a declaration is a data
         * file and has no business carrying that.
         */
        'timezone' => array(
            'default'  => 'America/Los_Angeles',
            'storable' => true,
            'autoload' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'timezone',
            'label'    => 'Reporting Timezone',
            'description' =>
                'This is the timezone that should be used to generate statistics for '
                . 'a specific time period.<br><br><strong>Changing this is not '
                . 'retroactive.</strong> Each request is filed under a calendar day '
                . 'when it is recorded, using the timezone set at that moment. Data '
                . 'already collected keeps the day boundaries it was recorded with, so '
                . 'a change applies only to traffic from this point on &mdash; and '
                . 'reports spanning the change will mix the two. Depending on how far '
                . 'the zones are apart, a day boundary can move by up to 21 hours.',
        ),
        'tracking_event_types' => array( 'default' => array( 'dom.click', 'ecommerce.transaction', 'base.page_request', 'dom.stream', 'base.feed_request', 'track.action' ) ),
        'ua-regexes' => array( 'default' => '' ),
        'update_session_user_name' => array( 'default' => true ),
        'useStaticConfigOnly' => array( 'default' => false ),
        'use_32bit_hash' => array( 'default' => false, 'storable' => true ),
        'user_id_illegal_chars' => array( 'default' => array( ' ', ';', '\'', '"', '|', ')', '(' ) ),
        /*
         * EVERY EVENT NAME v2 SENDS, and three of them were missing.
         *
         * This held only the events with no v1 equivalent. The four that were
         * RENAMED -- page_view, click, purchase, custom_event -- were registered
         * under their v1 spellings alone (base.page_request, dom.click,
         * ecommerce.transaction, track.action), and 4b93b248 made the tracker send
         * the new names. Only custom_event happened to be listed here as well.
         *
         * So a real page_view, click or purchase beacon from the current tracker
         * was REFUSED at the door: CoreAPI::trackingEventTypes() merges this list
         * with the v1 one and logEvent() checks it, so the event never reached a
         * handler. Measured: logEvent('page_view') returned false and wrote no
         * row, while logEvent('base.page_request') wrote three.
         *
         * Nothing caught it because every PHP fixture and test fires the v1
         * dispatch name. The e2e specs were the only thing driving a real tracker,
         * and their own assertions were matching v1 names too, so they timed out
         * waiting for a beacon and reported an empty database instead.
         *
         * All three consumers merge THIS setting -- trackingEventTypes(), the
         * EventRawHandlers registration and the base.processRequest processor --
         * so the names belong here rather than in three lists.
         */
        'v2_event_types' => array( 'default' => array(
            // renamed from v1; the old spellings stay registered for a cached tracker
            'page_view', 'click', 'purchase', 'custom_event',
            // new in v2
            'user_engagement', 'scroll', 'file_download', 'form_start', 'form_submit',
            'view_search_results', 'exception',
        ) ),
        'wiki_url' => array( 'default' => 'https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki' ),    ),
);
