<?php
/**
 * The checks the install wizard, the System Health screen and
 * cmd=instance-info run: EnvironmentCheck::all(), each with the 'requires'
 * sentence it declares. Asked of this checkout, but only the names and those
 * sentences are printed, and neither depends on what the server has.
 */

require_once __DIR__ . '/../lib/modules.php'; // owa_wiki_cell()

use OWA\Module\Base\Classes\EnvironmentCheck;

return static function () {

    $out = "| Check | Passes when |\n|---|---|\n";

    foreach ( EnvironmentCheck::all( \OWA\Core\Env::root() . '/owa-config.php' ) as $check ) {

        if ( trim( (string) ( $check['requires'] ?? '' ) ) === '' ) {
            throw new RuntimeException( "EnvironmentCheck '{$check['name']}' does not say what passes." );
        }

        $out .= sprintf( "| **%s** | %s |\n", owa_wiki_cell( $check['name'] ), owa_wiki_cell( $check['requires'] ) );
    }

    return $out;
};
