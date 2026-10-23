<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Application passwords: named, revocable REST API credentials, any number
 * per user.
 *
 * A token is LOOKUP_LENGTH + SECRET_LENGTH base62 characters with nothing
 * around them. The first part is stored as written and says which row to
 * check against; the whole token is stored only as an HMAC. It is never 32
 * hex characters, so it cannot be mistaken for a legacy apiKey.
 *
 * HMAC rather than a slow password hash: the secret is ~190 random bits, so
 * there is nothing for a slow hash to protect, and it would add its cost to
 * every API request. The key is the install's auth key and salt, so
 * `cmd=reset-secrets` invalidates every application password.
 *
 * Its own SQL, not the entity layer, so a revoked password is never served
 * from the entity cache.
 */
class AppPasswords {

    const LOOKUP_LENGTH = 12;

    const SECRET_LENGTH = 32;

    const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** GET only. */
    const SCOPE_READ = 'read';

    /** Whatever the user's role allows. */
    const SCOPE_FULL = 'full';

    /** Matches Entity\AppPassword's name column. */
    const NAME_LENGTH = 255;

    /** last_used_at is written at most this often per password, in seconds. */
    const USE_THROTTLE = 60;

    /** Separates this HMAC from any other made with the same key. */
    const HMAC_CONTEXT = 'owa-app-password:';

    /** Columns safe to hand back: everything but the hash. */
    const PUBLIC_COLUMNS = 'lookup_id, user_id, name, scope, created_at, last_used_at, last_used_ip';

    /** @var string|null a table to use instead of the install's. TESTS ONLY. */
    public static $table = null;

    /** @return string[] scope => what a person is shown */
    public static function scopes() {

        return array(
            self::SCOPE_READ => 'Read only',
            self::SCOPE_FULL => 'Read and write',
        );
    }

    /**
     * Make a password for a user.
     *
     * The token is in the answer and nowhere else: it cannot be shown again.
     *
     * @param  string $user_id
     * @param  string $name
     * @param  string $scope SCOPE_READ or SCOPE_FULL
     * @return array|false token, lookup_id, name, scope, created_at
     */
    public static function create( $user_id, $name, $scope = self::SCOPE_FULL ) {

        $user_id = (string) $user_id;
        $name    = trim( (string) $name );

        if ( $user_id === '' || $name === '' || ! isset( self::scopes()[ $scope ] ) || ! self::key() ) {

            return false;
        }

        $name = mb_substr( $name, 0, self::NAME_LENGTH );
        $now  = time();

        // A lookup id is 71 random bits; a second collision is not a case to plan for.
        for ( $try = 0; $try < 2; $try++ ) {

            $token  = self::randomString( self::LOOKUP_LENGTH + self::SECRET_LENGTH );
            $lookup = substr( $token, 0, self::LOOKUP_LENGTH );

            $inserted = self::db()->query( sprintf(
                'INSERT INTO %s (id, lookup_id, user_id, name, secret_hash, scope, created_at)'
              . ' VALUES (?, ?, ?, ?, ?, ?, ?)', self::table() ),
                array( (string) random_int( 1, PHP_INT_MAX ), $lookup, $user_id, $name,
                       self::hash( $token ), $scope, $now ) );

            if ( $inserted !== false ) {

                return array(
                    'token'      => $token,
                    'lookup_id'  => $lookup,
                    'name'       => $name,
                    'scope'      => $scope,
                    'created_at' => $now,
                );
            }
        }

        return false;
    }

    /**
     * The password a token belongs to, or null.
     *
     * @param  string $token
     * @return array|null the row, without its hash
     */
    public static function verify( $token ) {

        $token = (string) $token;

        if ( ! self::looksLikeToken( $token ) || ! self::key() ) {

            return null;
        }

        $row = self::db()->get_row( sprintf(
            'SELECT %s, secret_hash FROM %s WHERE lookup_id = ?', self::PUBLIC_COLUMNS, self::table() ),
            array( substr( $token, 0, self::LOOKUP_LENGTH ) ) );

        if ( ! is_array( $row ) || ! hash_equals( (string) $row['secret_hash'], self::hash( $token ) ) ) {

            return null;
        }

        unset( $row['secret_hash'] );

        return $row;
    }

    /**
     * Is this shaped like a token? Says nothing about whether it is one.
     *
     * @param  string $value
     * @return bool
     */
    public static function looksLikeToken( $value ) {

        return (bool) preg_match(
            sprintf( '/^[0-9A-Za-z]{%d}$/D', self::LOOKUP_LENGTH + self::SECRET_LENGTH ), (string) $value );
    }

    /**
     * A user's passwords, newest first.
     *
     * @param  string $user_id
     * @return array[] rows, without their hashes
     */
    public static function forUser( $user_id ) {

        if ( (string) $user_id === '' ) {

            return array();
        }

        return (array) self::db()->get_results( sprintf(
            'SELECT %s FROM %s WHERE user_id = ? ORDER BY created_at DESC, lookup_id', self::PUBLIC_COLUMNS, self::table() ),
            array( (string) $user_id ) );
    }

    /**
     * Revoke one of a user's passwords.
     *
     * The user is part of the match, so an id belonging to someone else
     * revokes nothing.
     *
     * @param  string $lookup_id
     * @param  string $user_id
     * @return bool whether there was such a password
     */
    public static function revoke( $lookup_id, $user_id ) {

        if ( (string) $lookup_id === '' || (string) $user_id === '' ) {

            return false;
        }

        $db     = self::db();
        $params = array( (string) $lookup_id, (string) $user_id );

        if ( ! $db->get_row( sprintf( 'SELECT lookup_id FROM %s WHERE lookup_id = ? AND user_id = ?', self::table() ), $params ) ) {

            return false;
        }

        return $db->query( sprintf( 'DELETE FROM %s WHERE lookup_id = ? AND user_id = ?', self::table() ), $params ) !== false;
    }

    /**
     * Revoke every password a user has.
     *
     * @param  string $user_id
     * @return bool
     */
    public static function revokeAllForUser( $user_id ) {

        if ( (string) $user_id === '' ) {

            return false;
        }

        return self::db()->query( sprintf( 'DELETE FROM %s WHERE user_id = ?', self::table() ),
            array( (string) $user_id ) ) !== false;
    }

    /**
     * Note that a password was used.
     *
     * At most once per USE_THROTTLE, and the condition is in the UPDATE, so a
     * busy client costs a write a minute rather than one per request.
     *
     * @param  string   $lookup_id
     * @param  string   $ip
     * @param  int|null $now
     * @return void
     */
    public static function recordUse( $lookup_id, $ip, $now = null ) {

        $now = $now === null ? time() : (int) $now;

        self::db()->query( sprintf(
            'UPDATE %s SET last_used_at = ?, last_used_ip = ? WHERE lookup_id = ?'
          . ' AND (last_used_at IS NULL OR last_used_at <= ?)', self::table() ),
            array( $now, substr( (string) $ip, 0, 45 ), (string) $lookup_id, $now - self::USE_THROTTLE ) );
    }

    /** The table: the entity's, or a test's scratch copy. */
    public static function table() {

        return self::$table ?: \OWA\Core\CoreAPI::entityFactory( 'base.app_password' )->getTableName();
    }

    /** HMAC of a whole token, hex. */
    private static function hash( $token ) {

        return hash_hmac( 'sha256', self::HMAC_CONTEXT . $token, self::key() );
    }

    /**
     * The HMAC key. Empty on an install with no auth key, and then nothing is
     * created or verified: an HMAC with an empty key protects nothing.
     */
    private static function key() {

        return (string) \OWA\Core\CoreAPI::getSalt( 'auth' );
    }

    /** $length characters from ALPHABET, each uniformly at random. */
    private static function randomString( $length ) {

        $max = strlen( self::ALPHABET ) - 1;
        $out = '';

        for ( $i = 0; $i < $length; $i++ ) {

            $out .= self::ALPHABET[ random_int( 0, $max ) ];
        }

        return $out;
    }

    private static function db() {

        return \OWA\Core\CoreAPI::dbSingleton();
    }
}

?>
