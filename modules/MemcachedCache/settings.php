<?php

/**
 * What this module stores, and what boot needs of it.
 *
 * Read from disk before any module object exists -- see
 * Core\Module::settingsRegistry() for why that is forced rather than chosen.
 *
 * Both are read by the cache object's constructor, which is built early on any
 * request once memcached is the cache type, so boot fetches them.
 *
 * NO DEFAULTS, deliberately. Base's default array carries 'memcachedServers'
 * and 'memcachedPersistantConnections' under module 'base', but every read is
 * under 'memcachedCache' -- so neither has ever resolved to anything and the
 * module has always behaved as though both were absent: no servers, and a
 * non-persistent connection. Declaring Base's values here would not be
 * adopting a default, it would be changing what installs do. The stale pair
 * under 'base' is read by nothing and wants removing on its own.
 *
 * is_active and schema_version are NOT here. Core\Module::settingsRegistry()
 * adds those to every module, from mechanicalSettings().
 */
return array(

    'module' => 'memcachedCache',

    'settings' => array(

        // 'host:port' strings. No screen offers them; they are set from the
        // config file or stored directly.
        'memcachedServers' => array(
            'storable' => true,
            'autoload' => true,
        ),

        'memcachedPersistantConnections' => array(
            'storable' => true,
            'autoload' => true,
        ),
    ),
);
