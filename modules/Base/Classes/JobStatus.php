<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The scheduler's view of a job: its schedule, its state row, and whether it is
 * behind and why.
 *
 * Moved out of SchedulerCli and ScheduleStatusCli so a screen can ask it too.
 * cmd=schedule-status and the Reporting Cube screen say the same thing about
 * the same job because they run the same code; the CLI classes keep their
 * method names as delegates.
 */
class JobStatus {

    /**
     * How late an occurrence must be before it counts as behind rather than
     * merely waiting for the next tick. One occurrence, or a quarter of an hour,
     * whichever is longer -- so a daily job has to miss a whole day, and an
     * every-minute job is flagged inside fifteen minutes.
     */
    const MIN_GRACE = 900;

    /**
     * Everything the screen needs about one job, in one call.
     *
     * 'reason' is diagnose()'s answer -- null when the job is up to date --
     * except while it is running, which is reported as 'running' rather than as
     * a problem.
     *
     * @param string   $name
     * @param int|null $now
     * @return array ['job','row','next','running','reason']
     */
    public static function forJob( $name, $now = null ) {

        $now   = $now ?: time();
        $jobs  = self::jobs();
        $state = self::allState();
        $job   = $jobs[ $name ] ?? null;
        $row   = $state[ $name ] ?? null;

        $out = array( 'job' => $job, 'row' => $row, 'next' => null, 'running' => false, 'reason' => null );

        if ( ! $job ) {

            $out['reason'] = sprintf( 'No job named "%s" is registered.', $name );

            return $out;
        }

        $parsed = self::parsedSchedule( $job );

        if ( $parsed ) {

            $out['next'] = \OWA\Core\Cron::nextAfter( $parsed, $now, self::timezone() );
        }

        $lock = \OWA\Core\CoreAPI::dbSingleton()->getJobLock( $name );

        if ( $lock && (int) $lock['expires_at'] > $now ) {

            $out['running'] = true;

            return $out;
        }

        $last_activity = 0;

        foreach ( $state as $r ) {

            $last_activity = max( $last_activity, (int) ( $r['last_run_at'] ?? 0 ) );
        }

        $out['reason'] = self::diagnose( $name, $job, $row, $lock, $parsed, $now,
            (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'scheduler_enabled' ),
            \OWA\Core\CoreAPI::isSchemaUpdateRequired(), (bool) $state, $last_activity );

        return $out;
    }

    public static function jobs() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        $s->loadCliCommands();
        $s->loadJobs();

        return $s->getJobs();
    }

    public static function timezone() {

        $tz = \OWA\Core\CoreAPI::getSetting( 'base', 'timezone' );

        return $tz ? $tz : date_default_timezone_get();
    }

    public static function parsedSchedule( $job ) {

        $expr = isset( $job['schedule'] ) ? strtolower( trim( (string) $job['schedule'] ) ) : '';

        if ( $expr === '' || $expr === 'off' ) {

            return null;
        }

        return \OWA\Core\Cron::parse( $expr );
    }

    public static function isDisabled( $job ) {

        $expr = isset( $job['schedule'] ) ? strtolower( trim( (string) $job['schedule'] ) ) : '';

        return $expr === '' || $expr === 'off';
    }

    public static function allState() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.scheduled_job' );
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        $rows = $db->get_results( sprintf( 'SELECT * FROM %s', $entity->getTableName() ) );
        $out  = array();

        foreach ( (array) $rows as $row ) {

            $row = (array) $row;

            if ( isset( $row['job_name'] ) ) {

                $out[ $row['job_name'] ] = $row;
            }
        }

        return $out;
    }

    public static function readable( $ts, $absent = 'never' ) {

        if ( ! $ts ) {

            return $absent;
        }

        try {

            return ( new \DateTimeImmutable( '@' . (int) $ts ) )
                ->setTimezone( new \DateTimeZone( self::timezone() ) )
                ->format( 'Y-m-d H:i' );

        } catch ( \Exception $e ) {

            return (string) $ts;
        }
    }

    /**
     * Why is this job not running? The first cause that holds, in order.
     *
     * @return string|null  null when there is nothing to say
     */
    public static function diagnose( $name, $job, $row, $lock, $parsed, $now, $enabled, $pending, $ever, $last_activity ) {

        // Global causes outrank everything: nothing else can be true while they
        // are, and they are already stated at the top of the report.
        if ( $pending || ! $enabled ) {

            return null;
        }

        if ( ! \OWA\Core\CoreAPI::serviceSingleton()->getCliCommandClass( $job['command'] ) ) {

            // Reachable for a job registered in code naming a command that has
            // since been removed; config entries are refused before they get
            // this far.
            return sprintf(
                'Names command "%s", which is not registered, so it can never run.', $job['command']
            );
        }

        if ( self::isDisabled( $job ) ) {

            return null;   // not behind; deliberately not running
        }

        if ( $parsed === null ) {

            return sprintf(
                'The schedule "%s" cannot be read, so this job will never run. It is deliberately '
              . 'not given a default.', $job['schedule']
            );
        }

        if ( $lock && (int) $lock['expires_at'] > $now ) {

            return sprintf( 'Running now, since %s.', self::readable( $lock['acquired_at'] ) );
        }

        if ( $lock ) {

            return sprintf(
                'A lock from %s is still present and its lease expired at %s -- the run holding it '
              . 'died. It will be taken over on the next tick, or drop it now with '
              . 'cmd=schedule-run --force-release job=%s',
                self::readable( $lock['acquired_at'] ), self::readable( $lock['expires_at'] ), $name
            );
        }

        // Is it actually behind, or just waiting for the next tick?
        $slot = \OWA\Core\Cron::dueSlot(
            $parsed, $row ? (int) $row['last_run_slot'] : 0, $now, self::timezone()
        );

        if ( $slot === null ) {

            return null;   // up to date
        }

        $next     = \OWA\Core\Cron::nextAfter( $parsed, $slot, self::timezone() );
        $interval = $next ? $next - $slot : self::MIN_GRACE;
        $last     = $row ? (int) $row['last_run_slot'] : 0;

        // Has a WHOLE occurrence been missed, or has this one merely just come
        // due? Measuring "how long since the newest missed occurrence" would be
        // wrong: a daily job forty days behind still has a slot from this
        // morning, and would read as minutes late rather than weeks.
        $missed_earlier = $last > 0
            && \OWA\Core\Cron::dueSlot( $parsed, $last, $slot - 60, self::timezone() ) !== null;

        if ( ! $missed_earlier && $now - $slot <= self::MIN_GRACE ) {

            return null;   // due, but within the tolerance of a normal tick
        }

        // Lateness runs from when it SHOULD have next run after its last
        // satisfied occurrence, which is what a person means by "overdue by".
        $late = self::howLate( $last > 0 ? max( 60, $now - ( $last + $interval ) ) : $now - $slot );

        if ( $row && (int) $row['last_finished_at'] && (int) $row['last_finished_at'] < (int) $row['last_run_at'] ) {

            return sprintf(
                'Overdue by %s. The last run started %s and never finished -- a fatal error or the '
              . 'process being killed, which nothing inside PHP could have caught.',
                $late, self::readable( $row['last_run_at'] )
            );
        }

        if ( $row && (int) $row['last_failure_at'] > (int) $row['last_success_at'] ) {

            return sprintf(
                'Overdue by %s. Failing since %s: %s. It is retried at every tick.',
                $late, self::readable( $row['last_failure_at'] ), $row['last_message'] ?: 'no message recorded'
            );
        }

        if ( $row && $row['last_status'] === 'refused' ) {

            return sprintf(
                'Overdue by %s. The last run declined to act: %s',
                $late, $row['last_message'] ?: 'no reason recorded'
            );
        }

        // Everything a running dispatcher could be doing about this job has been
        // excluded, which is what makes the remaining conclusion sound rather
        // than a guess.
        if ( ! $ever ) {

            return sprintf(
                'Overdue by %s, and no job has EVER recorded a run -- the dispatcher has not run at '
              . 'all. That almost always means the cron entry is missing or wrong. Add:  %s',
                $late, \OWA\Module\Base\Classes\SchedulerHealth::cronLine()
            );
        }

        if ( $last_activity && $now - $last_activity < self::MIN_GRACE ) {

            return sprintf(
                'Overdue by %s, but another job ran at %s, so the dispatcher is alive. The problem '
              . 'is specific to this job.',
                $late, self::readable( $last_activity )
            );
        }

        return sprintf(
            'Overdue by %s and nothing above explains it. The dispatcher does not appear to be '
          . 'running -- the last activity of any kind was %s. Check the cron entry:  %s',
            $late, self::readable( $last_activity ),
            \OWA\Module\Base\Classes\SchedulerHealth::cronLine()
        );
    }

    /**
     * @param int $seconds
     * @return string
     */
    public static function howLate( $seconds ) {

        if ( $seconds >= 86400 ) {

            return sprintf( '%d day(s)', intdiv( $seconds, 86400 ) );
        }

        if ( $seconds >= 3600 ) {

            return sprintf( '%d hour(s)', intdiv( $seconds, 3600 ) );
        }

        return sprintf( '%d minute(s)', max( 1, intdiv( $seconds, 60 ) ) );
    }
}

?>
