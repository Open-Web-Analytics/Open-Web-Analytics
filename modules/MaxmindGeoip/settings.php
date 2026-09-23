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
 * is_active and schema_version are NOT here. Core adds those to every module.
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
            'label'       => 'GeoIP Database Edition',
            'description' => 'Which MaxMind database this installation resolves locations against.',
            'options'     => array( 'GeoLite2-City', 'GeoLite2-Country' ),
        ),

        /*
         * No autoload: read by the options screen and by the db update job,
         * never on a request that tracks or reports. One query, the first time
         * something asks.
         */
        'db_license_key' => array(
            'default'     => '',
            'storable'    => true,
            'type'        => 'text',
            'label'       => 'MaxMind License Key',
            'description' => 'Required to download database updates from MaxMind.',
        ),
    ),
);
