<?php

/**
 * Base's install-wide settings screens and the fieldsets they render.
 *
 * Base\Module::registerAdminPanels() registers each page with
 * registerSettingsPage() and each fieldset with registerSettingsFieldSet().
 * Included when the admin nav is built, so the titles are translated then.
 *
 * The settings nav is for INSTALL-WIDE configuration only.
 *
 * Everything else hangs off the Organization / Property / Profile hierarchy and
 * is reached from the site control: user accounts on the Organization, goals on
 * a Profile. No Properties panel: the hierarchy is navigated from the site
 * control above the report nav, which also reaches the per-Property edit
 * screen.
 *
 * What is here is install-scoped: the modules that are active, and the
 * timezone -- which cannot move down, because yyyymmdd and nine other date
 * parts are baked into every fact row at collection in the configured zone, so
 * it is not retroactive and two profiles disagreeing about it would put rows of
 * different meanings in one table with nothing recording which.
 */
return [

    'pages' => [

        [
            'do'              => 'base.optionsGeneral',
            'title'           => \OWA\Core\CoreAPI::t( 'General' ),
            'group'           => 'General',
            'order'           => 1,
            'fieldsets'       => [
                'base.tracking', 'base.announcements', 'base.reporting', 'base.restApi' ],
            'fieldset_groups' => [ 'tracking_tag' ],
        ],

        // How long data is kept (Classes\Retention). Its own page: lowering the
        // first of these deletes data, and that should not sit among routine settings.
        [
            'do'        => 'base.optionsRetention',
            'title'     => \OWA\Core\CoreAPI::t( 'Data retention' ),
            'group'     => 'General',
            'order'     => 6,
            'fieldsets' => [ 'base.retention' ],
        ],

        [
            'do'    => 'base.optionsModules',
            'title' => \OWA\Core\CoreAPI::t( 'Modules' ),
            'group' => 'General',
            'order' => 3,
        ],

        /*
         * The health of every reporting cube. Install-wide because whether the
         * scheduler is building them is a fact about the installation, and
         * beside Modules because it answers the same kind of question: is this
         * installation doing what it should.
         */
        [
            'do'    => 'base.cubeStatus',
            'title' => \OWA\Core\CoreAPI::t( 'Reporting cubes' ),
            'group' => 'General',
            'order' => 4,
        ],

        // Is the background work happening: the scheduler, its jobs, the job queue, the tracker intake.
        [
            'do'    => 'base.systemHealth',
            'title' => \OWA\Core\CoreAPI::t( 'System health' ),
            'group' => 'General',
            'order' => 5,
        ],
    ],

    /*
     * The general options page, as fieldsets rather than as HTML.
     *
     * What each control looks like is already in the declaration -- `type`
     * says select or text, `label` and `description` say what to call it --
     * so the screen does not spell any of that out a second time. The order
     * of `settings` is the order they render in.
     */
    'fieldsets' => [

        [
            'id'       => 'base.tracking',
            'legend'   => \OWA\Core\CoreAPI::t( 'Tracking Request Processing' ),
            'settings' => [
                'spam_detection',
                'spam_threshold',
                'log_named_users',
                'excluded_ips',
                'anonymize_ips',
                'query_string_filters',
                'campaignUtmParams',
            ],
        ],

        [
            'id'       => 'base.announcements',
            'legend'   => \OWA\Core\CoreAPI::t( 'Visitor Announcements' ),
            'settings' => [ 'announce_visitors', 'notice_email' ],
        ],

        [
            'id'       => 'base.reporting',
            'legend'   => \OWA\Core\CoreAPI::t( 'Reporting' ),
            'settings' => [ 'timezone', 'attribution_lookback_days' ],
        ],

        // The group is what the wiki's Application Passwords page generates its
        // settings table from (settings-rest_api); no page renders it by group.
        [
            'id'       => 'base.restApi',
            'group'    => 'rest_api',
            'legend'   => \OWA\Core\CoreAPI::t( 'REST API' ),
            'settings' => [ 'app_passwords_enabled', 'app_passwords_require_https' ],
        ],

        [
            'id'          => 'base.retention',
            'legend'      => \OWA\Core\CoreAPI::t( 'Data Retention' ),
            'description' => \OWA\Core\CoreAPI::t( 'Each night\'s maintenance run (rotate-partitions) removes data outside these windows. '
                . 'Every Property can set its own reporting window on its Data Retention page.' ),
            'settings'    => [ 'raw_retention_months', 'cube_retention_months' ],
        ],
    ],
];
