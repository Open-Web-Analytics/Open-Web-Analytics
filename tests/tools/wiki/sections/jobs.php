<?php
/**
 * The scheduled-jobs table: every job the shipped modules register.
 * See lib/jobs.php for how spread schedules are described.
 */

require_once __DIR__ . '/../lib/jobs.php';

return function () {

    $jobs = owa_wiki_jobs();

    $out = implode( "\n", \OWA\Module\Base\Controller\ScheduleStatusCli::markdownTable( $jobs, 'owa_wiki_job_schedule' ) ) . "\n";

    $others = array_filter( $jobs, fn ( $job ) => $job['module'] !== 'base' );

    foreach ( $others as $name => $job ) {
        $out .= "\n`$name` runs while the `{$job['module']}` module is active.";
    }

    return $out;
};
