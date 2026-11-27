<?php
namespace OWA\Module\Base\Classes;

/**
 * Failed sign-ins, counted per user name and per client address.
 *
 * MAX_FAILURES failures counted within WINDOW of the first refuse every
 * further sign-in by that name, or from that address, until LOCKOUT after
 * the last of them. A refused attempt is not checked and not counted. The
 * refusal is the answer a wrong password gets. A successful sign-in, and a
 * password change, clear the name's count; the address's stays.
 *
 * The login form counts both (Core\Auth::authByInput()); an application
 * password counts its address only (Core\Auth::authByAppPassword()).
 *
 * Stored in owa_login_failure (Entity\LoginFailure). When the table cannot be
 * read nothing is refused.
 */
final class LoginThrottle {

    /** Failures that refuse further sign-ins. */
    const MAX_FAILURES = 5;

    /** How long after the first failure the next ones count with it. */
    const WINDOW = 900; // 15 minutes

    /** How long after the last failure sign-ins are refused. */
    const LOCKOUT = 900; // 15 minutes

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

        return (int) ( $row['failures'] ?? 0 ) >= self::MAX_FAILURES
            && (int) ( $row['last_failure_at'] ?? 0 ) > $now - self::LOCKOUT;
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

        return (int) ( $row['first_failure_at'] ?? 0 ) <= $now - self::WINDOW && ! self::isLockedRow( $row, $now );
    }

    /**
     * A row after one more failure at $now.
     *
     * @param  array<string, mixed>|null $row null for none
     * @param  int $now
     * @return array{failures: int, first_failure_at: int, last_failure_at: int}
     */
    public static function afterFailure( $row, $now ) {

        if ( ! $row || self::isStaleRow( $row, $now ) ) {

            return [ 'failures' => 1, 'first_failure_at' => $now, 'last_failure_at' => $now ];
        }

        return [
            'failures'         => (int) $row['failures'] + 1,
            'first_failure_at' => (int) $row['first_failure_at'],
            'last_failure_at'  => $now,
        ];
    }

    /**
     * Whether any of $keys refuses a sign-in now.
     *
     * @param  list<string> $keys keys()
     * @param  int|null     $now
     * @return bool
     */
    public static function isLocked( array $keys, $now = null ) {

        if ( ! self::$enabled || ! $keys ) {

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
     * Count one failure against each of $keys.
     *
     * Three statements, each one atomic: rows whose failures no longer count
     * are deleted, every row's count is raised by one or inserted at one, and
     * the time of the last failure is set.
     *
     * @param  list<string> $keys
     * @param  int|null     $now
     * @return void
     */
    public static function recordFailure( array $keys, $now = null ) {

        if ( ! self::$enabled || ! $keys ) {

            return;
        }

        $now ??= time();

        if ( self::$rows !== null ) {

            foreach ( self::$rows as $key => $row ) {

                if ( self::isStaleRow( $row, $now ) ) {

                    unset( self::$rows[ $key ] );
                }
            }

            foreach ( $keys as $key ) {

                self::$rows[ $key ] = self::afterFailure( self::$rows[ $key ] ?? null, $now );
            }

            return;
        }

        // The driver first: loading it is what defines the OWA_SQL_* constants.
        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = self::table();

        $db->query( sprintf( 'DELETE FROM %s WHERE first_failure_at <= ? AND (failures < ? OR last_failure_at <= ?)', $table ),
            [ $now - self::WINDOW, self::MAX_FAILURES, $now - self::LOCKOUT ] );

        $params = [];

        foreach ( $keys as $key ) {

            array_push( $params, $key, 1, $now, $now );
        }

        $params[] = 1;

        $db->query( sprintf( OWA_SQL_INSERT_OR_ADD, $table, 'subject, failures, first_failure_at, last_failure_at',
            implode( '), (', array_fill( 0, count( $keys ), '?, ?, ?, ?' ) ), 'subject', 'failures' ), $params );

        $db->query( sprintf( 'UPDATE %s SET last_failure_at = ? WHERE subject IN (%s)', $table, self::placeholders( $keys ) ),
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

        if ( ! self::$enabled || ! $keys ) {

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
