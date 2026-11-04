<?php
/**
 * The commands OWA_SCHEDULED_JOBS may never schedule, from
 * Service::NEVER_SCHEDULE.
 */

require_once __DIR__ . '/../lib/modules.php'; // owa_wiki_cell()

return static function () {

    $out = "| Command | Why it is refused |\n|---|---|\n";

    foreach ( \OWA\Module\Base\Classes\Service::NEVER_SCHEDULE as $command => $why ) {
        $out .= sprintf( "| `%s` | %s |\n", $command, owa_wiki_cell( $why ) );
    }

    return $out;
};
