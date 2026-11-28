<?php

/**
 * What this module stores, and what boot needs of it.
 *
 * Read from disk before any module object exists -- see
 * Core\Module::settingsRegistry() for why that is forced rather than chosen.
 *
 * The module names itself because core cannot: Lib::moduleDirName() maps both
 * 'maxmind_geoip' and 'maxmindGeoip' onto this directory, and the stored rows
 * use the first.
 *
 * is_active and schema_version are NOT here. Core\Module::settingsRegistry()
 * adds those to every module, from mechanicalSettings().
 */
return [

    'module' => 'maxmind_geoip',

    'settings' => [

        /*
         * Read on the lookup path -- Classes/Maxmind.php consults it for every
         * location resolution -- so boot fetches it.
         */
        'db_edition' => [
            'config_constant' => 'OWA_MAXMIND_EDITION',
            'default'     => 'GeoLite2-City',
            'storable'    => true,
            'autoload'    => true,
            'type'        => 'select',
            'label'       => \OWA\Core\CoreAPI::t( 'Database Edition' ),
            'description' =>
                \OWA\Core\CoreAPI::t( 'City resolves city, region and country. Country resolves only the '
              . 'country and is a fraction of the size. Changing this changes both the '
              . 'file downloaded and the file read.' ),
            'options'     => [ 'GeoLite2-City', 'GeoLite2-Country' ],
        ],

        /*
         * Which lookup path the module takes. Read in the module's own
         * constructor, so boot needs it.
         *
         * No UI offers it: OWA_MAXMIND_LOOKUP_METHOD sets it. It stays storable,
         * so a value an install already stores is still read.
         */
        'lookup_method' => [
            'config_constant' => 'OWA_MAXMIND_LOOKUP_METHOD',
            'default'     => 'city_lite_db',
            'storable'    => true,
            'autoload'    => true,
            'description' => 'How locations are resolved: city_lite_db reads the local GeoLite2 database, '
                . 'geoip_city_isp_org_web_service queries the MaxMind web service.',
        ],

        /*
         * Credentials for the web-service lookup path, read only when
         * lookup_method selects it. No default -- a blank key is not a
         * credential -- and no chrome, because no screen offers them:
         * OWA_MAXMIND_WS_LICENSE_KEY and OWA_MAXMIND_WS_USER_NAME set them.
         */
        'ws_license_key' => [
            'config_constant' => 'OWA_MAXMIND_WS_LICENSE_KEY',
            'storable'    => true,
            'description' => 'The licence key for the MaxMind web service lookup. Also used to download the '
                . 'database when db_license_key is empty.',
        ],
        'ws_user_name'   => [
            'config_constant' => 'OWA_MAXMIND_WS_USER_NAME',
            'storable'    => true,
            'description' => 'The MaxMind account id for the web service lookup.',
        ],

        /*
         * No autoload: read by the options screen and by the db update job,
         * never on a request that tracks or reports. One query, the first time
         * something asks.
         */
        'db_license_key' => [
            'config_constant' => 'OWA_MAXMIND_LICENSE_KEY',
            'default'     => '',
            'storable'    => true,
            'type'        => 'text',
            'label'       => \OWA\Core\CoreAPI::t( 'Licence Key' ),
            'description' =>
                \OWA\Core\CoreAPI::t( 'The MaxMind licence key a GeoLite2 database is downloaded with.' ),
        ],

        /*
         * The last web service failure, for System Health: written by the
         * lookup at most once an hour (Maxmind::FAILURE_NOTICE_INTERVAL), and
         * read on the lookup path to keep it to that, so boot fetches it.
         */
        'ws_last_failure' => [
            'default'     => [],
            'storable'    => true,
            'autoload'    => true,
            'internal'    => true,
            'description' => 'The last MaxMind web service lookup failure: when (at, unix time) and why (message).',
        ],
    ],
];
