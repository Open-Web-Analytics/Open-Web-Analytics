<?php
namespace OWA\Core;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Cron expressions, and deciding whether a schedule is due.
 *
 * Pure: no clock, no database, no settings. `$now` is always an argument, which
 * is the only shape that can be tested honestly -- a schedule that can only be
 * checked against the moment the check runs makes the assertion a copy of the
 * implementation.
 *
 * THE DUE TEST IS LEVEL-TRIGGERED, NOT EDGE-TRIGGERED, and that is the whole
 * design. Laravel asks "does this expression match the current minute"; miss the
 * minute -- cron ran late, the box was busy, a long job held the tick -- and a
 * monthly job waits another month. Here the caller keeps the occurrence its last
 * run satisfied, and the question becomes "has a matching minute passed since
 * then". A job stays due until it has actually run.
 *
 * No backfill: a job missed for three days runs ONCE at the next tick, not once
 * per missed occurrence. That imposes a rule on what may be scheduled --
 *
 *   A SCHEDULED JOB MUST BE CONVERGENT, NOT INCREMENTAL.
 *
 * partition-rotate extends *to* a boundary computed from today, so one run after
 * a gap leaves the same state as three would have. A job that processes
 * "yesterday's data" is incremental and must not be registered without being
 * made convergent first.
 */
class Cron {

    /** Minute, hour, day-of-month, month, day-of-week. */
    const FIELDS = array(
        array( 0, 59 ),   // minute
        array( 0, 23 ),   // hour
        array( 1, 31 ),   // day of month
        array( 1, 12 ),   // month
        array( 0, 6 ),    // day of week, 0 = Sunday
    );

    /**
     * The standard vixie-cron shorthands, so the readable forms are not an
     * invention of ours that operators have to learn.
     */
    const ALIASES = array(
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    );

    /**
     * A daily expression at a minute and hour derived from `$seed`.
     *
     * `@daily` is `0 0 * * *` -- midnight EXACTLY. A job that calls somebody
     * else's API on that schedule means every install running it makes the same
     * request at the same instant, and most servers keep UTC, so "the same
     * instant" is not even spread by timezone. That is a thundering herd
     * pointed at a third party, and the third party sees it as an attack
     * whatever we meant by it.
     *
     * Spread, not RANDOM. The scheduler decides whether a job is due by
     * comparing the occurrence it last satisfied against the expression, so an
     * expression that changed between runs would leave a job either firing
     * repeatedly or never being due again. Seeding from something stable per
     * install -- and constant across restarts, deploys and this process's
     * lifetime -- makes each install pick its own time and keep it.
     *
     * Both fields come from independent slices of the digest. Deriving the hour
     * from the minute (say, `$minute % 24`) would correlate them and collapse
     * the 1440 possible times onto far fewer.
     *
     * @param string $seed something stable and install-specific
     * @return string a five-field cron expression
     */
    public static function dailySpreadFor( $seed ) {

        $digest = md5( (string) $seed );

        $minute = hexdec( substr( $digest, 0, 6 ) ) % 60;
        $hour   = hexdec( substr( $digest, 6, 6 ) ) % 24;

        return sprintf( '%d %d * * *', $minute, $hour );
    }

    /**
     * How far back nextDueSince() will look for an unsatisfied occurrence.
     *
     * A job that has not run in years should still run once, but walking minute
     * by minute back to 1970 to discover that is pointless: the answer is the
     * same as "it is due now". Two years is longer than any plausible neglect
     * and bounds the work.
     */
    const LOOKBACK_DAYS = 730;

    /**
     * Parse an expression into per-field sets of permitted values.
     *
     * Returns null rather than throwing, and null rather than guessing: the
     * caller refuses the job. An expression that cannot be read must never fall
     * back to a default, which would run something on a cadence nobody chose.
     *
     * @param string $expr
     * @return array|null  five arrays of ints, or null
     */
    public static function parse( $expr ) {

        $expr = strtolower( trim( (string) $expr ) );

        if ( $expr === '' ) {

            return null;
        }

        if ( isset( self::ALIASES[ $expr ] ) ) {

            $expr = self::ALIASES[ $expr ];
        }

        // An unknown @something is a typo, not a cron expression.
        if ( $expr[0] === '@' ) {

            return null;
        }

        $fields = preg_split( '/\s+/', $expr );

        if ( ! is_array( $fields ) || count( $fields ) !== 5 ) {

            return null;
        }

        $parsed = array();

        foreach ( $fields as $i => $field ) {

            $values = self::parseField( $field, self::FIELDS[ $i ][0], self::FIELDS[ $i ][1] );

            if ( $values === null ) {

                return null;
            }

            $parsed[] = $values;
        }

        return $parsed;
    }

    /**
     * One field: `*`, `5`, `1-5`, `1,3,5`, and step syntax such as a star
     * followed by slash-15. (Spelt out because the literal sequence would end
     * this docblock.)
     *
     * @param string $field
     * @param int    $min
     * @param int    $max
     * @return array|null  sorted, unique ints within [$min,$max]
     */
    protected static function parseField( $field, $min, $max ) {

        $values = array();

        foreach ( explode( ',', $field ) as $part ) {

            if ( $part === '' ) {

                return null;
            }

            $step  = 1;
            $range = $part;

            if ( strpos( $part, '/' ) !== false ) {

                list( $range, $step ) = explode( '/', $part, 2 );

                if ( ! ctype_digit( (string) $step ) || (int) $step < 1 ) {

                    return null;
                }

                $step = (int) $step;
            }

            if ( $range === '*' ) {

                $from = $min;
                $to   = $max;

            } elseif ( strpos( $range, '-' ) !== false ) {

                list( $from, $to ) = explode( '-', $range, 2 );

                if ( ! ctype_digit( (string) $from ) || ! ctype_digit( (string) $to ) ) {

                    return null;
                }

                $from = (int) $from;
                $to   = (int) $to;

            } else {

                if ( ! ctype_digit( (string) $range ) ) {

                    return null;
                }

                $from = (int) $range;

                // A bare number with a step means "from here to the end",
                // matching cron: 5/10 in the minute field is 5,15,25,...
                $to = ( $step > 1 ) ? $max : $from;
            }

            if ( $from < $min || $to > $max || $from > $to ) {

                return null;
            }

            for ( $v = $from; $v <= $to; $v += $step ) {

                $values[ $v ] = true;
            }
        }

        if ( ! $values ) {

            return null;
        }

        $values = array_keys( $values );
        sort( $values );

        return $values;
    }

    /**
     * Does this expression match the given minute?
     *
     * Day-of-month and day-of-week are OR'd when both are restricted, which is
     * cron's own rule and surprises everyone who has not met it: `0 0 1 * 1`
     * means "the 1st, and every Monday", not "Mondays that fall on the 1st".
     *
     * @param array  $parsed
     * @param int    $when      unix timestamp
     * @param string $timezone
     * @return bool
     */
    public static function matches( array $parsed, $when, $timezone ) {

        $d = self::at( $when, $timezone );

        if ( ! $d ) {

            return false;
        }

        if ( ! in_array( (int) $d->format( 'i' ), $parsed[0], true ) ) { return false; }
        if ( ! in_array( (int) $d->format( 'G' ), $parsed[1], true ) ) { return false; }
        if ( ! in_array( (int) $d->format( 'n' ), $parsed[3], true ) ) { return false; }

        $dom_restricted = count( $parsed[2] ) !== 31;
        $dow_restricted = count( $parsed[4] ) !== 7;

        $dom = in_array( (int) $d->format( 'j' ), $parsed[2], true );
        $dow = in_array( (int) $d->format( 'w' ), $parsed[4], true );

        if ( $dom_restricted && $dow_restricted ) {

            return $dom || $dow;
        }

        return $dom && $dow;
    }

    /**
     * Is this schedule due, given the occurrence its last run satisfied?
     *
     * @param array    $parsed
     * @param int|null $last_slot  epoch of the last satisfied occurrence; 0/null if never
     * @param int      $now
     * @param string   $timezone
     * @return bool
     */
    public static function isDue( array $parsed, $last_slot, $now, $timezone ) {

        return self::dueSlot( $parsed, $last_slot, $now, $timezone ) !== null;
    }

    /**
     * The occurrence that is due and unsatisfied, or null.
     *
     * The most recent matching minute at or before $now that is later than
     * $last_slot. Recording *this* rather than "now" is what makes a run
     * idempotent within its occurrence: every later tick in the same period
     * computes the same slot, finds it satisfied, and does nothing.
     *
     * @param array    $parsed
     * @param int|null $last_slot
     * @param int      $now
     * @param string   $timezone
     * @return int|null
     */
    public static function dueSlot( array $parsed, $last_slot, $now, $timezone ) {

        $slot = self::previousMatch( $parsed, $now, $timezone );

        if ( $slot === null || $slot <= (int) $last_slot ) {

            return null;
        }

        return $slot;
    }

    /**
     * The latest matching minute at or before $when.
     *
     * Steps by DAY, not by minute. A monthly schedule checked a month after its
     * last run would otherwise walk 43,200 minutes on every tick, which at a
     * tick a minute is quadratic and would dominate the dispatcher.
     *
     * @param array  $parsed
     * @param int    $when
     * @param string $timezone
     * @return int|null
     */
    public static function previousMatch( array $parsed, $when, $timezone ) {

        $when = (int) $when;
        $when = $when - ( $when % 60 );

        // Anchored at midday so stepping between days is safe in zones whose
        // DST transition lands on midnight.
        $day = self::at( $when, $timezone );

        if ( ! $day ) {

            return null;
        }

        $day = $day->setTime( 12, 0, 0 );

        for ( $i = 0; $i <= self::LOOKBACK_DAYS; $i++ ) {

            if ( self::dayMatches( $parsed, $day ) ) {

                foreach ( array_reverse( $parsed[1] ) as $h ) {

                    foreach ( array_reverse( $parsed[0] ) as $m ) {

                        $t = $day->setTime( $h, $m, 0 )->getTimestamp();

                        // A candidate inside a skipped DST hour is rolled
                        // forward by setTime and lands after $when, so it is
                        // simply not a match -- that minute did not exist.
                        if ( $t <= $when ) {

                            return $t;
                        }
                    }
                }
            }

            $day  = $day->modify( '-1 day' );
            $when = $day->setTime( 23, 59, 0 )->getTimestamp();
        }

        return null;
    }

    /**
     * The next matching minute strictly after $when. For reporting only.
     *
     * @param array  $parsed
     * @param int    $when
     * @param string $timezone
     * @return int|null
     */
    public static function nextAfter( array $parsed, $when, $timezone ) {

        $when = (int) $when;
        $when = $when - ( $when % 60 );

        $day = self::at( $when, $timezone );

        if ( ! $day ) {

            return null;
        }

        $day = $day->setTime( 12, 0, 0 );

        for ( $i = 0; $i <= self::LOOKBACK_DAYS; $i++ ) {

            if ( self::dayMatches( $parsed, $day ) ) {

                foreach ( $parsed[1] as $h ) {

                    foreach ( $parsed[0] as $m ) {

                        $t = $day->setTime( $h, $m, 0 )->getTimestamp();

                        if ( $t > $when ) {

                            return $t;
                        }
                    }
                }
            }

            $day  = $day->modify( '+1 day' );
            $when = $day->setTime( 0, 0, 0 )->getTimestamp() - 60;
        }

        return null;
    }

    /**
     * Does this date satisfy the month, day-of-month and day-of-week fields?
     *
     * Day-of-month and day-of-week are OR'd when both are restricted, which is
     * cron's own rule and surprises everyone who has not met it: `0 0 1 * 1`
     * means "the 1st, and every Monday", not "Mondays that fall on the 1st".
     *
     * @param array              $parsed
     * @param \DateTimeImmutable $day
     * @return bool
     */
    protected static function dayMatches( array $parsed, \DateTimeImmutable $day ) {

        if ( ! in_array( (int) $day->format( 'n' ), $parsed[3], true ) ) {

            return false;
        }

        $dom_restricted = count( $parsed[2] ) !== 31;
        $dow_restricted = count( $parsed[4] ) !== 7;

        $dom = in_array( (int) $day->format( 'j' ), $parsed[2], true );
        $dow = in_array( (int) $day->format( 'w' ), $parsed[4], true );

        if ( $dom_restricted && $dow_restricted ) {

            return $dom || $dow;
        }

        return $dom && $dow;
    }

    /**
     * A schedule in words, for the status report.
     *
     * Only the shapes an operator is likely to write get a phrase; anything else
     * is shown as the expression itself, which is honest and still readable.
     *
     * @param string $expr  the original expression, aliases included
     * @return string
     */
    public static function describe( $expr ) {

        $expr = strtolower( trim( (string) $expr ) );

        $words = array(
            '@yearly'   => 'yearly, on 1 January',
            '@annually' => 'yearly, on 1 January',
            '@monthly'  => 'monthly, on the 1st at 00:00',
            '@weekly'   => 'weekly, on Sunday at 00:00',
            '@daily'    => 'daily at 00:00',
            '@midnight' => 'daily at 00:00',
            '@hourly'   => 'hourly, on the hour',
        );

        if ( isset( $words[ $expr ] ) ) {

            return $words[ $expr ];
        }

        if ( preg_match( '#^\*/(\d+) \* \* \* \*$#', $expr, $m ) ) {

            return sprintf( 'every %d minutes', (int) $m[1] );
        }

        if ( preg_match( '#^(\d+) \* \* \* \*$#', $expr, $m ) ) {

            return sprintf( 'hourly, at %d past', (int) $m[1] );
        }

        if ( preg_match( '#^(\d+) (\d+) \* \* \*$#', $expr, $m ) ) {

            return sprintf( 'daily at %02d:%02d', (int) $m[2], (int) $m[1] );
        }

        if ( preg_match( '#^(\d+) (\d+) (\d+) \* \*$#', $expr, $m ) ) {

            return sprintf( 'monthly, on day %d at %02d:%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
        }

        return $expr;
    }

    /**
     * A timestamp in a given zone, or null if the zone is unusable.
     *
     * @param int    $when
     * @param string $timezone
     * @return \DateTimeImmutable|null
     */
    protected static function at( $when, $timezone ) {

        try {

            $tz = new \DateTimeZone( (string) $timezone );

        } catch ( \Exception $e ) {

            return null;
        }

        return ( new \DateTimeImmutable( '@' . (int) $when ) )->setTimezone( $tz );
    }
}
