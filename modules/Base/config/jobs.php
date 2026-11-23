<?php

/**
 * Base's scheduled jobs, job name => registration.
 *
 * Run by cmd=schedule-run, which belongs in cron every minute:
 *
 *   * * * * * cd /path/to/owa && php cli.php cmd=schedule-run
 *
 * Base\Module::registerJobs() registers each entry with registerJob(); see
 * Core\Module::registerJobsFromConfig() for the keys. Each job below says why
 * it ships; ScheduleCliTest names the list exactly, so adding one is a
 * decision rather than a detail. An installation retunes or turns one off in
 * OWA_SCHEDULED_JOBS -- see Settings::applyConfigConstants().
 *
 * A `schedule` of [ 'spread' => ... ] is derived per install and per job from
 * Core\Module::jobSeed(): 'daily' is Cron::dailySpreadFor(), 'weekly'
 * Cron::weeklySpreadFor(), 'minutely' Cron::minutelySpreadFor() every
 * `interval` minutes.
 *
 * The NAME is deliberately not the command name. They are separate fields -- a
 * command can be scheduled more than once under different names -- and when
 * the only shipped job used the same string for both, nothing in the
 * documentation could show which one OWA_SCHEDULED_JOBS keys on. It keys on
 * the name.
 *
 * NOT registering ua-update-definitions here, deliberately.
 *
 * It would fit -- the patterns go stale on their own and monthly is roughly how
 * often uap-core changes -- but it makes an outbound HTTP request to a third
 * party, unattended, on a schedule. For self-hosted analytics that is a
 * decision the administrator makes, not one that arrives with an upgrade. Same
 * reasoning as the empty params of rotate-partitions: turning the scheduler on
 * must not turn anything else on.
 *
 * An installation that wants it adds it to OWA_SCHEDULED_JOBS, or runs
 * cli.php cmd=ua-update-definitions from its own cron.
 *
 * No job for Profiles' tracking bundles (PLAN 2.30.7). A save publishes its
 * own -- a Profile's at once, anything wider as a queued publish-trackers job
 * -- and a new release is applied with cmd=core-update-schema, which republishes what it
 * changed (Base\Module::update()).
 */
return [

    /*
     * Registered with EMPTY params. partition-rotate takes no retention
     * argument: it applies raw_retention_months and each Property's
     * cube_retention_months, both 0 (keep everything) by default, so an
     * installation deletes history only once an administrator sets one.
     * ScheduleCliTest pins the empty array.
     *
     * DAILY, NOT MONTHLY. Every piece of work this job does is triggered by a
     * period AGEING -- a lead running short, a month passing out of the detail
     * window, the cube's daily partitions leaving the rebuild window. Running
     * monthly does not do less of it, it just finds each one up to a month
     * late: the cube would hold an extra month of daily partitions, and a
     * stalled lead would have a month to erode before the next attempt, which
     * 2.8 calls the invariant everything else rests on. A run with nothing due
     * is a handful of catalogue queries.
     *
     * NOT '@daily'. That is midnight exactly, and several OWA installs commonly
     * share one database server -- so every one of them would start a run, and
     * possibly a REORGANIZE that rewrites rows, at the same instant. Same
     * reasoning as core-update-check below, for a local reason rather than a
     * remote one.
     */
    'rotate-partitions' => [
        'command'     => 'partition-rotate',
        'schedule'    => [ 'spread' => 'daily' ],
        'params'      => [],
        'description' => 'Keeps the event and reporting tables partitioned ahead of the calendar, and merges old periods '
          . 'so the tables stay within the server\'s open-file limit. Deletes nothing unless told to keep '
          . 'less history (raw_retention_months, and cube_retention_months per Property).',
    ],

    /*
     * The tracking intake's drain (PLAN 2.30.4), every minute, on the servers
     * whose role runs the scheduler. A minute with nothing queued costs a few
     * stat() calls (isProbablyEmpty()).
     */
    'drain-tracker-ingest' => [
        'command'     => 'drain-tracker-ingest',
        'schedule'    => '* * * * *',
        'params'      => [ 'scheduled' => 1 ],
        'description' => 'Ingests the beacons waiting in the tracker-ingest queue: every beacon when queueing is on, and '
          . 'any whose write failed and is waiting to be retried. First forwards any beacons the AWS SQS queue '
          . 'refused and the file queue kept. A minute with nothing waiting costs a few file checks.',
    ],

    /*
     * Dead letters back to the intake once each, daily (PLAN 2.30.4): a beacon
     * that died in an outage longer than the back-off gets one more round, and
     * one already replayed is kept for cmd=tracker-ingest-replay: on disk until
     * it is replayed, on SQS for the queue's fourteen-day retention. A dead
     * letter older than raw's retention window is removed instead
     * (TrackerIngest::replay()).
     */
    'replay-tracker-ingest' => [
        'command'     => 'tracker-ingest-replay',
        'schedule'    => [ 'spread' => 'daily' ],
        'params'      => [ 'scheduled' => 1 ],
        'description' => 'Gives each beacon in the tracker-ingest dead-letter queue one more try, once: one that failed '
          . 'during an outage longer than the retries gets ingested after all. One already replayed is kept '
          . 'for cmd=tracker-ingest-replay: in the file queue until it is replayed, in AWS SQS for the queue\'s '
          . 'fourteen-day retention, after which SQS deletes it. One older than raw_retention_months is deleted instead.',
    ],

    /*
     * The intake's archived batches (archive_old_events), daily, under raw's
     * retention window: a batch is kept as long as the events in it would be,
     * so with raw_retention_months unset nothing is deleted. By hand,
     * cmd=prune-event-queue-archives interval= prunes by age.
     */
    'prune-tracker-ingest-archive' => [
        'command'     => 'prune-event-queue-archives',
        'schedule'    => [ 'spread' => 'daily' ],
        'params'      => [ 'scheduled' => 1 ],
        'description' => 'Deletes the tracking intake\'s archived batch files older than raw_retention_months, the window '
          . 'event data is kept for. Deletes nothing when that is not set.',
    ],

    // Finished one-off jobs (PLAN 2.30.5): done after 7 days, failed after 30.
    'prune-job-queue' => [
        'command'     => 'jobs-prune',
        'schedule'    => [ 'spread' => 'daily' ],
        'params'      => [],
        'description' => sprintf( 'Deletes finished one-off jobs from the job queue: done ones after %d days, failed ones after %d.',
            \OWA\Module\Base\Classes\JobQueue::KEEP_DONE / 86400, \OWA\Module\Base\Classes\JobQueue::KEEP_FAILED / 86400 ),
    ],

    /*
     * The reporting cube's build, EVERY FIVE MINUTES. Reports read the cube,
     * and their header says when it was built, so this cadence is how fresh
     * every report is.
     *
     * Affordable because a run skips what has not changed: a partition nothing
     * has reached since it was built, whose sessions had all closed by then, is
     * left alone (Cube\Builder::isCurrent()), and one empty in both raw and the
     * cube costs an index probe. So a quiet Property costs a scan per partition
     * and no swap, and an active one rebuilds today's partition. An
     * installation that wants it more or less often says so in
     * OWA_SCHEDULED_JOBS.
     *
     * Spread, not on the minute, for the reason rotate-partitions is: several
     * OWA installs commonly share one database server, and a rebuild ends in an
     * EXCHANGE PARTITION. Two runs cannot collide on a cube either way -- each
     * takes the cube's lock and a second skips it.
     */
    'rebuild-cube' => [
        'command'     => 'cube-rebuild',
        'schedule'    => [ 'spread' => 'minutely', 'interval' => 5 ],
        'params'      => [],
        'description' => 'Builds each Property\'s reporting cube from new events, so reports are at most a few minutes '
          . 'behind. Skips the days that have not changed.',
    ],

    /*
     * Putting registered custom-dimension columns on the cubes.
     *
     * FREQUENT, unlike everything else here, and cheap enough to be. On a run
     * with nothing pending it is ONE indexed read for the whole installation --
     * Dimensions::propertiesWithPendingWork() -- and does no DDL at all.
     *
     * What it buys is the wait. Registering a dimension cannot do the ALTER
     * inline: it is a full table rebuild, which on a large cube runs past a
     * request's time limit -- and a killed request does not stop it, so a
     * screen doing this inline would time out, add the column anyway, and
     * invite a retry that pays a second rebuild. The build reconciles too, so
     * nothing DEPENDS on this job; without it a dimension registered from a
     * screen would simply wait for the next daily build instead of a quarter of
     * an hour.
     */
    'apply-custom-dimensions' => [
        'command'     => 'custom-dimension-apply',
        'schedule'    => [ 'spread' => 'minutely', 'interval' => 15 ],
        'params'      => [],
        'description' => 'Adds the columns for newly registered custom dimensions to the reporting cubes. Adding one '
          . 'rebuilds the table, which is too slow for the request that registered it. With nothing pending '
          . 'it is one quick read.',
    ],

    /*
     * Automatic updates, and the notifications bell's release feed
     * (Classes\AutoUpdate\ReleaseCheck): every hour, so an install can start
     * inside a window of an hour; four of those runs a day list the releases,
     * six hours apart at hours each installation keeps. It took over from
     * fetch-notifications, which made the same call daily.
     *
     * Spread by the minute and, for the listing, by the hour: every install
     * calling the update host at the same instant cannot be told from an attack.
     *
     * It runs while schema updates are pending: the release that fixes a
     * failed one is what it installs.
     */
    'core-update-check' => [
        'command'     => 'core-update-check',
        'schedule'    => [ 'spread' => 'hourly', 'interval' => 1 ],
        'params'      => [ 'scheduled' => 1 ],
        'description' => 'Automatic updates, every hour: four times a day lists OWA\'s releases for the notifications bell, and downloads and verifies the release to update to; then installs it, in the update window if one is set. The files are copied when the run\'s other jobs have finished, and the next run finishes the install.',
        'while_updates_pending' => true,
    ],
];
