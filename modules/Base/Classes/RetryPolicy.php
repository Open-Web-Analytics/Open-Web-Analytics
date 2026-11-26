<?php
namespace OWA\Module\Base\Classes;

/**
 * How a failed run of a job is tried again. ONE definition, for recurring and
 * one-off jobs alike:
 *
 *   max_attempts  the attempts a run gets, the first included; null for no limit
 *   backoff       the seconds to wait before each retry, in order; the last repeats
 *
 * If a run fails, it is tried again after each wait in turn, up to
 * max_attempts in all. Then that run has failed. What follows is the job's,
 * not the policy's:
 *
 *   - a one-off job is kept as failed, and cmd=jobs-retry runs it again;
 *   - a recurring job's next scheduled run happens when the schedule says.
 *     That is the schedule, not a retry, and it takes the place of a retry
 *     still waiting.
 *
 * A run can also give up -- Cli::fail( $msg, true ) -- leaving it no attempts
 * whatever the policy says. Only for a failure a retry would meet again.
 *
 * WHERE A POLICY COMES FROM, most specific first, each merged key by key over
 * the next (resolve()):
 *
 *   1. attached to the job: registerJob(), config/jobs.php or
 *      OWA_SCHEDULED_JOBS for a recurring job, enqueueJob() for a one-off;
 *   2. the command's own: a static retryPolicy() on its controller;
 *   3. the default for the job's kind, RECURRING or ONE_OFF.
 *
 * WHEN A RETRY IS DUE. The waits are counted from the failure for a one-off
 * job (JobQueue keeps its attempts in a column), and from the run's scheduled
 * time for a recurring one. Attempt n's window opens at the scheduled time
 * plus the first n-1 waits (windowOpens()); the dispatcher starts at most one
 * attempt per window, so the attempt a run is on is the window its last start
 * fell in (attemptOf()), with no counter to keep. A window that passed while
 * the dispatcher was not running is not made up, as a missed run is not.
 */
final class RetryPolicy {

    /** Recurring jobs: retried until the next run, waiting a minute and doubling to an hour. */
    const RECURRING = [
        'max_attempts' => null,
        'backoff'      => [ 60, 120, 240, 480, 960, 1920, 3600 ],
    ];

    /** One-off jobs: three attempts, a minute and then five minutes apart. */
    const ONE_OFF = [
        'max_attempts' => 3,
        'backoff'      => [ 60, 300, 900 ],
    ];

    /** The policy's keys, described for the wiki and for a refused value. */
    const KEYS = [
        'max_attempts' => 'The attempts a run gets, the first included: a whole number of at least 1, or null for no limit.',
        'backoff'      => 'The seconds to wait before each retry, in order, the last repeating: a list of whole numbers, at least one.',
    ];

    /** @var string */
    const KIND_RECURRING = 'recurring';

    /** @var string */
    const KIND_ONE_OFF = 'one-off';

    /**
     * What is wrong with a policy, or null when it can be used. Any subset of
     * the keys may be given; resolve() fills in the rest.
     *
     * @param  mixed $policy
     * @return string|null
     */
    public static function problem( $policy ) {

        if ( ! is_array( $policy ) ) {

            return 'a retry policy is an array of max_attempts and backoff';
        }

        $unknown = array_diff( array_map( 'strval', array_keys( $policy ) ), array_keys( self::KEYS ) );

        if ( $unknown ) {

            return sprintf( 'a retry policy has no %s (it takes %s)',
                implode( ', ', $unknown ), implode( ', ', array_keys( self::KEYS ) ) );
        }

        if ( array_key_exists( 'max_attempts', $policy ) && $policy['max_attempts'] !== null
            && ( ! is_int( $policy['max_attempts'] ) || $policy['max_attempts'] < 1 ) ) {

            return 'max_attempts is a whole number of at least 1, or null for no limit';
        }

        if ( array_key_exists( 'backoff', $policy ) ) {

            $b = $policy['backoff'];

            if ( ! is_array( $b ) || ! $b || array_values( $b ) !== $b
                || array_filter( $b, fn ( $s ) => ! is_int( $s ) || $s < 0 ) ) {

                return 'backoff is a list of whole numbers of seconds, at least one';
            }
        }

        return null;
    }

    /**
     * The policy a job runs under: its own, over its command's, over its
     * kind's default.
     *
     * An unusable policy is refused where it is given (registerJob(),
     * OWA_SCHEDULED_JOBS, enqueue()). One that reaches here anyway, or a
     * command's own that is wrong, is skipped with a notice rather than
     * guessed at.
     *
     * @param  string     $kind      KIND_RECURRING or KIND_ONE_OFF
     * @param  string     $command   the CLI command the job runs
     * @param  array<string, mixed>|null $attached  the job's own policy
     * @return array{max_attempts: int|null, backoff: list<int>}
     */
    public static function resolve( $kind, $command, $attached = null ) {

        $policy = $kind === self::KIND_ONE_OFF ? self::ONE_OFF : self::RECURRING;
        $class  = JobQueue::commandClass( (string) $command );

        $layers = [
            $class !== null && method_exists( $class, 'retryPolicy' ) ? $class::retryPolicy() : null,
            $attached,
        ];

        foreach ( $layers as $layer ) {

            if ( $layer === null || $layer === [] ) {

                continue;
            }

            $problem = self::problem( $layer );

            if ( $problem !== null ) {

                \OWA\Core\CoreAPI::notice( sprintf( 'The retry policy for %s is not used: %s.', $command, $problem ) );

                continue;
            }

            $policy = array_merge( $policy, $layer );
        }

        return [
            'max_attempts' => $policy['max_attempts'] === null ? null : (int) $policy['max_attempts'],
            'backoff'      => array_values( array_map( 'intval', $policy['backoff'] ) ),
        ];
    }

    /**
     * The wait before attempt $attempt (2 for the first retry): the backoff
     * step for it, the last step repeating.
     *
     * @param  array{max_attempts: int|null, backoff: list<int>} $policy
     * @param  int   $attempt
     * @return int seconds
     */
    public static function waitBefore( array $policy, $attempt ) {

        $steps = $policy['backoff'] ?: [ 60 ];

        return (int) $steps[ min( max( 0, (int) $attempt - 2 ), count( $steps ) - 1 ) ];
    }

    /**
     * Whether a run on attempt $attempt has none left.
     *
     * @param  array{max_attempts: int|null, backoff: list<int>} $policy
     * @param  int   $attempt
     * @return bool
     */
    public static function attemptsSpent( array $policy, $attempt ) {

        return $policy['max_attempts'] !== null && (int) $attempt >= $policy['max_attempts'];
    }

    /**
     * When attempt $attempt of a recurring run may start: the scheduled time
     * plus the waits before it.
     *
     * @param  array{max_attempts: int|null, backoff: list<int>} $policy
     * @param  int   $slot     the run's scheduled time
     * @param  int   $attempt  1 for the first
     * @return int unix time
     */
    public static function windowOpens( array $policy, $slot, $attempt ) {

        $at = (int) $slot;

        for ( $n = 2; $n <= (int) $attempt; $n++ ) {

            $at += max( 1, self::waitBefore( $policy, $n ) );
        }

        return $at;
    }

    /**
     * The attempt of a recurring run that started at $started: the window it
     * fell in. 0 when it started before the run's scheduled time, so it was
     * not an attempt at this run.
     *
     * Not capped by max_attempts: a start later than every window is past the
     * last attempt, and attemptsSpent() says so.
     *
     * @param  array{max_attempts: int|null, backoff: list<int>} $policy
     * @param  int   $slot     the run's scheduled time
     * @param  int   $started  when that attempt started
     * @return int
     */
    public static function attemptOf( array $policy, $slot, $started ) {

        $offset = (int) $started - (int) $slot;

        if ( $offset < 0 ) {

            return 0;
        }

        $steps   = $policy['backoff'] ?: [ 60 ];
        $attempt = 1;
        $opens   = 0;

        // Through the listed steps, then the last one repeating, by division:
        // a run that started days late must not walk a window at a time.
        foreach ( $steps as $step ) {

            $step = max( 1, (int) $step );

            if ( $opens + $step > $offset ) {

                return $attempt;
            }

            $opens += $step;
            $attempt++;
        }

        $last = max( 1, (int) end( $steps ) );

        return $attempt + intdiv( $offset - $opens, $last );
    }

    /**
     * The policy in words, for schedule-status and the wiki:
     * "up to 3 attempts, waiting 1 minute then 5 minutes".
     *
     * @param  array{max_attempts: int|null, backoff: list<int>} $policy
     * @param  string $kind  KIND_RECURRING or KIND_ONE_OFF
     * @return string
     */
    public static function describe( array $policy, $kind ) {

        $max = $policy['max_attempts'];

        if ( $max === 1 ) {

            return 'one attempt, not retried';
        }

        $waits = [];

        for ( $n = 2; $n <= ( $max ?? count( $policy['backoff'] ) + 1 ); $n++ ) {

            $waits[] = self::waitBefore( $policy, $n );
        }

        // Equal steps at the end read as one: "1 minute, then every 5 minutes".
        $popped = false;

        while ( count( $waits ) > 1 && $waits[ count( $waits ) - 1 ] === $waits[ count( $waits ) - 2 ] ) {

            array_pop( $waits );
            $popped = true;
        }

        $words = self::waitsInWords( $waits, $max === null || $popped );

        if ( $max === null ) {

            return ( $kind === self::KIND_ONE_OFF ? 'until it succeeds, ' : 'until the next run, ' ) . $words;
        }

        return sprintf( 'up to %d attempts, %s', $max, $words );
    }

    /**
     * Waits in words: "1 minute then 5 minutes apart", "1 minute doubling to
     * 1 hour", with "then every" for a last step that repeats.
     *
     * @param  list<int> $waits
     * @param  bool      $repeats  whether the last wait repeats without end
     * @return string
     */
    private static function waitsInWords( array $waits, $repeats ) {

        $doubling = count( $waits ) >= 3;

        for ( $i = 1; $doubling && $i < count( $waits ) - 1; $i++ ) {

            $doubling = $waits[ $i ] === 2 * $waits[ $i - 1 ];
        }

        $last = $waits[ count( $waits ) - 1 ];

        if ( $doubling && $last > $waits[ count( $waits ) - 2 ] && $last <= 2 * $waits[ count( $waits ) - 2 ] ) {

            return sprintf( 'waiting %s and doubling to %s%s', self::duration( $waits[0] ), self::duration( $last ),
                $repeats ? ', then every ' . self::duration( $last ) : '' );
        }

        $named = array_map( [ self::class, 'duration' ], $waits );

        if ( $repeats ) {

            $end = array_pop( $named );

            return $named ? sprintf( 'waiting %s, then every %s', implode( ', ', $named ), $end ) : sprintf( 'every %s', $end );
        }

        return sprintf( 'waiting %s', implode( ' then ', $named ) );
    }

    /**
     * @param  int $seconds
     * @return string
     */
    private static function duration( $seconds ) {

        $seconds = (int) $seconds;

        foreach ( [ 3600 => 'hour', 60 => 'minute', 1 => 'second' ] as $unit => $name ) {

            if ( $seconds >= $unit && $seconds % $unit === 0 ) {

                $n = intdiv( $seconds, $unit );

                return $n . ' ' . $name . ( $n === 1 ? '' : 's' );
            }
        }

        return $seconds . ' seconds';
    }
}
