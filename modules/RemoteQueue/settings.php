<?php

/**
 * What this module stores, and what boot needs of it.
 *
 * Read from disk before any module object exists -- see
 * Core\Module::settingsRegistry() for why that is forced rather than chosen.
 *
 * is_active and schema_version are NOT here. Core\Module::settingsRegistry()
 * adds those to every module, from mechanicalSettings().
 */
return array(

    'module' => 'remoteQueue',

    'settings' => array(

        /*
         * Read in the module's CONSTRUCTOR, which decides whether to register
         * the http queue at all -- so it has to be in hand before any module
         * object is built. A lazy fetch here would be a query on every request,
         * and one that arrives too late to register anything.
         *
         * No default: there is no sensible address to post events to, and an
         * empty one is how the module says it is not configured.
         */
        'endpoint' => array(
            'storable' => true,
            'autoload' => true,
        ),
    ),
);
