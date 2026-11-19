<?php

/**
 * Base's REST API routes: version => route => HTTP method => route.
 *
 * `class` is the controller, PSR-4 autoloadable; `conf` is the route's
 * configuration (params_order names the path segments after the route).
 * Base\Module::registerApiMethods() registers each one with
 * registerRestApiRoute().
 */
return [

    'v1' => [

        'sites' => [
            'GET'  => [ 'class' => 'OWA\\Module\\Base\\Controller\\SitesRest' ],
            'POST' => [ 'class' => 'OWA\\Module\\Base\\Controller\\AddSiteRest' ],
        ],

        'users' => [
            'GET'    => [ 'class' => 'OWA\\Module\\Base\\Controller\\UsersRest' ],
            'POST'   => [ 'class' => 'OWA\\Module\\Base\\Controller\\AddUserRest' ],
            'DELETE' => [ 'class' => 'OWA\\Module\\Base\\Controller\\DeleteUserRest', 'conf' => [ 'params_order' => [ 'user_id' ] ] ],
        ],

        'siteUsers' => [
            'POST' => [ 'class' => 'OWA\\Module\\Base\\Controller\\SiteAddAllowedUserRest' ],
        ],

        'notifications' => [
            'GET'    => [ 'class' => 'OWA\\Module\\Base\\Controller\\NotificationsRest' ],
            'POST'   => [ 'class' => 'OWA\\Module\\Base\\Controller\\NotificationMarkReadRest', 'conf' => [ 'params_order' => [ 'notificationId' ] ] ],
            'DELETE' => [ 'class' => 'OWA\\Module\\Base\\Controller\\NotificationDismissRest', 'conf' => [ 'params_order' => [ 'notificationId' ] ] ],
        ],

        'realtime' => [
            'GET' => [ 'class' => 'OWA\\Module\\Base\\Controller\\RealtimeRest' ],
        ],

        'reports' => [
            'GET' => [ 'class' => 'OWA\\Module\\Base\\Controller\\ReportsRest', 'conf' => [ 'params_order' => [ 'report_name' ] ] ],
        ],

        'retentionPreview' => [
            'GET' => [ 'class' => 'OWA\\Module\\Base\\Controller\\RetentionPreviewRest' ],
        ],
    ],
];
