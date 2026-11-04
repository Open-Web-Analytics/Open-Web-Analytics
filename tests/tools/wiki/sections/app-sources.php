<?php
/**
 * The Android apps recorded as a site's source rather than by their package
 * name, from Cube\SourceStep::APP_SOURCES.
 */

use OWA\Module\Base\Classes\Cube\SourceStep;

return function () {

    $out = "| Android package | Source |\n|---|---|\n";

    foreach ( SourceStep::APP_SOURCES as $package => $source ) {
        $out .= sprintf( "| `%s` | `%s` |\n", $package, $source );
    }

    return $out;
};
