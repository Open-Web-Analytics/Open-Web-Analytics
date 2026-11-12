<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//


namespace OWA\Module\Base\Entity;

/**
 * User Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class User extends \OWA\Core\Entity {
    
    const ADMIN_USER_REAL_NAME = 'default admin';
    const ADMIN_USER_ROLE = 'admin';

    /** The shortest password any screen or command accepts. */
    const MIN_PASSWORD_LENGTH = 6;

    /**
     * How long the link in a new account's email can set its password.
     */
    const INVITE_TTL = 604800; // 7 days

    /**
     * How long the link in a password reset email can set a new password.
     */
    const RESET_TTL = 86400; // 1 day

    /**
     * A temp passkey: 32 random hex characters, a dot, and the Unix time it
     * expires. The link carries the whole key and must match the stored value
     * exactly, so the expiry in it cannot be changed. A key without one -- every
     * key minted before keys expired -- is expired.
     */
    const TEMP_PASSKEY_PATTERN = '/^([a-f0-9]{32})\\.([0-9]{1,10})$/';

    /**
     * Credential fields. These are never part of a response payload.
     */
    protected $private_properties = [ 'password', 'temp_passkey', 'api_key' ];

    function __construct() {
    
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
         * needs to ask it (PLAN.html §3.5).
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
        
        $apiKey = new \OWA\Module\Base\Classes\DbColumn;
        $apiKey->setName('api_key');
        $apiKey->setDataType(OWA_DTD_VARCHAR255);
        $this->setProperty($apiKey);

    }
    
    /**
     * An account given no password is an invitation: nobody can sign in to it
     * until its owner sets a password from the link in the new account email,
     * which works for INVITE_TTL.
     *
     * Its stored hash is of random bytes nobody is told. It used to be the hash
     * of a "generated" password that was the first six characters of a hash,
     * "$2y$10", and so the same for every such account (Auth::isUser() refuses
     * that value now).
     */
    function createNewUser($user_id, $role, $password = '', $email_address = '', $real_name = '') {

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
        $this->set('api_key', self::newApiKey() );
        $ret = $this->create();

        return $ret;
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
     */
    public static function tempPasskeyIsCurrent( $key ): bool {

        return is_string( $key )
            && preg_match( self::TEMP_PASSKEY_PATTERN, $key, $m ) === 1
            && (int) $m[2] > time();
    }

    /** A well-formed temp passkey, current or not, or false. */
    public static function cleanTempPasskey( $key ) {

        return is_string( $key ) && preg_match( self::TEMP_PASSKEY_PATTERN, $key ) === 1 ? $key : false;
    }

    /** A legacy API key: 32 random hex characters. */
    public static function newApiKey(): string {

        return bin2hex( random_bytes( 16 ) );
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

?>
