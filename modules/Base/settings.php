<?php

/**
 * What Base stores, and what boot needs of it.
 *
 * First generated from getDefaultSettingsArray() plus the settings that are
 * stored without one, and maintained by hand since. A key Base owns but does
 * not declare leaves the boot query's wholesale clause and is then never read,
 * so the completeness is the point. SettingsDeclarationContractTest keeps the
 * defaults here in step with getDefaultSettingsArray().
 *
 * Three kinds, and only two of them ever reach the database:
 *
 *   STATIC     a default and nothing else. Nothing can persist one, so nothing
 *              ever queries for it. This is most of them, and it is why a
 *              catalogue this size costs nothing.
 *
 *   STORABLE   something persists it. `autoload` on the ones the request path
 *              reads -- boot fetches those; the rest arrive in one batch the
 *              first time anything asks.
 *
 *   SCOPED     storable, and overridable per Property or Profile.
 *
 * Every setting has a `description`, which the wiki's settings reference is
 * generated from (tests/tools/wiki/sections/settings.php), unless it is
 * `internal`: state OWA records for itself, a per-request flag, or a URL
 * derived from public_url at boot. Nobody configures those, and the reference
 * leaves them out. A handful have neither because nothing reads them;
 * WikiDocsSettingsTest lists them.
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
 * The config-file-only settings -- paths, stream targets, database
 * credentials, report_wrapper -- are declared STATIC, which is the same
 * guarantee configFileOnlySettings() gives by listing them: never read from
 * the database. A stored error_log_file or report_wrapper is an RCE primitive,
 * so that list stays as a test asserting this file agrees with it.
 *
 * TWO SETTINGS ARE DECLARED WITH NO DEFAULT although the code has one:
 * config_file and plugin_dir. getDefaultSettingsArray() builds
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
        'app_ns' => array( 'default' => '', 'description' => 'The prefix on the parameter names in OWA\'s own admin and report URLs. Empty by default, so they read do=base.reportingHome.' ),
        'archive_old_events' => array( 'default' => true, 'description' => 'Whether the file event queue moves a processed batch file to its archive directory instead of deleting it. On by default.' ),
        'assets_url' => array( 'default' => '', 'internal' => true ),
        'async_log_dir' => array( 'default' => '', 'description' => 'The directory the file event queue writes its batch files to. Empty uses owa-data/logs/.' ),
        'base_url' => array( 'default' => '' ),
        'cacheType' => array( 'default' => '', 'description' => 'Which object cache backend is in use: file or memcached. Set by the File Cache or Memcached Cache module when it is active; empty means none.' ),
        'cache_objects' => array( 'default' => false, 'description' => 'Whether OWA keeps looked-up objects, such as parsed user agents, in its object cache between requests. Off by default; the File Cache module turns it on.' ),
        'capabilities' => array( 'default' => array( 'admin' => array( 'install_schema', 'view_site_list', 'view_reports', 'view_reports_ecommerce', 'edit_settings', 'edit_sites', 'edit_users', 'edit_modules', 'edit_reports', 'edit_own_email' ), 'analyst' => array( 'install_schema', 'view_site_list', 'view_reports', 'view_reports_ecommerce' ), 'viewer' => array( 'install_schema', 'view_site_list', 'view_reports' ), 'everyone' => array( 'install_schema' ) ), 'description' => 'Which capabilities each user role has, as role => list of capabilities.' ),
        'capabilitiesThatRequireSiteAccess' => array( 'default' => array( 'view_reports', 'view_reports_ecommerce', 'edit_sites' ), 'description' => 'The capabilities a user holds only on the sites they have been given access to, rather than on every site.' ),
        'clean_query_string' => array( 'default' => true ),
        'config_file' => array( 'description' => 'The path of the configuration file OWA reads at boot. Defaults to owa-config.php in the OWA directory.' ),
        'configuration_id' => array( 'default' => '1', 'internal' => true ),
        'cookie_domain' => array( 'default' => false, 'internal' => true ),
        'cookie_persistence' => array( 'default' => true, 'description' => 'Whether the cookies OWA\'s server sets, such as the login cookie, outlast the browser session. Off makes them session cookies.' ),
        'cube_rebuild_window_days' => array( 'default' => 7, 'description' => 'How many recent days of the reporting cube stay in daily partitions that partition-rotate will not merge, so events arriving late for those days are cheap to rebuild. Default 7.' ),
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
        // RETENTION (Classes\Retention). Months; not set keeps everything. Read by
        // partition-rotate, which drops whole partitions outside the window.
        'raw_retention_months' => array(
            // No value keeps everything; blank on the screen says so.
            'default'  => null,
            'storable' => true,
            'autoload' => true,
            // Install only: raw is one table shared by every Property, and a
            // partition can only be dropped for all of them at once.
            'type'     => 'integer',
            'min'      => 1,
            'max'      => 1200,
            'blank'    => 'Keep everything',
            'label'    => 'Keep event data (months)',
            'description' =>
                'How many months of tracked events to keep. Older months are deleted at the next '
                . 'daily maintenance run and <strong>cannot be recovered</strong>. Reporting data '
                . 'can never reach further back than this. Leave blank to keep everything.',
        ),
        'cube_retention_months' => array(
            // No value is the same window as event data.
            'default'  => null,
            'storable' => true,
            'autoload' => true,
            // Per Property: one cube per Property, dropped and rebuilt on its own.
            'scopes'   => array( 'install', 'property' ),
            'type'     => 'integer',
            'min'      => 1,
            'max'      => 1200,
            'blank'    => 'Same as event data',
            'label'    => 'Keep reporting data (months)',
            'description' =>
                'How many months reports can show. Shortening it removes older months from reports '
                . 'only: they are rebuilt from the event data if it is lengthened again, as far back '
                . 'as event data is kept. Leave blank to keep as much as event data.',
        ),
        'currencyLocal' => array( 'default' => 'en_US', 'description' => 'The locale revenue is formatted with in reports, such as en_US.' ),
        'db_force_new_connections' => array( 'default' => true ),
        'db_host' => array( 'default' => '', 'description' => 'The host name of the database server.' ),
        'db_make_persistant_connections' => array( 'default' => false, 'description' => 'Whether OWA opens persistent database connections that PHP reuses across requests. Off by default.' ),
        'db_name' => array( 'default' => '', 'description' => 'The name of the database OWA uses.' ),
        /*
         * 'secret' so that no screen can print it. It is static and governed by
         * OWA_DB_PASSWORD, so it should never appear on a settings page at all
         * -- but a governed field renders read-only by design, and this is the
         * one governed value where showing what is in force would be a leak
         * rather than a courtesy.
         */
        'db_password' => array( 'default' => '', 'secret' => true, 'description' => 'The database user\'s password.' ),
        'db_port' => array( 'default' => 3306, 'description' => 'The port the database server listens on. Default 3306.' ),
        'db_supported_types' => array( 'default' => array( 'mysql' => 'MySQL / MariaDB' ), 'internal' => true ),
        'db_type' => array( 'default' => '', 'description' => 'The database driver: mysql (PDO where available, otherwise mysqli), mysqli or pdo_mysql.' ),
        'db_user' => array( 'default' => '', 'description' => 'The user name OWA connects to the database as.' ),
        'default_cache_expiration_period' => array( 'default' => 604800, 'description' => 'How long a parsed user agent stays in the object cache, in seconds. Default 604800 (7 days).' ),
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
        'default_reporting_period' => array( 'default' => 'last_seven_days', 'description' => 'The date range a report shows when none is chosen. Default last_seven_days.' ),
        'disableAllEndpoints' => array( 'default' => false, 'description' => 'When true, turns off every entry point: index.php, log.php, api/index.php, cli.php and install.php.' ),
        'disabledEndpoints' => array( 'default' => array(), 'description' => 'Entry-point file names to turn off, such as install.php. Empty by default.' ),
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
        'error_log_file' => array( 'default' => '', 'description' => 'The path of OWA\'s error log. Empty uses a file in owa-data/logs/ named errors_ followed by a hash of the instance.' ),
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
        'feed_subscription_param' => array( 'default' => 'sid', 'description' => 'The name, after the ns prefix, of the URL parameter that carries a feed subscriber id. It is removed from page URLs before they are stored. Default sid (owa_sid).' ),
        'geolocation_lookup' => array( 'default' => false, 'description' => 'Whether OWA resolves a location from each visitor\'s IP address. Turned on by the Maxmind GeoIP module when it is active.' ),
        'geolocation_service' => array( 'default' => '', 'description' => 'Which geolocation service resolves locations. Set to maxmind by the Maxmind GeoIP module.' ),
        'goals' => array( 'storable' => true, 'scopes' => array( 'install', 'property', 'profile' ), 'internal' => true ),
        'images_url' => array( 'default' => '', 'internal' => true ),
        'install_complete' => array( 'storable' => true, 'autoload' => true, 'internal' => true ),
        'is_embedded_admin_user_password_reset' => array( 'storable' => true, 'internal' => true ),
        'link_template' => array( 'default' => '%s?%s', 'description' => 'The sprintf format OWA builds its own links with: the URL, then the query string. Default %s?%s.' ),
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
        'log_visitor_pii' => array( 'default' => true, 'description' => 'Whether the user_id an event carries is stored. Off removes it before the event is written.' ),
        'logo_image_path' => array( 'default' => 'base/i/owa-logo-100w.png', 'description' => 'The logo shown in the header of OWA\'s screens, as a path under public/.' ),
        'mailer-from' => array( 'default' => '', 'description' => 'The address OWA\'s e-mail is sent from. Empty uses owa@ and the host name OWA is served from.' ),
        'mailer-fromName' => array( 'default' => 'OWA Server', 'description' => 'The sender name on OWA\'s e-mail. Default OWA Server.' ),
        'mailer-host' => array( 'default' => '', 'description' => 'The SMTP server host, used when mailer-use-smtp is on.' ),
        'mailer-password' => array( 'default' => '', 'description' => 'The password for the SMTP server, used with mailer-username.' ),
        'mailer-port' => array( 'default' => '', 'description' => 'The SMTP server port. Empty uses the mail library\'s default.' ),
        'mailer-smtpAuth' => array( 'default' => false, 'description' => 'Whether OWA authenticates to the SMTP server. Off by default.' ),
        'mailer-use-smtp' => array( 'default' => false, 'description' => 'Whether OWA sends e-mail through an SMTP server instead of PHP\'s mail(). Off by default.' ),
        'mailer-username' => array( 'default' => '', 'description' => 'The user name for the SMTP server, used with mailer-password.' ),
        'maxCustomVars' => array( 'default' => 5, 'description' => 'How many numbered custom variables (cv1, cv2 and so on) a beacon may carry. Default 5.' ),
        'memcachedPersistantConnections' => array( 'default' => true ),
        'memcachedServers' => array( 'default' => array() ),
        'modules' => array( 'default' => array( 'base' ) ),
        'nonce_expiration_period' => array( 'default' => 7200, 'description' => 'The length, in seconds, of the time window a form nonce is issued for. Default 7200 (2 hours).' ),
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
        'ns' => array( 'default' => 'owa_', 'description' => 'The prefix on OWA\'s cookie names and on the URL parameters it reads from tracked pages, such as owa_source and owa_state. Default owa_. Changing it orphans every existing cookie and campaign link.' ),

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
            'description' =>
                'Renames the URL parameters campaign tags are read from, as role => parameter name. '
                . 'The roles are source, medium, campaign, search_terms, ad and ad_type; a role not named '
                . 'keeps its ns-prefixed name, such as owa_source.',
        ),
        'numGoals' => array( 'default' => 15, 'description' => 'How many goal slots the funnel visualization offers. Default 15.' ),
        'owa_news_url' => array( 'default' => 'https://api.github.com/repositories/3891123/releases?page=1&per_page=5', 'description' => 'The URL fetch-notifications reads OWA release notifications from.' ),
        'owa_user_agent' => array( 'default' => 'Open Web Analytics Bot master', 'description' => 'The User-Agent header OWA sends on its own outbound HTTP requests.' ),
        'partition_detail_months' => array( 'default' => 36, 'description' => 'How many recent months of a partitioned table keep their fine-grained partitions before partition-rotate may merge older ones. Default 36.' ),
        'partition_max_partitions' => array( 'default' => 0, 'description' => 'The most partitions partition-rotate lets one table hold. 0 uses the built-in ceiling of 400.' ),
        'password_length' => array( 'default' => 4 ),
        'plugin_dir' => array(),
        'public_path' => array( 'default' => '', 'description' => 'The filesystem path of OWA\'s public/ directory. Empty uses public/ inside the OWA directory.' ),
        'public_url' => array( 'default' => '', 'description' => 'The URL of the OWA directory, ending in a slash. Links, assets and tracker URLs are built from it.' ),
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
        'images_absolute_url' => array( 'internal' => true ),
        'is_embedded'         => array( 'internal' => true ),
        'log_url'             => array( 'internal' => true ),
        'main_url'            => array( 'internal' => true ),
        'modules_url'         => array( 'internal' => true ),
        'rest_api_url'        => array( 'internal' => true ),
        'tracking_mode'       => array( 'internal' => true ),
        'ua_regexes_dir'      => array( 'description' => 'The directory update-ua-regexes writes refreshed user-agent patterns to, and OWA reads them from. Empty uses owa-data/ua-parser/.' ),

        'queue_events' => array( 'default' => false, 'description' => 'The 1.x name for queueing incoming beacons. Read only when queue_tracker_ingest is not set.' ),

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
        'queue_incoming_tracking_events' => array( 'storable' => true, 'autoload' => true, 'description' => 'A stored 1.x name for queueing incoming beacons. Read only when queue_tracker_ingest is not set.' ),

        /*
         * The tracking intake (PLAN 2.30.3). queue_tracker_ingest defaults to
         * NULL, not false: null is "not set", which is when the 1.x names
         * above are read (Classes\TrackerIngest::isQueued()).
         */
        'queue_tracker_ingest' => array( 'default' => null, 'storable' => true, 'autoload' => true, 'description' => 'Whether log.php queues each beacon for the drain-tracker-ingest job instead of writing it at once. Not set falls back to the 1.x names queue_events and queue_incoming_tracking_events.' ),

        /*
         * The built tracker that last applied an update (Module::isUpToDate()):
         * a hash other than the build's (TrackerBundle::buildHash()) is an update
         * pending, which republishes the Profiles' bundles. The scheduler keeps
         * running meanwhile; only a schema behind stops it. No default, like
         * schema_version: an install with none from 1.x has an update pending.
         */
        'tracker_build' => array( 'storable' => true, 'autoload' => true, 'internal' => true ),
        'tracker_ingest_queue_type' => array( 'default' => 'file', 'description' => 'Where queued beacons wait: file, or sqs with the AWS SQS module active.' ),
        'tracker_ingest_drain' => array( 'default' => 'scheduler', 'description' => 'What ingests queued beacons: scheduler (the drain-tracker-ingest job) or external (a consumer outside OWA).' ),
        'report_wrapper' => array( 'default' => 'wrapper_default.php', 'description' => 'The template that wraps OWA\'s admin and report pages. Default wrapper_default.php.' ),
        'request_mode' => array( 'default' => 'web_app', 'internal' => true ),
        'reserved_words' => array( 'default' => array( 'do' => 'action' ), 'description' => 'Request parameters read under another name, as name => alias. By default a parameter named action is read as do.' ),
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
        'scheduled_jobs' => array( 'default' => array(), 'description' => 'Changes to the scheduled jobs, keyed by job name: a different schedule or params, a job turned off, or a job of your own.' ),
        'scheduler_enabled' => array( 'default' => true, 'description' => 'Whether schedule-run runs any job. False stops every scheduled job without touching crontab.' ),
        'session_length' => array( 'default' => 1800, 'description' => 'How long a visitor can be inactive before their next event starts a new session, in seconds. Default 1800 (30 minutes).' ),
        'site_id' => array( 'default' => '' ),
        'source_param' => array( 'default' => 'source' ),
        'start_page' => array( 'default' => 'base.reportingHome', 'description' => 'The screen OWA opens after login, and when index.php is requested with no action. Default base.reportingHome.' ),
        'theme' => array( 'default' => '', 'description' => 'A directory under the themes directory whose templates override module templates. Empty uses none.' ),
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
        'ua-regexes' => array( 'default' => '', 'description' => 'The path of a user-agent regexes.php file to parse user agents with. Empty uses the one in ua_regexes_dir if present, otherwise the bundled one.' ),
        /*
         * THE TRACKING TAG (PLAN 2.24.4). What a Profile's tracking bundle does,
         * baked in at publish as the tracker's own options and commands -- the
         * ones a snippet could push. These govern the tracker only: the server's
         * cookie_persistence and cookie_domain, above, are for OWA's own admin
         * cookies and are not read here.
         */
        // Publishing's last read-back of the cache header a bundle is served with. No screen edits it.
        'tracker_cache_headers' => array( 'default' => array(), 'storable' => true, 'internal' => true ),
        'tracker_page_views' => array(
            'default'  => true,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Page Views',
            'description' => 'Sends a page view when the page loads.',
        ),
        'tracker_clicks' => array(
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::starts( 'trackClicks' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Clicks',
            'description' => 'Sends a click event for each click, with the element clicked and where the link led.',
        ),
        'tracker_forms' => array(
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::starts( 'trackForms' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Forms',
            'description' => 'Sends an event when a visitor starts and when they submit a form.',
        ),
        'tracker_scroll' => array(
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::starts( 'trackScroll' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Scroll Depth',
            'description' => 'Sends one event per page view the visitor scrolls, at the deepest scroll threshold reached.',
        ),
        'tracker_site_search' => array(
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::starts( 'trackSiteSearch' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track Site Search',
            'description' => 'Sends a search event when a page&rsquo;s URL carries one of the site search parameters.',
        ),
        'tracker_exceptions' => array(
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::starts( 'trackExceptions' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Track JavaScript Errors',
            'description' => 'Sends an event for each uncaught JavaScript error on the page.',
        ),
        'tracker_route_changes' => array(
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::starts( 'trackRouteChanges' ),
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
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::text( 'scrollThresholds' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Scroll Thresholds',
            'description' => 'The scroll depths, in percent of the page, that a page view\'s deepest point is reported at. Separate them with commas.',
            'pattern'  => '/^(100|[1-9][0-9]?)(\\s*,\\s*(100|[1-9][0-9]?))*$/',
            'pattern_problem' => \OWA\Core\CoreAPI::t( 'Scroll Thresholds is a comma-separated list of percentages from 1 to 100.' ),
        ),
        'tracker_site_search_params' => array(
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::text( 'siteSearchParams' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Site Search Parameters',
            'description' => 'The URL parameters that carry a search term on your site. Separate them with commas.',
            'pattern'  => '/^[A-Za-z0-9_.\\-\\[\\]]+(\\s*,\\s*[A-Za-z0-9_.\\-\\[\\]]+)*$/',
            'pattern_problem' => \OWA\Core\CoreAPI::t( 'Site Search Parameters is a comma-separated list of URL parameter names.' ),
        ),
        'tracker_download_extensions' => array(
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::text( 'downloadExtensions' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'text',
            'label'    => 'Download File Extensions',
            'description' => 'A click on a link to a file with one of these extensions is a download. Separate them with commas.',
            'pattern'  => '/^[A-Za-z0-9]+(\\s*,\\s*[A-Za-z0-9]+)*$/',
            'pattern_problem' => \OWA\Core\CoreAPI::t( 'Download File Extensions is a comma-separated list of file extensions, without the dot.' ),
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
            'pattern_problem' => \OWA\Core\CoreAPI::t( 'Tracker Cookie Domain is a domain name, such as example.com.' ),
        ),
        'useStaticConfigOnly' => array( 'default' => false, 'description' => 'When true, OWA does not load stored settings from the database at boot and runs on code defaults plus the config file. For a node that only logs beacons.' ),
        'use_32bit_hash' => array( 'default' => false, 'storable' => true, 'internal' => true ),
        // Set by Update034 when it re-keyed an installation that arrived still
        // deriving 32-bit ids, so its down() can put them back.
        'use_32bit_hash_before_v2' => array( 'default' => false, 'storable' => true, 'internal' => true ),
        // Set by cmd=v1-drop. Update062's down() refuses once it is: with v1's
        // tables gone there is nothing to revert to.
        'v1_tables_dropped' => array( 'default' => false, 'storable' => true, 'internal' => true ),
        'user_id_illegal_chars' => array( 'default' => array( ' ', ';', '\'', '"', '|', ')', '(' ), 'description' => 'Characters a user name may not contain.' ),
        'wiki_url' => array( 'default' => 'https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki', 'description' => 'The base URL of the documentation OWA\'s screens link to.' ),
    ),
);
