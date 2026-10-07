<?php

/**
 * What this module stores, and what boot needs of it.
 *
 * Read from disk before any module object exists -- see
 * Core\Module::settingsRegistry() for why that is forced rather than chosen.
 *
 * Two tag settings (PLAN 2.24.4): whether a Profile records, and what share
 * of page loads. They reach the tracker only through a Profile's tracking
 * bundle, as trackDomStream and setDomstreamSampleRate.
 *
 * Declaring nothing is not the same as saying nothing. Without this file the
 * module is undeclared, and the boot query falls back to loading EVERY row it
 * owns -- a clause that exists so third-party modules keep working and that is
 * meant to be removed. An empty settings array states that there is nothing to
 * load.
 *
 * is_active and schema_version are NOT here. Core\Module::settingsRegistry()
 * adds those to every module, from mechanicalSettings().
 */
return array(

    'module' => 'domstream',

    'settings' => array(
        'record' => array(
            // Off, like every feature the tracker does not start by itself
            // (modules/Base/src/tracker/defaults.json): a Profile turns it on.
            'default'  => \OWA\Module\Base\Classes\TrackerDefaults::starts( 'trackDomStream' ),
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'boolean',
            'label'    => 'Record Page Interactions',
            'description' => 'Records pointer movement, clicks and key presses (never the keys) so a visit can be played back.',
        ),
        'sample_rate' => array(
            'default'  => 100,
            'storable' => true,
            'scopes'   => array( 'install', 'property', 'profile' ),
            'type'     => 'integer',
            'min'      => 0,
            'max'      => 100,
            'label'    => 'Recording Sample Rate (%)',
            'description' => 'The share of page loads recorded, from 0 to 100.',
        ),
    ),
);
