<?php
namespace OWA\Module\Base\Classes;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Whether the scheduler's cron entry is actually in place.
 *
 * The scheduler is one crontab line, and the single thing most likely to go
 * wrong with it is that nobody added it. Nothing then runs -- no partition
 * rotation, no queue draining -- and because a scheduler that is not running
 * produces no output, the failure is completely silent. This exists so the admin
 * interface can say so instead.
 *
 * WHAT WE CAN INFER, AND WHAT WE CANNOT.
 *
 * The dispatcher is the only thing that writes to owa_scheduled_job, and it
 * materialises a row for every registered job on its first tick. So the mere
 * EXISTENCE of a row proves it has run at least once, and an empty table proves
 * it has not. That check is exact.
 *
 * Detecting that a working cron entry later STOPPED is a weaker business:
 * silence is evidence only once the most frequent job should have run
 * several times. So the window is derived from the registered schedules --
 * three of the most frequent job's intervals, never under two hours and never
 * over forty days (silentFor()). With rebuild-cube every five minutes that is
 * two hours, which matters because every report goes stale with the
 * scheduler; an installation whose only job is monthly keeps the forty days,
 * because anything shorter would cry wolf and teach people to ignore the
 * banner.
 */
class SchedulerHealth {

    /**
     * The longest the dispatcher may be silent before it is called stopped,
     * whatever is scheduled: the window for an installation whose most frequent
     * job is monthly, which is a month plus a wide margin. See silentFor().
     */
    const SILENT_FOR = 3456000;   // 40 days

    /**
     * Silence shorter than this is never called a stop, whatever is scheduled:
     * two hours rides out a deploy or a brief outage without crying wolf, and
     * it is the only case answered without loading the job registry.
     */
    const MIN_SILENT_FOR = 7200;

    /** A stop is this many of the most frequent job's intervals without a run. */
    const MISSED_INTERVALS = 3;

    /** @var array|null|false  false = not yet computed */
    protected static $memo = false;

    /**
     * The problem with the scheduler, or null when there is nothing to say.
     *
     * Memoised: this is consulted on every admin page render, and the answer
     * cannot change within a single request.
     *
     * @return array|null  ['headline' => string, 'message' => string]
     */
    public static function problem() {

        if ( self::$memo !== false ) {

            return self::$memo;
        }

        self::$memo = self::check();

        return self::$memo;
    }

    /**
     * @return array|null
     */
    protected static function check() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.scheduled_job' );

        $row = $db->get_row( sprintf(
            'SELECT COUNT(*) AS jobs, MAX(last_run_at) AS last_run FROM %s',
            $entity->getTableName()
        ) );

        // A falsy result means the table is not there yet -- an installation
        // that has not applied the update. Db::query() swallows the error and
        // returns falsy rather than raising. Saying nothing is right: the
        // updates-required path already has that install's attention, and a
        // second banner about a table it does not have would only confuse.
        if ( ! $row ) {

            return null;
        }

        if ( ! (int) $row['jobs'] ) {

            return array(
                'headline' => "OWA's Job Scheduler is not running.",
                // The line itself is NOT appended here. The caller decides how to
                // present it -- the banner gives it its own <code> block, and
                // running them together produced it twice on the page.
                'message'  => 'OWA needs one cron entry to run its scheduled jobs. Add this '
                            . 'line to the crontab of the user that owns your OWA files:',
            );
        }

        $last = (int) $row['last_run'];

        /*
         * SILENCE IS JUDGED AGAINST THE MOST FREQUENT JOB, not a fixed month.
         * With rebuild-cube every five minutes, a dispatcher quiet for a day
         * has left reports a day stale; waiting forty days to say so -- the
         * old rule, written when the most frequent job was monthly -- let that
         * go unnoticed. Anything under two hours is answered with no registry
         * load, which is every page render on a healthy installation.
         */
        if ( $last && ( time() - $last ) > self::MIN_SILENT_FOR
                && ( time() - $last ) > self::silentFor( self::registeredJobs() ) ) {

            return array(
                'headline' => "OWA's Job Scheduler may have stopped.",
                'message'  => sprintf(
                    'The scheduler last ran %s. It has run before, so the cron entry was '
                  . 'working at some point -- check that it is still there and that the user it '
                  . 'runs as can still execute cli.php. Run "cli.php cmd=schedule-status" for a '
                  . 'full diagnosis.',
                    \OWA\Module\Base\Classes\JobStatus::readable( $last )
                ),
            );
        }

        return null;
    }

    /**
     * How long the dispatcher may be silent before that is a stop: the most
     * frequent job's interval times MISSED_INTERVALS, between MIN_SILENT_FOR
     * and SILENT_FOR. Pure, so each schedule mix can be asserted.
     *
     * A job's interval is the gap between its next two occurrences, which is
     * exact for the evenly spread schedules OWA registers.
     *
     * @param array $jobs name => job, as JobStatus::jobs() returns them
     * @param int|null $now
     * @return int seconds
     */
    public static function silentFor( array $jobs, $now = null ) {

        $now      = $now ?: time();
        $timezone = \OWA\Module\Base\Classes\JobStatus::timezone();
        $shortest = null;

        foreach ( $jobs as $job ) {

            if ( \OWA\Module\Base\Classes\JobStatus::isDisabled( $job ) ) {

                continue;
            }

            $parsed = \OWA\Module\Base\Classes\JobStatus::parsedSchedule( $job );
            $first  = $parsed ? \OWA\Core\Cron::nextAfter( $parsed, $now, $timezone ) : null;
            $second = $first ? \OWA\Core\Cron::nextAfter( $parsed, $first, $timezone ) : null;

            if ( $first && $second && $second > $first ) {

                $shortest = $shortest === null ? $second - $first : min( $shortest, $second - $first );
            }
        }

        if ( $shortest === null ) {

            return self::SILENT_FOR;
        }

        return (int) min( self::SILENT_FOR, max( self::MIN_SILENT_FOR, self::MISSED_INTERVALS * $shortest ) );
    }

    /**
     * The registered jobs. Loaded only once the dispatcher has been quiet for
     * MIN_SILENT_FOR -- Service::loadJobs() is otherwise left to the scheduler
     * commands.
     *
     * @return array
     */
    protected static function registeredJobs() {

        return \OWA\Module\Base\Classes\JobStatus::jobs();
    }

    /**
     * The crontab line for THIS installation, so it can be copied rather than
     * assembled by hand from a documentation example.
     *
     * @return string
     */
    public static function cronLine() {

        return '* * * * * cd ' . rtrim( OWA_DIR, '/' ) . ' && php cli.php cmd=schedule-run';
    }

    /**
     * Test seam: forget the memoised answer.
     *
     * @return void
     */
    public static function forget() {

        self::$memo = false;
    }
}
