<?php
/**
 * The registered jobs, with each schedule described the same on every install.
 *
 * Spread schedules (Cron::dailySpreadFor(), minutelySpreadFor()) are seeded
 * from the install, so the expression a job registers is this checkout's and
 * would put this checkout's minute on the wiki. Which jobs are spread is found
 * by asking: each module re-registers its jobs under two different public_url
 * values, and a schedule that differs between them is derived from the install.
 * Nothing is inferred from the shape of the expression, which a fixed
 * `30 2 * * *` shares with a spread one.
 *
 * Registrations only: OWA_SCHEDULED_JOBS is an install's own and not applied.
 */

require_once __DIR__ . '/modules.php';

/**
 * @return array<string,array> keyed by job name, each with 'module' and 'spread' (bool)
 */
function owa_wiki_jobs() {

    $jobs = array();

    foreach ( owa_wiki_all_modules() as $module ) {

        $registered = (array) $module->scheduled_jobs;
        $seeded     = array();

        $url = \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' );

        foreach ( array( 'https://a.example.org/', 'https://b.example.org/' ) as $i => $seed ) {

            \OWA\Core\CoreAPI::setSetting( 'base', 'public_url', $seed );
            $module->scheduled_jobs = array();
            $module->registerJobs();
            $seeded[ $i ] = (array) $module->scheduled_jobs;
        }

        \OWA\Core\CoreAPI::setSetting( 'base', 'public_url', $url );
        $module->scheduled_jobs = $registered;

        foreach ( $registered as $name => $job ) {

            $job['spread'] = ( $seeded[0][ $name ]['schedule'] ?? null ) !== ( $seeded[1][ $name ]['schedule'] ?? null );
            $jobs[ $name ] = $job;
        }
    }

    ksort( $jobs );

    return $jobs;
}

/**
 * A schedule in words that holds on every install.
 *
 * A spread job says how often and that the time is the install's; anything
 * else is Cron::describe(), which is already install-independent for a fixed
 * expression.
 */
function owa_wiki_job_schedule( array $job ) {

    if ( \OWA\Module\Base\Classes\JobStatus::isDisabled( $job ) ) {
        return 'off';
    }

    if ( empty( $job['spread'] ) ) {
        return \OWA\Core\Cron::describe( $job['schedule'] );
    }

    $expr = trim( (string) $job['schedule'] );

    if ( preg_match( '#^\d+ \d+ \* \* \*$#', $expr ) ) {
        return 'daily, at a time each install derives for itself';
    }

    if ( preg_match( '#^(\d+),(\d+)(?:,\d+)* \* \* \* \*$#', $expr, $m ) ) {
        return sprintf( 'every %d minutes, at an offset each install derives for itself', $m[2] - $m[1] );
    }

    if ( preg_match( '#^\d+ \* \* \* \*$#', $expr ) ) {
        return 'hourly, at a minute each install derives for itself';
    }

    return 'at a time each install derives for itself';
}
