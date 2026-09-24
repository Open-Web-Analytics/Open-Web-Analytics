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
return array(

    'module' => 'maxmind_geoip',

    'settings' => array(

        /*
         * Read on the lookup path -- Classes/Maxmind.php consults it for every
         * location resolution -- so boot fetches it.
         */
        'db_edition' => array(
            'default'     => 'GeoLite2-City',
            'storable'    => true,
            'autoload'    => true,
            'type'        => 'select',
            'label'       => 'Database Edition',
            'description' =>
                'City resolves city, region and country. Country resolves only the '
              . 'country and is a fraction of the size, which is the better trade if '
              . 'your reports never go below country level. Changing this changes '
              . 'which file is downloaded and which one is read, together.',
            'options'     => array( 'GeoLite2-City', 'GeoLite2-Country' ),
        ),

        /*
         * Which lookup path the module takes. Read in the module's own
         * constructor, so boot needs it.
         *
         * No UI offers it, which is the only reason its absence went unnoticed
         * for so long -- see the note on Maxmind::getLocationFromWebService().
         * It is still storable: an operator sets it from the config file or
         * directly, and a declaration that left it out would mean the stored
         * value was written and never read.
         */
        'lookup_method' => array(
            'default'     => 'city_lite_db',
            'storable'    => true,
            'autoload'    => true,
        ),

        /*
         * Credentials for the web-service lookup path, read only when
         * lookup_method selects it. No default -- a blank key is not a
         * credential -- and no chrome, because no screen offers them.
         */
        'ws_license_key' => array( 'storable' => true ),
        'ws_user_name'   => array( 'storable' => true ),

        /*
         * No autoload: read by the options screen and by the db update job,
         * never on a request that tracks or reports. One query, the first time
         * something asks.
         */
        'db_license_key' => array(
            'default'     => '',
            'storable'    => true,
            'type'        => 'text',
            'label'       => 'Licence Key',
            'description' =>
                'The GeoLite2 databases are free, but MaxMind stopped allowing '
              . 'anonymous downloads at the end of 2019, so fetching one needs a key. '
              . 'Creating a MaxMind account and a key costs nothing.',
        ),
    ),
);
