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
 *   SCOPED (6)    storable, and overridable per Property or Profile. These are
 *                 the keys the Observation Settings screen writes, plus `goals`,
 *                 1.x's per-site goal blob, which only Update017 and Update025
 *                 read to migrate it into goal events.
 *
 * `schema_version` and `is_active` are NOT here. Core\Module::settingsRegistry()
 * adds those to every module, eager and without a default -- a default would let
 * pruneRedundantPersistedSettings() drop them and the module would look
 * uninstalled.
 *
 * Three settings are declared with no default for the same reason:
 * install_complete, domain_aliases and goals are stored and have never had a
 * code default, and inventing one would put them within reach of the prune.
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
        'attribution_lookback_days' => array(
            'default'  => 90,
            'storable' => true,
            'autoload' => true,
            // Per Property, not per Profile: one cube per Property applies it.
            'scopes'   => array( 'install', 'property' ),
            'type'     => 'integer',
            'min'      => 0,
            'max'      => 365,
            'label'    => 'Attribution lookback (days)',
            'description' =>
                'How far back a session that arrives directly takes the visitor&rsquo;s last '
                . 'campaign, search or referral visit as its attributed source. A change reaches '
                . 'past reports when their days are rebuilt (cube-rebuild).',
        ),
        'currencyISO3' => array(
            'default'  => 'USD',
            'storable' => true,
            'autoload' => true,
            // Not per Profile: a Property's revenue is summed in one cube, and
            // two currencies in it would add unlike amounts.
            'scopes'   => array( 'install', 'property' ),
            'type'     => 'text',
            'label'    => 'Currency',
            'description' =>
                'The ISO 4217 code revenue is recorded and reported in, such as USD or EUR. '
                . 'A purchase that names no currency is recorded in this one.',
        ),
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
        'db_supported_types' => array( 'default' => array( 'mysql' => 'MySQL / MariaDB' ) ),
        'db_type' => array( 'default' => '' ),
        'db_user' => array( 'default' => '' ),
        'default_cache_expiration_period' => array( 'default' => 604800 ),
        'default_page' => array(
            'default'  => '',
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Default Page',
            'description' =>
                'The page your web server serves when a URL names none (e.g. index.html), so '
                . 'www.domain.com and www.domain.com/index.html count as one page.',
        ),
        'default_reporting_period' => array( 'default' => 'last_seven_days' ),
        'disableAllEndpoints' => array( 'default' => false ),
        'disabledEndpoints' => array( 'default' => array() ),
        'domain_aliases' => array(
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Domain Aliases',
            'description' =>
                'Other domain names to treat as this one. If the domain is www.mydomain.com, '
                . 'an alias of mydomain.com counts both as the same. Separate aliases with commas.',
        ),
        'enableEcommerceReporting' => array(
            'default'  => false,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'e-commerce Reporting',
            'description' => 'Adds e-commerce metrics to reports.',
        ),
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
         * parameters explicitly instead, which is how a site opts into
         * utm_source, utm_medium, utm_campaign, utm_term, utm_content without
         * having to change its links.
         *
         * PROPERTY-SCOPED, because a Property is a website and its links are its
         * own. The install default covers the common case of one convention
         * everywhere; a Property whose links already use utm_* overrides it.
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
        'campaignUtmParams' => array(
            'default'  => true,
            'storable' => true,
            'scopes'   => array( 'install', 'property' ),
            'type'     => 'boolean',
            'label'    => 'Read utm_ Campaign Parameters',
            'description' =>
                'Reads utm_source, utm_medium, utm_campaign, utm_term and utm_content from a '
                . 'landing URL as well as OWA&rsquo;s own owa_ parameters. When a URL carries '
                . 'both, the owa_ value is used. Applies to visits recorded after the change.',
        ),
        'campaignKeys' => array(
            'default'  => array(),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
        ),
        'numGoals' => array( 'default' => 15 ),
        'owa_news_url' => array( 'default' => 'https://api.github.com/repositories/3891123/releases?page=1&per_page=5' ),
        'owa_user_agent' => array( 'default' => 'Open Web Analytics Bot master' ),
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

        /*
         * The tracking intake (PLAN 2.30.3). queue_tracker_ingest defaults to
         * NULL, not false: null is "not set", which is when the 1.x names
         * above are read (Classes\TrackerIngest::isQueued()).
         */
        'queue_tracker_ingest' => array( 'default' => null, 'storable' => true, 'autoload' => true ),

        /*
         * The tracker version that last applied an update (Module::isUpToDate()):
         * lower than the one tracker-version.php records is an update pending,
         * which republishes the Profiles' bundles. The scheduler keeps running
         * meanwhile; only a schema behind stops it. No default, like
         * schema_version: an install with none from 1.x has an update pending.
         */
        'tracker_version' => array( 'storable' => true, 'autoload' => true ),
        'tracker_ingest_queue_type' => array( 'default' => 'file' ),
        'tracker_ingest_drain' => array( 'default' => 'scheduler' ),
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
        'ua-regexes' => array( 'default' => '' ),
        /*
         * THE TRACKING TAG (PLAN 2.24.4). What a Profile's tracking bundle does,
         * baked in at publish as the tracker's own options and commands -- the
         * ones a snippet could push. These govern the tracker only: the server's
         * cookie_persistence and cookie_domain, above, are for OWA's own admin
         * cookies and are not read here.
         */
        // Publishing's last read-back of the cache header a bundle is served with. No screen edits it.
        'tracker_cache_headers' => array( 'default' => array(), 'storable' => true ),
        'tracker_page_views' => array(
            'default'  => true,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Page Views',
            'description' => 'Sends a page view when the page loads.',
        ),
        'tracker_clicks' => array(
            'default'  => true,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Clicks',
            'description' => 'Sends a click event for each click, with the element clicked and where the link led.',
        ),
        'tracker_forms' => array(
            'default'  => true,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Forms',
            'description' => 'Sends an event when a visitor starts and when they submit a form.',
        ),
        'tracker_scroll' => array(
            'default'  => true,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Scroll Depth',
            'description' => 'Sends an event as the visitor scrolls past each scroll threshold.',
        ),
        'tracker_site_search' => array(
            'default'  => true,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Site Search',
            'description' => 'Sends a search event when a page&rsquo;s URL carries one of the site search parameters.',
        ),
        'tracker_exceptions' => array(
            'default'  => false,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track JavaScript Errors',
            'description' => 'Sends an event for each uncaught JavaScript error on the page.',
        ),
        'tracker_route_changes' => array(
            'default'  => false,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Route Changes',
            'description' => 'For single-page applications: sends a page view when the URL changes without a page load.',
        ),
        'tracker_url_fragments' => array(
            'default'  => false,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Keep URL Fragments',
            'description' => 'Keeps the part of a URL after # in page locations, for sites whose pages are told apart by it.',
        ),
        'tracker_scroll_thresholds' => array(
            'default'  => '25, 50, 75, 90',
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Scroll Thresholds',
            'description' => 'The scroll depths, in percent of the page, that each send a scroll event. Separate them with commas.',
            'pattern'  => '/^(100|[1-9][0-9]?)(\\s*,\\s*(100|[1-9][0-9]?))*$/',
            'pattern_problem' => 'Scroll Thresholds is a comma-separated list of percentages from 1 to 100.',
        ),
        'tracker_site_search_params' => array(
            'default'  => 'q, s, search, query, keyword',
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Site Search Parameters',
            'description' => 'The URL parameters that carry a search term on your site. Separate them with commas.',
            'pattern'  => '/^[A-Za-z0-9_.\\-\\[\\]]+(\\s*,\\s*[A-Za-z0-9_.\\-\\[\\]]+)*$/',
            'pattern_problem' => 'Site Search Parameters is a comma-separated list of URL parameter names.',
        ),
        'tracker_download_extensions' => array(
            'default'  => 'pdf, doc, docx, xls, xlsx, ppt, pptx, csv, txt, rtf, zip, gz, tar, rar, 7z, dmg, pkg, exe, mp3, wav, mp4, mov, avi, wmv, epub, mobi',
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Download File Extensions',
            'description' => 'A click on a link to a file with one of these extensions is a download. Separate them with commas.',
            'pattern'  => '/^[A-Za-z0-9]+(\\s*,\\s*[A-Za-z0-9]+)*$/',
            'pattern_problem' => 'Download File Extensions is a comma-separated list of file extensions, without the dot.',
        ),
        'tracker_visitor_cookie_days' => array(
            'default'  => 364,
            'storable' => true,
            // Per Property: the visitor cookie is one per page, shared by every Profile on it.
            'scopes'   => array( 'install', 'property' ),
            'type'     => 'integer',
            'min'      => 0,
            'max'      => 400,
            'label'    => 'Visitor Cookie Lifetime (days)',
            'description' => 'How long the visitor cookie lasts after a visitor&rsquo;s last visit. '
                . '<strong>0 ends it when the browser closes</strong>: every browser session is then a new '
                . 'visitor, so returning visitors and attribution across visits are mostly lost. At most 400, '
                . 'the longest a browser keeps a cookie.',
        ),
        'tracker_session_cookie_days' => array(
            'default'  => 364,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'integer',
            'min'      => 0,
            'max'      => 400,
            'label'    => 'Session Cookie Lifetime (days)',
            'description' => 'How long the session cookie, which carries a visitor&rsquo;s session history, '
                . 'lasts after their last visit. 0 ends it when the browser closes. At most 400.',
        ),
        'tracker_cookie_domain' => array(
            'default'  => '',
            'storable' => true,
            'scopes'   => array( 'install', 'property' ),
            'type'     => 'text',
            'label'    => 'Tracker Cookie Domain',
            'description' => 'The domain the tracker&rsquo;s cookies are set on, such as example.com to share '
                . 'them across its subdomains. Empty uses the page&rsquo;s own domain.',
            'pattern'  => '/^$|^\\.?[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$/',
            'pattern_problem' => 'Tracker Cookie Domain is a domain name, such as example.com.',
        ),
        'update_session_user_name' => array( 'default' => true ),
        'useStaticConfigOnly' => array( 'default' => false ),
        'use_32bit_hash' => array( 'default' => false, 'storable' => true ),
        // Set by Update034 when it re-keyed an installation that arrived still
        // deriving 32-bit ids, so its down() can put them back.
        'use_32bit_hash_before_v2' => array( 'default' => false, 'storable' => true ),
        // Set by cmd=v1-drop. Update062's down() refuses once it is: with v1's
        // tables gone there is nothing to revert to.
        'v1_tables_dropped' => array( 'default' => false, 'storable' => true ),
        'user_id_illegal_chars' => array( 'default' => array( ' ', ';', '\'', '"', '|', ')', '(' ) ),
        'wiki_url' => array( 'default' => 'https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki' ),    ),
);
