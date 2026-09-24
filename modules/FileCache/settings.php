<?php

/**
 * What this module stores, and what boot needs of it.
 *
 * Read from disk before any module object exists -- see
 * Core\Module::settingsRegistry() for why that is forced rather than chosen.
 *
 * It reads no settings at all. Where the cache files go is OWA_CACHE_DIR, or a
 * cache_dir passed in at construction -- see Classes/FileCache.php. Neither is
 * a stored setting.
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

    'module' => 'fileCache',

    'settings' => array(),
);
