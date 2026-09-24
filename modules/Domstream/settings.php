<?php

/**
 * What this module stores, and what boot needs of it.
 *
 * Read from disk before any module object exists -- see
 * Core\Module::settingsRegistry() for why that is forced rather than chosen.
 *
 * The only setting it reads is its own is_active. What gets recorded is
 * decided by the tracker and by Base's settings, not by anything stored under
 * 'domstream'.
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

    'settings' => array(),
);
