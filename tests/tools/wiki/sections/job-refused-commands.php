<?php
/**
 * The commands OWA_SCHEDULED_JOBS may never schedule, from
 * Service::NEVER_SCHEDULE.
 */

return static function () {

    $out = "| Command |\n|---|\n";

    foreach ( array_keys( \OWA\Module\Base\Classes\Service::NEVER_SCHEDULE ) as $command ) {
        $out .= sprintf( "| `%s` |\n", $command );
    }

    return $out;
};
