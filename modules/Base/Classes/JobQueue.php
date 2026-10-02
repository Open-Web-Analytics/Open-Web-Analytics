<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One-off admin jobs (PLAN 2.30.5): a registered CLI command and its arguments,
 * queued by whatever needs it run and drained by schedule-run.
 *
 * Reviewed against Laravel's database queue, and shaped by what the scheduler
 * that drains it cannot have: no long-running worker, no shared cache, a
 * 240-second budget, and a host that may reboot mid-job.
 *
 * CLAIMING IS A LEASE. A job is taken with SELECT ... FOR UPDATE SKIP LOCKED
 * and marked running until lease_until, in one transaction, so two overlapping
 * ticks claim different jobs. A job whose runner died -- a fatal, an
 * out-of-memory kill, a reboot -- is claimed again once its lease has passed.
 * There is no reaper: the claim is the recovery.
 *
 * THE ATTEMPT IS COUNTED AT CLAIM, and checked before running. A job already
 * past its max_attempts when claimed is marked failed without running, which
 * is the only way a job that kills its own runner stops being retried.
 *
 * UNIQUE UNTIL CLAIMED. A job may carry a dedupe key, enforced by a unique
 * index in the same insert, so it cannot leak. Enqueueing a key that is still
 * pending updates that job; claiming releases the key, so a change made while
 * a job runs queues a fresh one that sees it.
 *
 * A RETRY KEEPS ITS ROW: the same id, pending again after the next back-off
 * step. A job that exhausts its attempts is kept as failed, with its error,
 * until retried or pruned.
 */
class JobQueue {

    /** Used where a command declares no policy of its own (see policyFor()). */
    const DEFAULT_POLICY = array(
        'max_attempts'  => 3,
        'backoff'       => array( 60, 300, 900 ),
        // Longer than a job may run, or a second tick claims it mid-run.
        'lease_seconds' => 300,
    );

    /** What last_error holds; STRICT mode refuses a longer value. */
    const ERROR_LENGTH = 1000;

    /** How many times a claim retries a deadlock or lock-wait timeout. */
    const CLAIM_RETRIES = 3;

    /** How long done and failed jobs are kept, in seconds. */
    const KEEP_DONE   = 604800;
    const KEEP_FAILED = 2592000;

    /** Rows deleted per statement when pruning. */
    const PRUNE_BATCH = 1000;

    /**
     * The queue's clock, stopped at this unix time. TESTS ONLY: a test moves
     * it on to pass a lease or a back-off step, and a second ticking over
     * mid-assertion cannot make it fail.
     *
     * @var int|null
     */
    public static $frozen_at = null;

    /**
     * A table to use instead of the entity's. TESTS ONLY: a scratch copy, so a
     * test never claims a real job and a real tick never claims a test's.
     *
     * @var string|null
     */
    public static $table = null;

    /** The queue's clock. */
    public static function now() {

        return self::$frozen_at ?? time();
    }

    /** Set by claim() when the database connection was lost; drain() stops. */
    private static $connection_lost = false;

    private static function db() {

        return \OWA\Core\CoreAPI::dbSingleton();
    }

    /**
     * The registered CLI commands, built if this request has not built them.
     *
     * The map is built lazily -- cli.php and the scheduler build it, other
     * requests never need it -- and a web request enqueueing a job is exactly
     * one that has not.
     *
     * @param  string $command
     * @return string|null its action
     */
    private static function commandAction( $command ) {

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        if ( ! $s->getMap( 'cli_commands' ) ) {

            $s->loadCliCommands();
        }

        return $s->getCliCommandClass( (string) $command ) ?: null;
    }

    /** The queue's table: the entity's, or a test's scratch copy. */
    public static function table() {

        return self::$table ?: \OWA\Core\CoreAPI::entityFactory( 'base.job_queue' )->getTableName();
    }

    /**
     * A command's retry policy: DEFAULT_POLICY, overridden by the command's
     * controller declaring a static jobQueuePolicy().
     *
     * @param  string $command
     * @return array max_attempts, backoff (int[]), lease_seconds
     */
    public static function policyFor( $command ) {

        $policy = self::DEFAULT_POLICY;
        $s      = \OWA\Core\CoreAPI::serviceSingleton();
        $map    = $s->getMapValue( 'actions', (string) self::commandAction( $command ) );
        $class  = is_array( $map ) ? ( $map['class_name'] ?? '' ) : '';

        if ( $class && class_exists( $class ) && method_exists( $class, 'jobQueuePolicy' ) ) {

            $policy = array_merge( $policy, (array) $class::jobQueuePolicy() );
        }

        $policy['max_attempts']  = max( 1, (int) $policy['max_attempts'] );
        $policy['lease_seconds'] = max( 1, (int) $policy['lease_seconds'] );
        $policy['backoff']       = array_values( array_map( 'intval', (array) $policy['backoff'] ) ) ?: array( 60 );

        return $policy;
    }

    /**
     * Queue a command to run once.
     *
     * With a $key still pending, that job is updated instead -- its arguments
     * replaced, its start brought forward if this one is due sooner -- so ten
     * saves in a minute leave one job.
     *
     * Not to be called inside a transaction the job should not outlive: the
     * job is committed with whatever surrounds it.
     *
     * @param  string      $command  a registered CLI command
     * @param  array       $params   its arguments
     * @param  string|null $key      at most one pending job per key
     * @param  int         $delay    seconds before it may run
     * @return string|false  the job's id
     */
    public static function enqueue( $command, array $params = array(), $key = null, $delay = 0 ) {

        $command = (string) $command;

        if ( ! self::commandAction( $command ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Not queueing "%s": no such command.', $command ) );

            return false;
        }

        $json = json_encode( (object) $params, JSON_UNESCAPED_SLASHES );

        if ( $json === false ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Not queueing "%s": its arguments are not JSON-encodable.', $command ) );

            return false;
        }

        $key    = $key === null || $key === '' ? null : substr( (string) $key, 0, 255 );
        $now    = self::now();
        $due    = $now + max( 0, (int) $delay );
        $policy = self::policyFor( $command );
        $db     = self::db();

        if ( $key !== null ) {

            $existing = self::pendingWithKey( $key );

            if ( $existing ) {

                $db->query( sprintf(
                    'UPDATE %s SET params = ?, run_after = LEAST(run_after, ?) WHERE id = ? AND status = ?', self::table() ),
                    array( $json, $due, $existing, 'pending' ) );

                return $existing;
            }
        }

        $id = (string) random_int( 1, PHP_INT_MAX );

        $inserted = $db->query( sprintf(
            'INSERT INTO %s (id, command, params, dedupe_key, status, run_after, attempts, max_attempts,'
          . ' lease_seconds, backoff, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)', self::table() ),
            array( $id, $command, $json, $key, 'pending', $due, $policy['max_attempts'],
                   $policy['lease_seconds'], implode( ',', $policy['backoff'] ), $now ) );

        if ( $inserted !== false ) {

            return $id;
        }

        // Lost a race with another enqueue of the same key: theirs is the job.
        return $key !== null ? ( self::pendingWithKey( $key ) ?: false ) : false;
    }

    /** @return string|null the pending job holding $key */
    private static function pendingWithKey( $key ) {

        $row = self::db()->get_row( sprintf(
            'SELECT id FROM %s WHERE dedupe_key = ? AND status = ?', self::table() ), array( $key, 'pending' ) );

        return $row ? (string) $row['id'] : null;
    }

    /**
     * Claim the next job: a due pending one, else one whose lease has expired.
     *
     * Two queries rather than one OR, so each is served by its index.
     *
     * @param  int|null $within  only a job whose lease fits in this many seconds; null for any
     * @return array|null the claimed row
     */
    public static function claim( $within = null ) {

        $db = self::db();

        for ( $try = 1; $try <= self::CLAIM_RETRIES; $try++ ) {

            $errors = $db->queryErrorCount();
            $now    = self::now();
            $fit    = $within === null ? '' : ' AND lease_seconds <= ' . (int) $within;

            $db->beginTransaction();

            $row = $db->get_row( sprintf(
                'SELECT * FROM %s WHERE status = ? AND run_after <= ?%s ORDER BY run_after, id LIMIT 1 FOR UPDATE SKIP LOCKED',
                self::table(), $fit ), array( 'pending', $now ) );

            if ( ! $row && $db->queryErrorCount() === $errors ) {

                $row = $db->get_row( sprintf(
                    'SELECT * FROM %s WHERE status = ? AND lease_until < ?%s ORDER BY lease_until, id LIMIT 1 FOR UPDATE SKIP LOCKED',
                    self::table(), $fit ), array( 'running', $now ) );
            }

            if ( $row && $db->queryErrorCount() === $errors ) {

                $lease = $now + (int) $row['lease_seconds'];

                $db->query( sprintf(
                    'UPDATE %s SET status = ?, dedupe_key = NULL, attempts = attempts + 1, lease_until = ?,'
                  . ' started_at = ? WHERE id = ?', self::table() ),
                    array( 'running', $lease, $now, $row['id'] ) );
            }

            if ( $db->queryErrorCount() === $errors ) {

                $db->endTransaction();

                if ( ! $row ) {

                    return null;
                }

                $row['status']      = 'running';
                $row['attempts']    = (int) $row['attempts'] + 1;
                $row['lease_until'] = $lease;
                $row['started_at']  = $now;
                $row['dedupe_key']  = null;

                return $row;
            }

            $db->rollbackTransaction();

            $error = $db->lastQueryError();

            if ( self::isLostConnection( $error ) ) {

                self::$connection_lost = true;

                return null;
            }

            if ( ! self::isRetryable( $error ) ) {

                \OWA\Core\CoreAPI::notice( 'Job queue: the claim failed: ' . $error );

                return null;
            }
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Job queue: the claim deadlocked %d times; trying next tick.', self::CLAIM_RETRIES ) );

        return null;
    }

    /** A deadlock (1213, SQLSTATE 40001) or lock-wait timeout (1205): worth trying again. */
    public static function isRetryable( $error ) {

        return (bool) preg_match( '/\b(1213|1205|40001)\b|deadlock|lock wait timeout/i', (string) $error );
    }

    /** The server went away or the connection dropped (2006, 2013): stop until the next tick. */
    public static function isLostConnection( $error ) {

        return (bool) preg_match( '/\b(2006|2013)\b|gone away|lost connection/i', (string) $error );
    }

    /**
     * Run due jobs until the queue is empty or the deadline passes.
     *
     * The budget is checked before each claim, against the job's lease: a job
     * is claimed only when what is left of the run covers it, except as the
     * first job, so a job longer than the budget still runs.
     *
     * @param  int      $deadline  unix time to stop claiming
     * @param  callable $run       fn( string $command, array $params, array $job ): array outcome, message
     * @return array counts: done, retried, failed, exhausted
     */
    public static function drain( $deadline, callable $run ) {

        $counts = array( 'done' => 0, 'retried' => 0, 'failed' => 0, 'exhausted' => 0 );
        $first  = true;

        self::$connection_lost = false;

        while ( self::now() < $deadline ) {

            $job = self::claim( $first ? null : $deadline - self::now() );

            if ( ! $job ) {

                if ( self::$connection_lost ) {

                    \OWA\Core\CoreAPI::notice( 'Job queue: the database connection was lost; stopping until the next tick.' );
                }

                break;
            }

            $first = false;

            if ( (int) $job['attempts'] > (int) $job['max_attempts'] ) {

                self::markFailed( $job['id'], sprintf(
                    'Did not finish in %d attempts: each run died before recording an outcome. Last error: %s',
                    (int) $job['max_attempts'], $job['last_error'] ?: '(none)' ) );

                $counts['exhausted']++;

                continue;
            }

            $params = json_decode( (string) $job['params'], true );

            if ( ! is_array( $params ) ) {

                self::markFailed( $job['id'], 'Its arguments are not valid JSON.' );
                $counts['failed']++;

                continue;
            }

            try {

                $outcome = $run( (string) $job['command'], $params, $job );

            } catch ( \Throwable $t ) {

                $outcome = array( 'outcome' => 'failed', 'message' => get_class( $t ) . ': ' . $t->getMessage() );
            }

            $state = (string) ( $outcome['outcome'] ?? 'failed' );

            if ( $state === 'ok' || $state === 'refused' ) {

                self::markDone( $job['id'] );
                $counts['done']++;

            } elseif ( self::retryOrFail( $job, (string) ( $outcome['message'] ?? '' ) ) ) {

                $counts['retried']++;

            } else {

                $counts['failed']++;
            }
        }

        return $counts;
    }

    private static function markDone( $id ) {

        self::db()->query( sprintf(
            'UPDATE %s SET status = ?, lease_until = NULL, finished_at = ? WHERE id = ?', self::table() ),
            array( 'done', self::now(), $id ) );
    }

    private static function markFailed( $id, $error ) {

        self::db()->query( sprintf(
            'UPDATE %s SET status = ?, lease_until = NULL, finished_at = ?, last_error = ? WHERE id = ?', self::table() ),
            array( 'failed', self::now(), self::bound( $error ), $id ) );
    }

    /**
     * After a failed run: pending again after the next back-off step, or
     * failed for good once its attempts are spent.
     *
     * @return bool whether it will be retried
     */
    private static function retryOrFail( array $job, $error ) {

        $attempts = (int) $job['attempts'];

        if ( $attempts >= (int) $job['max_attempts'] ) {

            self::markFailed( $job['id'], $error ?: 'Failed.' );

            return false;
        }

        $steps = array_values( array_filter( array_map( 'intval', explode( ',', (string) $job['backoff'] ) ),
            fn ( $s ) => $s >= 0 ) ) ?: array( 60 );
        $delay = $steps[ min( $attempts - 1, count( $steps ) - 1 ) ];

        self::db()->query( sprintf(
            'UPDATE %s SET status = ?, lease_until = NULL, run_after = ?, last_error = ? WHERE id = ?', self::table() ),
            array( 'pending', self::now() + $delay, self::bound( $error ?: 'Failed.' ), $job['id'] ) );

        return true;
    }

    /** An error message, cut to what the column holds. */
    private static function bound( $error ) {

        return mb_substr( (string) $error, 0, self::ERROR_LENGTH );
    }

    /**
     * Make a failed job due now, with its attempts and error cleared.
     *
     * @param  string $id  a job id, or 'all' for every failed job
     * @return int how many
     */
    public static function retry( $id ) {

        $db  = self::db();
        $sql = sprintf( 'UPDATE %s SET status = ?, attempts = 0, last_error = NULL, run_after = ?, finished_at = NULL'
                      . ' WHERE status = ?', self::table() );
        $params = array( 'pending', self::now(), 'failed' );

        if ( $id !== 'all' ) {

            $sql     .= ' AND id = ?';
            $params[] = (string) $id;
        }

        $db->query( $sql, $params );

        return (int) $db->getAffectedRows();
    }

    /**
     * Delete one job, whatever its state.
     *
     * @return bool whether there was one
     */
    public static function forget( $id ) {

        $db = self::db();
        $db->query( sprintf( 'DELETE FROM %s WHERE id = ?', self::table() ), array( (string) $id ) );

        return (int) $db->getAffectedRows() > 0;
    }

    /**
     * Delete done jobs older than KEEP_DONE and failed ones older than
     * KEEP_FAILED, PRUNE_BATCH rows per statement.
     *
     * @return int how many
     */
    public static function prune() {

        $db      = self::db();
        $removed = 0;

        foreach ( array( 'done' => self::KEEP_DONE, 'failed' => self::KEEP_FAILED ) as $status => $keep ) {

            do {

                $db->query( sprintf( 'DELETE FROM %s WHERE status = ? AND finished_at < ? LIMIT %d',
                    self::table(), self::PRUNE_BATCH ), array( $status, self::now() - $keep ) );

                $n = (int) $db->getAffectedRows();
                $removed += $n;

            } while ( $n === self::PRUNE_BATCH );
        }

        return $removed;
    }

    /**
     * The queue at a glance, for the health screen and cmd=jobs.
     *
     * @return array due, delayed, running, failed, done (counts) and
     *               oldest_due_age (seconds, null when nothing is due)
     */
    public static function stats() {

        $now  = self::now();
        $rows = (array) self::db()->get_results( sprintf(
            'SELECT status, (run_after <= ?) AS is_due, COUNT(*) AS n, MIN(run_after) AS oldest'
          . ' FROM %s GROUP BY status, is_due', self::table() ), array( $now ) );

        $out = array( 'due' => 0, 'delayed' => 0, 'running' => 0, 'failed' => 0, 'done' => 0, 'oldest_due_age' => null );

        foreach ( $rows as $row ) {

            $n = (int) $row['n'];

            if ( $row['status'] === 'pending' ) {

                if ( (int) $row['is_due'] ) {

                    $out['due'] += $n;
                    $out['oldest_due_age'] = $now - (int) $row['oldest'];

                } else {

                    $out['delayed'] += $n;
                }

            } elseif ( isset( $out[ $row['status'] ] ) ) {

                $out[ $row['status'] ] += $n;
            }
        }

        return $out;
    }

    /**
     * Jobs, newest first, for cmd=jobs.
     *
     * @param  string|null $status
     * @param  int         $limit
     * @return array[]
     */
    public static function listJobs( $status = null, $limit = 50 ) {

        $where  = $status ? ' WHERE status = ?' : '';
        $params = $status ? array( (string) $status ) : array();

        return (array) self::db()->get_results( sprintf(
            'SELECT id, command, params, status, attempts, max_attempts, run_after, last_error, created_at, finished_at'
          . ' FROM %s%s ORDER BY created_at DESC, id LIMIT %d', self::table(), $where, max( 1, (int) $limit ) ), $params );
    }

    /**
     * Whether a job is waiting or running for a command, optionally for one key.
     *
     * @param  string      $command
     * @param  string|null $key  matched while pending; a running job has released it
     * @return bool
     */
    public static function isQueued( $command, $key = null ) {

        if ( $key !== null && self::pendingWithKey( $key ) ) {

            return true;
        }

        return (bool) self::db()->get_row( sprintf(
            'SELECT id FROM %s WHERE command = ? AND status IN (?, ?) LIMIT 1', self::table() ),
            array( (string) $command, 'pending', 'running' ) );
    }
}

?>
