<?php
/**
 * The keys an OWA_SCHEDULED_JOBS entry may give, from
 * Service::SCHEDULED_JOB_KEYS, the list applyConfiguredJobs() copies.
 */

require_once __DIR__ . '/../lib/modules.php'; // owa_wiki_cell()

return static function () {

    $out = "| Key | Meaning |\n|---|---|\n";

    foreach ( \OWA\Module\Base\Classes\Service::SCHEDULED_JOB_KEYS as $key => $meaning ) {
        $out .= sprintf( "| `%s` | %s |\n", $key, owa_wiki_cell( $meaning ) );
    }

    return $out;
};
