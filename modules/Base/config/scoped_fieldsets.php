<?php

/**
 * Fieldsets rendered below the install, by SettingsForm::scopedFieldSet():
 * each field shows what it inherits until its Override switch is on.
 *
 * Base\Module::registerScopedSettingsFieldSets() registers each one with
 * registerSettingsFieldSet(), from registerActions(): the Profile and Property
 * screens and their save controllers read these without building the admin
 * nav. `order` places a fieldset within its group.
 */
return [

    /*
     * The Profile's Observation Settings screen, in the `profile_observation`
     * group so its save controller (SitesEditSettings) and its template read
     * the same list.
     */
    [
        'id'       => 'base.profileObservation',
        'group'    => 'profile_observation',
        'order'    => 0,
        'legend'   => \OWA\Core\CoreAPI::t( 'Page URLs' ),
        'settings' => [ 'query_string_filters' ],
    ],
    [
        'id'       => 'base.profileCampaigns',
        'group'    => 'profile_observation',
        'order'    => 1,
        'legend'   => \OWA\Core\CoreAPI::t( 'Campaign parameters' ),
        'settings' => [ 'campaignKeys' ],
    ],
    [
        'id'       => 'base.profilePrivacy',
        'group'    => 'profile_observation',
        'order'    => 2,
        'legend'   => \OWA\Core\CoreAPI::t( 'Privacy and exclusions' ),
        'settings' => [ 'anonymize_ips', 'excluded_ips', 'log_named_users' ],
    ],
    [
        'id'       => 'base.profileAnnouncements',
        'group'    => 'profile_observation',
        'order'    => 3,
        'legend'   => \OWA\Core\CoreAPI::t( 'Visitor announcements' ),
        'settings' => [ 'announce_visitors', 'notice_email' ],
    ],

    [
        'id'       => 'base.propertyAttribution',
        'settings' => [ 'attribution_lookback_days', 'campaignUtmParams', 'campaignKeys' ],
    ],

    /*
     * What a Property's reports show. On the Property, not the Profile: a
     * report is of the Property and covers every Profile in it.
     */
    [
        'id'       => 'base.propertyReporting',
        'settings' => [ 'enableEcommerceReporting', 'currencyISO3' ],
    ],

    // The Property's Data Retention screen (Classes\Retention).
    [
        'id'       => 'base.propertyRetention',
        'settings' => [ 'cube_retention_months' ],
    ],

    /*
     * The tracking tag, in the `tracking_tag` group so an active
     * module adds its own fieldset beside it. Each fieldset serves every
     * level: each screen shows the settings that level may hold.
     *
     * One per job, each with a description, so the Tracking Tag screen can
     * show them as collapsed groups a person opens only for the one they want.
     */
    [
        'id'          => 'base.trackingPageViews',
        'group'       => 'tracking_tag',
        'order'       => 0,
        'legend'      => \OWA\Core\CoreAPI::t( 'Page views' ),
        'description' => \OWA\Core\CoreAPI::t( 'When the tracker sends a page view, and what identifies the page.' ),
        'settings'    => [ 'tracker_page_views', 'tracker_route_changes', 'tracker_url_fragments' ],
    ],
    [
        'id'          => 'base.trackingClicks',
        'group'       => 'tracking_tag',
        'order'       => 1,
        'legend'      => \OWA\Core\CoreAPI::t( 'Clicks and downloads' ),
        'description' => \OWA\Core\CoreAPI::t( 'Clicks on links and other elements, and which links count as downloads.' ),
        'settings'    => [ 'tracker_clicks', 'tracker_download_extensions' ],
    ],
    [
        'id'          => 'base.trackingForms',
        'group'       => 'tracking_tag',
        'order'       => 2,
        'legend'      => \OWA\Core\CoreAPI::t( 'Forms' ),
        'description' => \OWA\Core\CoreAPI::t( 'A visitor starting and submitting a form.' ),
        'settings'    => [ 'tracker_forms' ],
    ],
    [
        'id'          => 'base.trackingScroll',
        'group'       => 'tracking_tag',
        'order'       => 3,
        'legend'      => \OWA\Core\CoreAPI::t( 'Scroll depth' ),
        'description' => \OWA\Core\CoreAPI::t( 'How far down each page visitors get.' ),
        'settings'    => [ 'tracker_scroll', 'tracker_scroll_thresholds' ],
    ],
    [
        'id'          => 'base.trackingSearch',
        'group'       => 'tracking_tag',
        'order'       => 4,
        'legend'      => \OWA\Core\CoreAPI::t( 'Site search' ),
        'description' => \OWA\Core\CoreAPI::t( 'Searches made with your site’s own search box, read from the results page’s URL.' ),
        'settings'    => [ 'tracker_site_search', 'tracker_site_search_params' ],
    ],
    [
        'id'          => 'base.trackingErrors',
        'group'       => 'tracking_tag',
        'order'       => 5,
        'legend'      => \OWA\Core\CoreAPI::t( 'JavaScript errors' ),
        'description' => \OWA\Core\CoreAPI::t( 'Uncaught errors in your pages’ scripts.' ),
        'settings'    => [ 'tracker_exceptions' ],
    ],
    [
        'id'          => 'base.trackingVisit',
        'group'       => 'tracking_tag',
        'order'       => 6,
        'legend'      => \OWA\Core\CoreAPI::t( 'Visits and cookies' ),
        'description' => \OWA\Core\CoreAPI::t( 'How long the tracker’s cookies hold a visit and a visitor together, and the domain they are set on.' ),
        'settings'    => [ 'tracker_session_cookie_days', 'tracker_visitor_cookie_days', 'tracker_cookie_domain' ],
    ],
    [
        'id'          => 'base.trackingPrivacy',
        'group'       => 'tracking_tag',
        'order'       => 7,
        'legend'      => \OWA\Core\CoreAPI::t( 'Privacy signals' ),
        'description' => \OWA\Core\CoreAPI::t( 'Browsers that ask not to be tracked, with Do Not Track or Global Privacy Control.' ),
        'settings'    => [ 'tracker_honor_privacy_signals' ],
    ],
];
