<?php
/**
 * The scheduler's and the job queue's fixed limits, each read from the
 * constant the code uses:
 *
 *   ScheduleRunCli::MAX_RUNTIME           a tick's budget for starting jobs
 *   Core\Controller\Cli::DEFAULT_JOB_LEASE  the lock lease of a command that sets none
 *   JobStatus::MIN_GRACE                  how late is overdue
 *   SchedulerHealth::MISSED_INTERVALS, MIN_SILENT_FOR, SILENT_FOR   the banner
 *   RetryPolicy::RECURRING, ONE_OFF       the retry policies a job gets by default
 *   JobQueue::DEFAULT_LEASE, KEEP_DONE, KEEP_FAILED                 one-off jobs
 */

use OWA\Module\Base\Classes\JobQueue;
use OWA\Module\Base\Classes\RetryPolicy;
use OWA\Module\Base\Classes\JobStatus;
use OWA\Module\Base\Classes\SchedulerHealth;

return static function () {

    $time = static function ( $seconds ) {

        $seconds = (int) $seconds;

        foreach ( [ 86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second' ] as $unit => $name ) {

            if ( $seconds >= $unit && $seconds % $unit === 0 ) {
                $n = intdiv( $seconds, $unit );
                return $n . ' ' . $name . ( $n === 1 ? '' : 's' );
            }
        }

        return $seconds . ' seconds';
    };

    $policy = RetryPolicy::ONE_OFF;

    // The delay before each retry: RetryPolicy::waitBefore(), the last step repeating.
    $delays = [];

    for ( $attempt = 2; $attempt <= (int) $policy['max_attempts']; $attempt++ ) {
        $delays[] = $time( RetryPolicy::waitBefore( $policy, $attempt ) );
    }

    $rows = [
        'Time one `schedule-run` tick may spend starting jobs'
            => $time( \OWA\Module\Base\Controller\ScheduleRunCli::MAX_RUNTIME ),
        'A job\'s lock lease when its command sets none'
            => $time( \OWA\Core\Controller\Cli::DEFAULT_JOB_LEASE ),
        'How late a job can be before it is called overdue'
            => sprintf( 'one occurrence or %s, whichever is longer', $time( JobStatus::MIN_GRACE ) ),
        'Silence before the admin banner says the scheduler may have stopped'
            => sprintf( '%d intervals of the most frequent registered job, at least %s and at most %s',
                SchedulerHealth::MISSED_INTERVALS, $time( SchedulerHealth::MIN_SILENT_FOR ),
                $time( SchedulerHealth::SILENT_FOR ) ),
        'A recurring job\'s retries, unless it or its command has a policy'
            => RetryPolicy::describe( RetryPolicy::RECURRING, RetryPolicy::KIND_RECURRING ),
        'Attempts a one-off job gets, unless it or its command has a policy'
            => (string) (int) $policy['max_attempts'],
        'Wait before a failed one-off job is retried'
            => $delays ? implode( ', then ', $delays ) : 'not retried',
        'A one-off job\'s lease, unless its command declares a JOB_LEASE'
            => $time( JobQueue::DEFAULT_LEASE ),
        'How long a done one-off job is kept'
            => $time( JobQueue::KEEP_DONE ),
        'How long a failed one-off job is kept'
            => $time( JobQueue::KEEP_FAILED ),
    ];

    $out = "| Limit | Value |\n|---|---|\n";

    foreach ( $rows as $limit => $value ) {
        $out .= "| $limit | $value |\n";
    }

    return $out;
};
