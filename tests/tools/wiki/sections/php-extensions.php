<?php
/**
 * The PHP extensions OWA cannot run without besides a database driver:
 * EnvironmentCheck::REQUIRED_EXTENSIONS, the list the install wizard checks.
 */

require_once __DIR__ . '/../lib/modules.php'; // owa_wiki_cell()
return static function () {

    $out = "| Extension | Used for |\n|---|---|\n";

    foreach ( \OWA\Module\Base\Classes\EnvironmentCheck::REQUIRED_EXTENSIONS as $extension => $what_for ) {
        $out .= sprintf( "| `%s` | %s |\n", $extension, owa_wiki_cell( $what_for ) );
    }

    return $out;
};
