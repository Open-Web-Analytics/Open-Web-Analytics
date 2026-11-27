<?php
namespace OWA\Module\Base\Classes;

/**
 * Failed sign-ins, counted per user name and per client address.
 *
 * maxFailures() failures counted within window() of the first refuse every
 * further sign-in by that name, or from that address, until window() after
 * the last of them: by default 5, and 15 minutes, set by
 * OWA_LOGIN_THROTTLE_MAX_FAILURES and OWA_LOGIN_THROTTLE_WINDOW. A limit
 * of 0 counts and refuses nothing. A refused attempt is not checked and not counted. The
 * refusal is the answer a wrong password gets. A successful sign-in, and a
 * password change, clear the name's count; the address's stays.
 *
 * The login form counts both (Core\Auth::authByInput()), and counts each
 * attempt BEFORE the password is checked (attempt()): attempts made at
 * once each see the others, so no more than maxFailures() are checked. The
 * outcome then settles it: failed() keeps it counted, succeeded() takes it
 * back. An application password counts its address only, once it has failed
 * (Core\Auth::authByAppPassword()).
 *
 * Stored in owa_login_failure (Entity\LoginFailure). When the table cannot be
 * read nothing is refused.
 */
final class LoginThrottle {

    /** The default of login_throttle_max_failures: failures that refuse further sign-ins. */
    const MAX_FAILURES = 5;

    /**
     * The default of login_throttle_window, in seconds: how long after the
     * first failure the next ones count with it, and how long after the last
     * sign-ins are refused.
     */
    const WINDOW = 900; // 15 minutes

    /**
     * Failures that refuse further sign-ins (OWA_LOGIN_THROTTLE_MAX_FAILURES);
     * 0 for no limit.
     *
     * @return int
     */
    public static function maxFailures() {

        return max( 0, (int) \OWA\Core\CoreAPI::getSetting( 'base', 'login_throttle_max_failures' ) );
    }

    /**
     * The window, in seconds (OWA_LOGIN_THROTTLE_WINDOW).
     *
     * @return int
     */
    public static function window() {

        return max( 1, (int) \OWA\Core\CoreAPI::getSetting( 'base', 'login_throttle_window' ) );
    }

    /** @return bool whether anything is counted or refused */
    private static function on() {

        return self::$enabled && self::maxFailures() > 0;
    }

    /**
     * False: nothing is counted or refused. The test suite's bootstrap turns
     * it off, as every test signs in from one address.
     *
     * @var bool
     */
    public static $enabled = true;

    /**
     * Rows kept here instead of owa_login_failure, when set: subject => row.
     *
     * @var array<string, array{failures: int, first_failure_at: int, last_failure_at: int}>|null
     */
    public static $rows = null;

    /**
     * The subjects counted for a sign-in: its user name and its client
     * address, either left out when empty.
     *
     * @param  mixed  $user_id as the request carried it
     * @param  string $address Core\ClientAddress::fromRequest()
     * @return list<string>
     */
    public static function keys( $user_id, $address ) {

        return array_values( array_filter( [ self::nameKey( $user_id ), self::addressKey( $address ) ] ) );
    }

    /**
     * @param  mixed $user_id
     * @return string '' for no name
     */
    public static function nameKey( $user_id ) {

        $name = is_scalar( $user_id ) ? strtolower( trim( (string) $user_id ) ) : '';

        return $name === '' ? '' : hash( 'sha256', 'name:' . $name );
    }

    /**
     * @param  mixed $address
     * @return string '' for no address
     */
    public static function addressKey( $address ) {

        $address = is_scalar( $address ) ? trim( (string) $address ) : '';

        return $address === '' ? '' : hash( 'sha256', 'address:' . strtolower( $address ) );
    }

    /**
     * Whether a row refuses sign-ins at $now.
     *
     * @param  array<string, mixed> $row failures, first_failure_at, last_failure_at
     * @param  int $now
     * @return bool
     */
    public static function isLockedRow( array $row, $now ) {

        return (int) ( $row['failures'] ?? 0 ) >= self::maxFailures()
            && (int) ( $row['last_failure_at'] ?? 0 ) > $now - self::window();
    }

    /**
     * Whether a row's failures no longer count at $now: its window has passed
     * and it refuses nothing.
     *
     * @param  array<string, mixed> $row
     * @param  int $now
     * @return bool
     */
    public static function isStaleRow( array $row, $now ) {

        return (int) ( $row['first_failure_at'] ?? 0 ) <= $now - self::window() && ! self::isLockedRow( $row, $now );
    }

    /**
     * Whether any of $keys refuses a sign-in now.
     *
     * @param  list<string> $keys keys()
     * @param  int|null     $now
     * @return bool
     */
    public static function isLocked( array $keys, $now = null ) {

        if ( ! self::on() || ! $keys ) {

            return false;
        }

        $now ??= time();

        foreach ( self::read( $keys ) as $row ) {

            if ( self::isLockedRow( $row, $now ) ) {

                return true;
            }
        }

        return false;
    }

    /**
     * Count a sign-in attempt against each of $keys before its password is
     * checked, and say whether it may be checked: false when any count, this
     * attempt included, is over maxFailures(). A refused attempt is taken back
     * at once. One that may be checked is settled by failed() or succeeded().
     *
     * The raise is one statement, so attempts made at the same moment each
     * count the others.
     *
     * @param  list<string> $keys keys()
     * @param  int|null     $now
     * @return bool
     */
    public static function attempt( array $keys, $now = null ) {

        if ( ! self::on() || ! $keys ) {

            return true;
        }

        $now ??= time();

        self::prune( $now );
        self::addOne( $keys, $now );

        foreach ( self::read( $keys ) as $row ) {

            if ( (int) ( $row['failures'] ?? 0 ) > self::maxFailures() ) {

                self::subtractOne( $keys );

                return false;
            }
        }

        return true;
    }

    /**
     * The attempt() that was checked failed: it stays counted, and the
     * refusal runs from now.
     *
     * @param  list<string> $keys
     * @param  int|null     $now
     * @return void
     */
    public static function failed( array $keys, $now = null ) {

        if ( ! self::on() || ! $keys ) {

            return;
        }

        self::setLast( $keys, $now ?? time() );
    }

    /**
     * The attempt() that was checked succeeded: the name's count is cleared,
     * and the attempt is taken back from the address's.
     *
     * @param  string $name_key    nameKey()
     * @param  string $address_key addressKey()
     * @return void
     */
    public static function succeeded( $name_key, $address_key ) {

        self::clear( [ $name_key ] );

        if ( self::on() && $address_key !== '' ) {

            self::subtractOne( [ $address_key ] );
        }
    }

    /**
     * Count one failure against each of $keys, after the fact: for a
     * credential whose check comes first (an application password).
     *
     * @param  list<string> $keys
     * @param  int|null     $now
     * @return void
     */
    public static function recordFailure( array $keys, $now = null ) {

        if ( ! self::on() || ! $keys ) {

            return;
        }

        $now ??= time();

        self::prune( $now );
        self::addOne( $keys, $now );
        self::setLast( $keys, $now );
    }

    /**
     * Delete every row whose failures no longer count (isStaleRow()).
     *
     * @param  int $now
     * @return void
     */
    private static function prune( $now ) {

        if ( self::$rows !== null ) {

            foreach ( self::$rows as $key => $row ) {

                if ( self::isStaleRow( $row, $now ) ) {

                    unset( self::$rows[ $key ] );
                }
            }

            return;
        }

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'DELETE FROM %s WHERE first_failure_at <= ? AND (failures < ? OR last_failure_at <= ?)', self::table() ),
            [ $now - self::window(), self::maxFailures(), $now - self::window() ] );
    }

    /**
     * Raise each key's count by one, in one statement; a key with no row
     * starts at one, its first and last failure now.
     *
     * @param  list<string> $keys
     * @param  int $now
     * @return void
     */
    private static function addOne( array $keys, $now ) {

        if ( self::$rows !== null ) {

            foreach ( $keys as $key ) {

                if ( isset( self::$rows[ $key ] ) ) {

                    self::$rows[ $key ]['failures']++;

                } else {

                    self::$rows[ $key ] = [ 'failures' => 1, 'first_failure_at' => $now, 'last_failure_at' => $now ];
                }
            }

            return;
        }

        // The driver first: loading it is what defines the OWA_SQL_* constants.
        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $params = [];

        foreach ( $keys as $key ) {

            array_push( $params, $key, 1, $now, $now );
        }

        $params[] = 1;

        $db->query( sprintf( OWA_SQL_INSERT_OR_ADD, self::table(), 'subject, failures, first_failure_at, last_failure_at',
            implode( '), (', array_fill( 0, count( $keys ), '?, ?, ?, ?' ) ), 'subject', 'failures' ), $params );
    }

    /**
     * Lower each key's count by one, not below zero.
     *
     * @param  list<string> $keys
     * @return void
     */
    private static function subtractOne( array $keys ) {

        $keys = array_values( array_filter( $keys ) );

        if ( ! $keys ) {

            return;
        }

        if ( self::$rows !== null ) {

            foreach ( $keys as $key ) {

                if ( isset( self::$rows[ $key ] ) ) {

                    self::$rows[ $key ]['failures'] = max( 0, self::$rows[ $key ]['failures'] - 1 );
                }
            }

            return;
        }

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'UPDATE %s SET failures = failures - 1 WHERE failures > 0 AND subject IN (%s)', self::table(), self::placeholders( $keys ) ),
            $keys );
    }

    /**
     * @param  list<string> $keys
     * @param  int $now
     * @return void
     */
    private static function setLast( array $keys, $now ) {

        if ( self::$rows !== null ) {

            foreach ( $keys as $key ) {

                if ( isset( self::$rows[ $key ] ) ) {

                    self::$rows[ $key ]['last_failure_at'] = $now;
                }
            }

            return;
        }

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf(
            'UPDATE %s SET last_failure_at = ? WHERE subject IN (%s)', self::table(), self::placeholders( $keys ) ),
            array_merge( [ $now ], $keys ) );
    }

    /**
     * Forget the failures counted against $keys.
     *
     * @param  list<string> $keys
     * @return void
     */
    public static function clear( array $keys ) {

        $keys = array_values( array_filter( $keys ) );

        if ( ! self::on() || ! $keys ) {

            return;
        }

        if ( self::$rows !== null ) {

            foreach ( $keys as $key ) {

                unset( self::$rows[ $key ] );
            }

            return;
        }

        \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DELETE FROM %s WHERE subject IN (%s)', self::table(), self::placeholders( $keys ) ), $keys );
    }

    /**
     * @param  list<string> $keys
     * @return list<array<string, mixed>>
     */
    private static function read( array $keys ) {

        if ( self::$rows !== null ) {

            return array_values( array_intersect_key( self::$rows, array_flip( $keys ) ) );
        }

        $rows = \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            'SELECT subject, failures, first_failure_at, last_failure_at FROM %s WHERE subject IN (%s)',
            self::table(), self::placeholders( $keys ) ), $keys );

        return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : [];
    }

    /**
     * @param  list<string> $keys
     * @return string
     */
    private static function placeholders( array $keys ) {

        return implode( ', ', array_fill( 0, count( $keys ), '?' ) );
    }

    /** @return string */
    private static function table() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.login_failure' )->getTableName();
    }
}
