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
 * Detecting that a working cron entry later STOPPED is a weaker business. A
 * healthy installation whose only job is monthly writes nothing for weeks, so
 * silence is not evidence of failure until enough time has passed that even the
 * slowest schedule should have fired. Hence the deliberately generous window
 * below: a false alarm here would train people to ignore the banner, which costs
 * more than noticing a dead scheduler a few days late.
 */
class SchedulerHealth {

    /**
     * How long the dispatcher may be silent before we call it stopped.
     *
     * The longest schedule OWA registers is monthly, so anything short of a
     * month is a guaranteed false positive. Forty days is that plus a wide
     * margin.
     */
    const SILENT_FOR = 3456000;   // 40 days

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
                'headline' => 'Scheduled maintenance is not running',
                'message'  => 'OWA needs one cron entry to run its scheduled jobs -- without it '
                            . 'the fact tables stop being maintained and nothing says so. Add this '
                            . 'line to the crontab of the user that owns your OWA files: '
                            . self::cronLine(),
            );
        }

        $last = (int) $row['last_run'];

        if ( $last && ( time() - $last ) > self::SILENT_FOR ) {

            return array(
                'headline' => 'Scheduled maintenance may have stopped',
                'message'  => sprintf(
                    'The scheduler last ran on %s. It has run before, so the cron entry was '
                  . 'working at some point -- check that it is still there and that the user it '
                  . 'runs as can still execute cli.php. Run "cli.php cmd=schedule-status" for a '
                  . 'full diagnosis.',
                    date( 'j M Y', $last )
                ),
            );
        }

        return null;
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
