<?php

namespace OWA\Module\Base\Entity;

/**
 * User Entity
 */

class User extends \OWA\Core\Entity {
    
    const ADMIN_USER_REAL_NAME = 'default admin';
    const ADMIN_USER_ROLE = 'admin';

    /** The shortest password any screen or command accepts. */
    const MIN_PASSWORD_LENGTH = 6;

    /**
     * The longest password any screen or command accepts, in bytes: bcrypt
     * (Lib::encryptPassword()) reads no further. Hashes already stored are
     * not affected.
     */
    const MAX_PASSWORD_BYTES = 72;

    /**
     * How long the link in a new account's email can set its password.
     */
    const INVITE_TTL = 604800; // 7 days

    /**
     * How long the link in a password reset email can set a new password.
     */
    const RESET_TTL = 86400; // 1 day

    /**
     * How long after a password reset email another request for the same
     * account sends nothing (resetMailedRecently()).
     */
    const RESET_RESEND_AFTER = 900; // 15 minutes

    /**
     * A temp passkey: 32 random hex characters, a dot, and the Unix time it
     * expires. The link carries the whole key and must match the stored value
     * exactly, so the expiry in it cannot be changed. A key without one -- every
     * key minted before keys expired -- is expired.
     */
    const TEMP_PASSKEY_PATTERN = '/^([a-f0-9]{32})\\.([0-9]{1,10})$/';

    /**
     * Credential fields. These are never part of a response payload.
     *
     * @var list<string>
     */
    protected $private_properties = [ 'password', 'temp_passkey', 'session_epoch' ];

    public function __construct() {
    
        $this->setTableName('user');
        
        // properties
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_SERIAL);
        $this->properties['user_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['user_id']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['user_id']->setPrimaryKey();
        $this->properties['password'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['password']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['role'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['role']->setDataType(OWA_DTD_VARCHAR255);

        /*
         * The organization this account belongs to. Roles are scoped to it.
         *
         * One per user for now: multi-organization membership carries a real
         * question -- which organization am I acting in? -- and nothing yet
         * needs to ask it.
         */
        $this->properties['organization_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['organization_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['real_name'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['real_name']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['email_address'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['email_address']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['temp_passkey'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['temp_passkey']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['creation_date'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['creation_date']->setDataType(OWA_DTD_BIGINT);
        $this->properties['last_update_date'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['last_update_date']->setDataType(OWA_DTD_BIGINT);
        
        // Signed into every session cookie (Auth::sessionCredential()); a new
        // value ends them all. See endSessions(). Update087.
        $epoch = new \OWA\Module\Base\Classes\DbColumn;
        $epoch->setName('session_epoch');
        $epoch->setDataType(OWA_DTD_INT);
        $epoch->setNotNull();
        $epoch->setDefaultValue(0);
        $this->setProperty($epoch);

    }
    
    /**
     * An account given no password is an invitation: nobody can sign in to it
     * until its owner sets a password from the link in the new account email,
     * which works for INVITE_TTL.
     *
     * Its stored hash is of random bytes nobody is told. Auth::isUser() refuses
     * the values the previous generator gave.
     *
     * @param  string $user_id
     * @param  string $role
     * @param  string $password
     * @param  string $email_address
     * @param  string $real_name
     * @return mixed Entity::create()'s result, the driver's: false on failure
     */
    public function createNewUser($user_id, $role, $password = '', $email_address = '', $real_name = '') {

        // The screens and commands refuse it first, with a message.
        if ( self::passwordIsTooLong( $password ) ) {

            \OWA\Core\CoreAPI::error( sprintf( 'User %s not created: the password is longer than %d bytes.', $user_id, self::MAX_PASSWORD_BYTES ) );

            return false;
        }

        if ( ! $password ) {

            $password = bin2hex( random_bytes( 32 ) );
        }

        $this->set('user_id', $user_id);
        $this->set('role', $role);
        $this->set('real_name', $real_name);
        $this->set('email_address', $email_address);
        $this->set('temp_passkey', self::newTempPasskey( self::INVITE_TTL ) );
        $this->set('password', \OWA\Core\Lib::encryptPassword($password));
        $this->set('creation_date', time());
        $this->set('last_update_date', time());
        $ret = $this->create();

        return $ret;
    }

    /**
     * Whether a password is too long to set (MAX_PASSWORD_BYTES).
     *
     * @param  mixed $password as the request carried it
     */
    public static function passwordIsTooLong( $password ): bool {

        return is_string( $password ) && strlen( $password ) > self::MAX_PASSWORD_BYTES;
    }

    /**
     * The refusal of a password longer than MAX_PASSWORD_BYTES.
     *
     * @param  bool $translated false for the command line, which is not translated
     */
    public static function passwordTooLongMessage( bool $translated = true ): string {

        $text = $translated
            ? \OWA\Core\CoreAPI::t( 'The password must be at most %d bytes. An accented or non-Latin letter takes 2 to 4.' )
            : 'The password must be at most %d bytes. An accented or non-Latin letter takes 2 to 4.';

        return sprintf( $text, self::MAX_PASSWORD_BYTES );
    }

    /** A temp passkey that sets a password until $ttl seconds from now. */
    public static function newTempPasskey( int $ttl ): string {

        return bin2hex( random_bytes( 16 ) ) . '.' . ( time() + $ttl );
    }

    /**
     * A temp passkey that sets nothing: what an account holds once its password
     * has been set, so the link that set it cannot be used again.
     *
     * Not an empty value: the password change finds its account by key, and an
     * empty key would find every account that had one.
     */
    public static function spentTempPasskey(): string {

        return bin2hex( random_bytes( 16 ) ) . '.0';
    }

    /**
     * Whether a temp passkey is well formed and not yet expired. Says nothing
     * about whether any account holds it.
     *
     * @param  mixed $key as the request carried it: only a string can be one
     */
    public static function tempPasskeyIsCurrent( $key ): bool {

        return is_string( $key )
            && preg_match( self::TEMP_PASSKEY_PATTERN, $key, $m ) === 1
            && (int) $m[2] > time();
    }

    /**
     * Whether a temp passkey is a reset key minted less than
     * RESET_RESEND_AFTER seconds ago.
     *
     * Read from the key's expiry, which is its minting time plus RESET_TTL:
     * nothing else records when a reset was sent. An invitation key, which
     * lives INVITE_TTL, reads as recent only in the RESET_RESEND_AFTER seconds
     * when it has exactly RESET_TTL left.
     *
     * @param  mixed $key as stored or carried: only a string can be one
     */
    public static function resetMailedRecently( $key, ?int $now = null ): bool {

        $now ??= time();

        if ( ! is_string( $key ) || preg_match( self::TEMP_PASSKEY_PATTERN, $key, $m ) !== 1 ) {

            return false;
        }

        $minted = (int) $m[2] - self::RESET_TTL;

        return $minted <= $now && $minted > $now - self::RESET_RESEND_AFTER;
    }

    /**
     * A well-formed temp passkey, current or not, or false.
     *
     * @param  mixed $key as the request carried it: only a string can be one
     * @return string|false
     */
    public static function cleanTempPasskey( $key ) {

        return is_string( $key ) && preg_match( self::TEMP_PASSKEY_PATTERN, $key ) === 1 ? $key : false;
    }

    
    /**
     * Ends every browser session of this user, on every device, from the next
     * request: each session cookie carries the epoch it was issued under.
     * Written by the caller's update().
     *
     * @return void
     */
    public function endSessions() {

        $this->set( 'session_epoch', (int) $this->get( 'session_epoch' ) + 1 );
    }

    /**
     * @return boolean
     */
    public function isOWAAdmin() {
        if ( $this->get('real_name') == self::ADMIN_USER_REAL_NAME ) {
            return true;
        } else {
            return false;
        }
    }
    
    /**
     * @return boolean
     */
    public function isAdmin() {
        if ( $this->get('role') == self::ADMIN_USER_ROLE ) {
            return true;
        } else {
            return false;
        }
    }
    
}
