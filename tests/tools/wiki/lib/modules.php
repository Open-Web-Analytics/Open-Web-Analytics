<?php

require_once __DIR__ . '/../generate.php'; // OWA_WIKI_EXCLUDED_MODULES
/**
 * Every module that ships, active or not, for the sections that document what
 * modules register.
 *
 * A module registers its commands and jobs in its constructor, and only active
 * modules are constructed at boot -- a configless boot activates Base alone --
 * so the rest are constructed here to be asked. Constructing one also registers
 * its actions in the service map, which is what resolves a command's class.
 *
 * @return array<string,\OWA\Core\Module> keyed by module name
 */
function owa_wiki_all_modules() {

    static $modules;

    if ( $modules !== null ) {
        return $modules;
    }

    $s       = \OWA\Core\CoreAPI::serviceSingleton();
    $modules = array();
    $active  = array();

    foreach ( $s->modules as $name => $module ) {

        $modules[ $name ] = $module;
        $active[]         = \OWA\Core\Lib::moduleDirName( $name );
    }

    foreach ( glob( OWA_DIR . 'modules/*/Module.php' ) as $file ) {

        $dir = basename( dirname( $file ) );

        if ( in_array( $dir, $active, true ) ) {
            continue;
        }

        $module = \OWA\Core\CoreAPI::moduleClassFactory( $dir );

        $modules[ $module->name ] = $module;
    }

    $modules = array_diff_key( $modules, array_flip( OWA_WIKI_EXCLUDED_MODULES ) );

    ksort( $modules );

    return $modules;
}

/** A markdown table cell: no pipes, no line breaks. */
function owa_wiki_cell( $v ) {

    return str_replace( array( '|', "\n" ), array( '\\|', ' ' ), (string) $v );
}
