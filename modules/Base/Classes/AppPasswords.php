<?php
namespace OWA\Module\Base\Classes;

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
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 */
class AppPasswords {

    const LOOKUP_LENGTH = 12;

    const SECRET_LENGTH = 32;

    const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    /** The READ_METHODS only. */
    const SCOPE_READ = 'read';

    /** The request methods a SCOPE_READ password is accepted on (Core\Auth). */
    const READ_METHODS = [ 'GET', 'HEAD' ];

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

        return [
            self::SCOPE_READ => \OWA\Core\CoreAPI::t( 'Read only' ),
            self::SCOPE_FULL => \OWA\Core\CoreAPI::t( 'Read and write' ),
        ];
    }

    /**
     * Make a password for a user.
     *
     * The token is in the answer and nowhere else: it cannot be shown again.
     *
     * @param  string $user_id
     * @param  string $name
     * @param  string $scope SCOPE_READ or SCOPE_FULL. Read when not given, as
     *                       the dialog's first choice and cmd=app-password-create are.
     * @return array{token: string, lookup_id: string, name: string, scope: string, created_at: int}|false
     */
    public static function create( $user_id, $name, $scope = self::SCOPE_READ ) {

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
                [ \OWA\Core\Lib::randomId(), $lookup, $user_id, $name,
                       self::hash( $token ), $scope, $now ] );

            if ( $inserted !== false ) {

                return [
                    'token'      => $token,
                    'lookup_id'  => $lookup,
                    'name'       => $name,
                    'scope'      => $scope,
                    'created_at' => $now,
                ];
            }
        }

        return false;
    }

    /**
     * The password a token belongs to, or null.
     *
     * @param  string $token
     * @return Row|null the row, without its hash
     */
    public static function verify( $token ) {

        $token = (string) $token;

        if ( ! self::looksLikeToken( $token ) || ! self::key() ) {

            return null;
        }

        $row = self::db()->get_row( sprintf(
            'SELECT %s, secret_hash FROM %s WHERE lookup_id = ?', self::PUBLIC_COLUMNS, self::table() ),
            [ substr( $token, 0, self::LOOKUP_LENGTH ) ] );

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
     * @return list<Row> rows, without their hashes
     */
    public static function forUser( $user_id ) {

        if ( (string) $user_id === '' ) {

            return [];
        }

        return (array) self::db()->get_results( sprintf(
            'SELECT %s FROM %s WHERE user_id = ? ORDER BY created_at DESC, lookup_id', self::PUBLIC_COLUMNS, self::table() ),
            [ (string) $user_id ] );
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
        $params = [ (string) $lookup_id, (string) $user_id ];

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
            [ (string) $user_id ] ) !== false;
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
            [ $now, substr( (string) $ip, 0, 45 ), (string) $lookup_id, $now - self::USE_THROTTLE ] );
    }

    /**
     * The table: the entity's, or a test's scratch copy.
     *
     * @return string
     */
    public static function table() {

        return self::$table ?: \OWA\Core\CoreAPI::entityFactory( 'base.app_password' )->getTableName();
    }

    /** What a revoke link's key is an HMAC of, before the password's. */
    const REVOKE_CONTEXT = 'owa-app-password-revoke:';

    /**
     * The key of the link that revokes $row, as the creation e-mail carries
     * it (announce()): an HMAC of its lookup id, user and creation time. Used
     * once, as revoking removes the row.
     *
     * @param  array<string, mixed> $row lookup_id, user_id, created_at
     * @return string
     */
    public static function revokeKey( array $row ) {

        return hash_hmac( 'sha256', self::REVOKE_CONTEXT . $row['lookup_id'] . '|' . $row['user_id'] . '|' . (int) $row['created_at'], self::key() );
    }

    /**
     * The password a revoke link names, when its key is that password's
     * (revokeKey()); null otherwise, or when it is gone.
     *
     * @param  string $lookup_id
     * @param  string $key
     * @return Row|null without its hash
     */
    public static function forRevokeLink( $lookup_id, $key ) {

        if ( ! self::key() || ! is_string( $key ) || $key === '' ) {

            return null;
        }

        $row = self::db()->get_row( sprintf( 'SELECT %s FROM %s WHERE lookup_id = ?', self::PUBLIC_COLUMNS, self::table() ),
            [ (string) $lookup_id ] );

        return $row && hash_equals( self::revokeKey( (array) $row ), $key ) ? (array) $row : null;
    }

    /**
     * Tell the account a password was made for it: an e-mail with what was
     * made, from where, and a link that revokes it signed out
     * (base.appPasswordRevokeLink). When the mail is not sent, a notice in
     * the account's bell instead.
     *
     * @param  array<string, mixed> $made create()'s answer
     * @param  string               $user_id
     * @param  string               $from     the client address, or '' for the command line
     * @return bool whether the mail was sent
     */
    public static function announce( array $made, $user_id, $from ) {

        $made += [ 'user_id' => (string) $user_id ];

        $sent = \OWA\Core\CoreAPI::performAction( 'base.appPasswordCreatedMail', [
            'made' => $made, 'from' => (string) $from, 'key' => self::revokeKey( $made ) ] ) === true;

        if ( ! $sent ) {

            NotificationManager::record( [ [
                'source_key'   => (string) $made['lookup_id'],
                'title'        => sprintf( \OWA\Core\CoreAPI::t( 'An application password, %s, was made for your account.' ), $made['name'] ),
                'body'         => \OWA\Core\CoreAPI::t( 'If you did not make it, revoke it under My application passwords.' ),
                'url'          => '',
                'published_at' => (int) $made['created_at'],
            ] ], 'app_password', (string) $user_id );
        }

        return $sent;
    }

    /**
     * After a password change, put the account's application passwords in
     * front of it: they keep working (they are not revoked by a change), so
     * one made by someone who had the account would too. A notice in the
     * account's bell, linking to the list, when it has any.
     *
     * @param  string $user_id
     * @return bool whether a notice was raised
     */
    public static function askForReview( $user_id ) {

        $count = count( self::forUser( (string) $user_id ) );

        if ( ! $count ) {

            return false;
        }

        $now = time();

        return NotificationManager::record( [ [
            'source_key'   => (string) $user_id . ':' . $now,
            'title'        => sprintf( \OWA\Core\CoreAPI::tn(
                'Your password was changed. Review your %d application password.',
                'Your password was changed. Review your %d application passwords.', $count ), $count ),
            'body'         => \OWA\Core\CoreAPI::t( 'Application passwords keep working after a password change. Under My application passwords, revoke any you do not recognise.' ),
            'url'          => rtrim( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ), '/' ) . '/index.php?owa_do=base.myAppPasswords',
            'published_at' => $now,
        ] ], 'app_password_review', (string) $user_id ) > 0;
    }

    /**
     * HMAC of a whole token, hex.
     *
     * @param  string $token
     * @return string
     */
    private static function hash( $token ) {

        return hash_hmac( 'sha256', self::HMAC_CONTEXT . $token, self::key() );
    }

    /**
     * The HMAC key. Empty on an install with no auth key, and then nothing is
     * created or verified: an HMAC with an empty key protects nothing.
     *
     * @return string
     */
    private static function key() {

        return (string) \OWA\Core\CoreAPI::getSalt( 'auth' );
    }

    /**
     * $length characters from ALPHABET, each uniformly at random.
     *
     * @param  int $length
     * @return string
     */
    private static function randomString( $length ) {

        $max = strlen( self::ALPHABET ) - 1;
        $out = '';

        for ( $i = 0; $i < $length; $i++ ) {

            $out .= self::ALPHABET[ random_int( 0, $max ) ];
        }

        return $out;
    }

    /**
     * @return \OWA\Core\Db
     */
    private static function db() {

        return \OWA\Core\CoreAPI::dbSingleton();
    }
}
