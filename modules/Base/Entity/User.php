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
    
    function createNewUser($user_id, $role, $password = '', $email_address = '', $real_name = '') {
    
        if (!$password) {
            $password = $this->generateRandomPassword();
        }
        
        $this->set('user_id', $user_id);
        $this->set('role', $role);
        $this->set('real_name', $real_name);
        $this->set('email_address', $email_address);
        $this->set('temp_passkey', $this->generateTempPasskey($user_id));
        $this->set('password', \OWA\Core\Lib::encryptPassword($password));
        $this->set('creation_date', time());
        $this->set('last_update_date', time());
        $this->set('api_key', $this->generateTempPasskey($user_id));
        $ret = $this->create();
        
        return $ret;
    }
    
    function generateTempPasskey($seed) {
        
        return md5($seed.time().rand());
    }
    
    function generateRandomPassword() {
        return substr(\OWA\Core\Lib::encryptPassword(microtime()),0,6);
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
    
     /**
     * @return boolean
     */
    public function isDefaultUser() {
	    
	    if ( $this->get('id') === 1 ) {
		    
		    return true;
	    }
    }
}

?>
