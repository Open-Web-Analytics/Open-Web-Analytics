<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * What the scheduler is doing, and why a job is not running.
 *
 *   php cli.php cmd=schedule-status
 *
 * Read-only. NEVER WRITES A ROW -- if it materialised state it would manufacture
 * the very evidence it is being read for: run it once before cron has ever
 * fired, and it would report that cron had fired.
 *
 * Saying only "this job is behind" makes the reader guess. This walks an ordered
 * list of causes and names the first that holds, which works because every way a
 * LIVE dispatcher can leave a job behind is directly observable -- so eliminating
 * them makes "the scheduler is not running" a conclusion rather than a shrug.
 *
 * Nothing here alerts; a human has to run it. Real alerting is the ping-hook and
 * mail-on-failure route, deliberately out of scope for now.
 */
class ScheduleStatusCli extends SchedulerCli {

    /**
     * How late an occurrence must be before it counts as behind rather than
     * merely waiting for the next tick. One occurrence, or a quarter of an hour,
     * whichever is longer -- so a daily job has to miss a whole day, and an
     * every-minute job is flagged inside fifteen minutes.
     */
    const MIN_GRACE = 900;

    function action() {

        $now     = time();
        $jobs    = $this->jobs();
        $state   = $this->allState();
        $enabled = (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'scheduler_enabled' );
        $pending = \OWA\Core\CoreAPI::isUpdateRequired();

        $lines = array( sprintf(
            'Scheduler status, %s (%s). %d job(s) registered.',
            $this->readable( $now ), $this->timezone(), count( $jobs )
        ) );

        // One source for this string, shared with the admin banner and the
        // install page -- an installation told two different cron lines depending
        // on which screen it read has to guess which one is right.
        $lines[] = 'Expected cron entry:  '
                 . \OWA\Module\Base\Classes\SchedulerHealth::cronLine();

        if ( ! $enabled ) {

            $lines[] = '';
            $lines[] = 'The scheduler is DISABLED by OWA_SCHEDULER_ENABLED. No job will run.';
        }

        if ( $pending ) {

            $lines[] = '';
            $lines[] = 'ACTION: schema updates are pending, so the dispatcher refuses every job. '
                     . 'Apply them with cmd=update. Nothing below will run until you do.';
        }

        // Any state row is proof the dispatcher has run at least once, because
        // it is the only thing that writes them. That is what separates "cron
        // was never installed" from "healthy, nothing due yet".
        $ever = (bool) $state;
        $last_activity = 0;

        foreach ( $state as $row ) {

            $last_activity = max( $last_activity, (int) ( $row['last_run_at'] ?? 0 ) );
        }

        foreach ( $jobs as $name => $job ) {

            $lines[] = '';
            $lines   = array_merge( $lines, $this->describeJob(
                $name, $job, $state[ $name ] ?? null, $now, $enabled, $pending, $ever, $last_activity
            ) );
        }

        $lines = array_merge( $lines, $this->describeOrphans( $jobs, $state ) );
        $lines = array_merge( $lines, $this->summarise( $jobs, $state, $ever, $last_activity, $now ) );

        $this->write( $lines );
    }

    /**
     * One job's block.
     *
     * @return string[]
     */
    protected function describeJob( $name, $job, $row, $now, $enabled, $pending, $ever, $last_activity ) {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $lock   = $db->getJobLock( $name );
        $parsed = $this->parsedSchedule( $job );

        $lines = array( sprintf(
            '%s    %s (%s)',
            $name,
            $this->isDisabled( $job ) ? 'off' : \OWA\Core\Cron::describe( $job['schedule'] ),
            $job['source']
        ) );

        $lines[] = sprintf( '  runs         %s%s',
            $job['command'],
            $job['params'] ? ' ' . $this->encodeParams( $job['params'] ) : ' (no arguments)'
        );

        if ( $parsed ) {

            $next = \OWA\Core\Cron::nextAfter( $parsed, $now, $this->timezone() );

            $lines[] = sprintf( '  next due     %s', $this->readable( $next, 'unknown' ) );
        }

        if ( ! $row ) {

            $lines[] = '  last run     never';

        } else {

            $lines[] = sprintf( '  last run     %s, %s%s',
                $this->readable( $row['last_run_at'] ),
                $row['last_status'] ?: 'unknown',
                ( $row['last_duration'] ?? 0 ) ? sprintf( ' (%ds)', (int) $row['last_duration'] ) : ''
            );

            if ( ! empty( $row['last_message'] ) && $row['last_message'] !== '-' ) {

                $lines[] = sprintf( '               %s', $row['last_message'] );
            }

            $lines[] = sprintf( '  history      %d run(s), %d failed',
                (int) ( $row['run_count'] ?? 0 ), (int) ( $row['failure_count'] ?? 0 ) );

            // The arguments a run used are recorded, so a change is visible
            // BEFORE it takes effect rather than diagnosed afterwards.
            $current = $this->encodeParams( $job['params'] );

            if ( ! empty( $row['last_params'] ) && $row['last_params'] !== $current ) {

                $lines[] = sprintf(
                    '  NOTE         arguments have changed since the last run: was "%s", now "%s".',
                    $row['last_params'], $current
                );
            }
        }

        $reason = $this->diagnose( $name, $job, $row, $lock, $parsed, $now, $enabled, $pending, $ever, $last_activity );

        if ( $reason ) {

            $lines[] = '  ACTION       ' . $reason;
        }

        return $lines;
    }

    /**
     * Why is this job not running? The first cause that holds, in order.
     *
     * @return string|null  null when there is nothing to say
     */
    protected function diagnose( $name, $job, $row, $lock, $parsed, $now, $enabled, $pending, $ever, $last_activity ) {

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

        if ( $this->isDisabled( $job ) ) {

            return null;   // not behind; deliberately not running
        }

        if ( $parsed === null ) {

            return sprintf(
                'The schedule "%s" cannot be read, so this job will never run. It is deliberately '
              . 'not given a default.', $job['schedule']
            );
        }

        if ( $lock && (int) $lock['expires_at'] > $now ) {

            return sprintf( 'Running now, since %s.', $this->readable( $lock['acquired_at'] ) );
        }

        if ( $lock ) {

            return sprintf(
                'A lock from %s is still present and its lease expired at %s -- the run holding it '
              . 'died. It will be taken over on the next tick, or drop it now with '
              . 'cmd=schedule-run --force-release job=%s',
                $this->readable( $lock['acquired_at'] ), $this->readable( $lock['expires_at'] ), $name
            );
        }

        // Is it actually behind, or just waiting for the next tick?
        $slot = \OWA\Core\Cron::dueSlot(
            $parsed, $row ? (int) $row['last_run_slot'] : 0, $now, $this->timezone()
        );

        if ( $slot === null ) {

            return null;   // up to date
        }

        $next     = \OWA\Core\Cron::nextAfter( $parsed, $slot, $this->timezone() );
        $interval = $next ? $next - $slot : self::MIN_GRACE;
        $last     = $row ? (int) $row['last_run_slot'] : 0;

        // Has a WHOLE occurrence been missed, or has this one merely just come
        // due? Measuring "how long since the newest missed occurrence" would be
        // wrong: a daily job forty days behind still has a slot from this
        // morning, and would read as minutes late rather than weeks.
        $missed_earlier = $last > 0
            && \OWA\Core\Cron::dueSlot( $parsed, $last, $slot - 60, $this->timezone() ) !== null;

        if ( ! $missed_earlier && $now - $slot <= self::MIN_GRACE ) {

            return null;   // due, but within the tolerance of a normal tick
        }

        // Lateness runs from when it SHOULD have next run after its last
        // satisfied occurrence, which is what a person means by "overdue by".
        $late = $this->howLate( $last > 0 ? max( 60, $now - ( $last + $interval ) ) : $now - $slot );

        if ( $row && (int) $row['last_finished_at'] && (int) $row['last_finished_at'] < (int) $row['last_run_at'] ) {

            return sprintf(
                'Overdue by %s. The last run started %s and never finished -- a fatal error or the '
              . 'process being killed, which nothing inside PHP could have caught.',
                $late, $this->readable( $row['last_run_at'] )
            );
        }

        if ( $row && (int) $row['last_failure_at'] > (int) $row['last_success_at'] ) {

            return sprintf(
                'Overdue by %s. Failing since %s: %s. It is retried at every tick.',
                $late, $this->readable( $row['last_failure_at'] ), $row['last_message'] ?: 'no message recorded'
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
                $late, $this->readable( $last_activity )
            );
        }

        return sprintf(
            'Overdue by %s and nothing above explains it. The dispatcher does not appear to be '
          . 'running -- the last activity of any kind was %s. Check the cron entry:  %s',
            $late, $this->readable( $last_activity ),
            \OWA\Module\Base\Classes\SchedulerHealth::cronLine()
        );
    }

    /**
     * @param int $seconds
     * @return string
     */
    protected function howLate( $seconds ) {

        if ( $seconds >= 86400 ) {

            return sprintf( '%d day(s)', intdiv( $seconds, 86400 ) );
        }

        if ( $seconds >= 3600 ) {

            return sprintf( '%d hour(s)', intdiv( $seconds, 3600 ) );
        }

        return sprintf( '%d minute(s)', max( 1, intdiv( $seconds, 60 ) ) );
    }

    /**
     * State rows for jobs nothing registers any more.
     *
     * Kept rather than deleted, so a job that vanished through a config typo is
     * visible instead of silently gone.
     *
     * @return string[]
     */
    protected function describeOrphans( $jobs, $state ) {

        $orphans = array_diff_key( $state, $jobs );

        if ( ! $orphans ) {

            return array();
        }

        $lines = array( '', sprintf( 'Orphaned (%d) -- no longer registered, never run, kept for their history:', count( $orphans ) ) );

        foreach ( $orphans as $name => $row ) {

            $lines[] = sprintf( '  %-28s last run %s, %s',
                $name, $this->readable( $row['last_run_at'] ), $row['last_status'] ?: 'unknown' );
        }

        $lines[] = '  Remove them with cmd=schedule-run --prune-orphans (never automatic: a typo in';
        $lines[] = '  OWA_SCHEDULED_JOBS de-registers a real job, and auto-pruning would delete its history).';

        return $lines;
    }

    /**
     * @return string[]
     */
    protected function summarise( $jobs, $state, $ever, $last_activity, $now ) {

        $lines = array( '' );

        if ( ! $jobs ) {

            $lines[] = 'No jobs are registered.';

            return $lines;
        }

        if ( ! $ever ) {

            $lines[] = 'The dispatcher has never run. Until the cron entry above is in place, nothing here happens.';

            return $lines;
        }

        $lines[] = sprintf(
            '%d job(s) registered. Last activity of any kind: %s.',
            count( $jobs ), $this->readable( $last_activity )
        );

        // A hint about a job that could exist, rather than a report about one
        // that does: queue processing is not shipped registered, because whether
        // to drain at all depends on the installation.
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'queue_events' ) ) {

            $drains = false;

            foreach ( $jobs as $job ) {

                if ( stripos( $job['command'], 'queue' ) !== false ) {

                    $drains = true;
                }
            }

            if ( ! $drains ) {

                $lines[] = 'NOTE: event queueing is enabled but no job drains the queue. If nothing else '
                         . 'processes it, add one -- see OWA_SCHEDULED_JOBS in owa-config.php.';
            }
        }

        return $lines;
    }
}
