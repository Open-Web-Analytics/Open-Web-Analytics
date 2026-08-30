<?php
namespace OWA\Module\Base\Classes;


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

/**
 * Abstract Install Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class InstallManager extends \OWA\Core\Base {

    function __construct($params = '') {

        return parent::__construct($params);
    }

    function installSchema() {

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $base = $service->getModule('base');
        $status = $base->install();
        return $status;

    }

    function createAdminUser($user_id, $email_address, $password = '') {

        //create user entity
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        // check to see if an admin user already exists
        $u->getByColumn('role', 'admin');
        $id_check = $u->get('id');
        // if not then proceed
        if (empty($id_check)) {

            //Check to see if user name already exists
            $u->getByColumn('user_id', $user_id);

            $id = $u->get('id');

            // Set user object Params
            if (empty($id)) {

                if ( ! $password ) {

                    $password = $u->generateRandomPassword();
                }

                $ret = $u->createNewUser($user_id, \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE, $password, $email_address, \OWA\Module\Base\Entity\User::ADMIN_USER_REAL_NAME);
                \OWA\Core\CoreAPI::debug("Admin user created successfully.");
                return $password;

            } else {
                \OWA\Core\CoreAPI::debug($this->getMsgAsString(3306));
            }
        } else {
            \OWA\Core\CoreAPI::debug("Admin user already exists.");
        }

    }

    /**
     * Ensure the install's default site exists, and return its site_id.
     *
     * Idempotent, as before, but no longer because the identifier is a function
     * of the domain. It used to derive md5( $domain ) and look THAT up, so a
     * re-run recognised the site only by arriving at the same identifier;
     * minting identifiers breaks that, and the domain is looked up directly
     * instead.
     *
     * A caller may still pin an identifier by passing $site_id -- the install
     * wizard does -- and in that case the pinned value is what gets looked up,
     * so pinning stays idempotent on its own terms.
     */
    function createDefaultSite($domain, $name = '', $description = '', $site_family = '', $site_id = '') {

        if (!$name) {
            $name = $domain;
        }

        $this->e->notice('Checking for existence of default site.');

        $existing = \OWA\Core\CoreAPI::entityFactory('base.site');

        if ($site_id) {
            $existing->load($site_id, 'site_id');
        } else {
            $existing->load($domain, 'domain');
        }

        if ($existing->wasPersisted()) {

            $this->e->notice(sprintf(
                "Default site already exists (id = %s). nothing to do here.", $existing->get('id')));

            return $existing->get('site_id');
        }

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');

        if (!$site_id) {
            $site_id = $site->mintSiteId();
        }

        $site->set('id', $site->generateId($site_id));
        $site->set('site_id', $site_id);
        $site->set('name', $name);
        $site->set('description', $description);
        $site->set('domain', $domain);
        $site->set('site_family', $site_family);

        if ($site->create()) {
            $this->e->notice('Created default site.');
        } else {
            $this->e->notice('Creation of default site failed.');
        }

        return $site->get('site_id');
    }

    function checkDbConnection() {

        // Check DB connection status
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->connect();
        if ($db->connection_status === true) {
            return true;
        } else {
            return false;
        }
    }
    
    function isInstallComplete() {
        
        // is config file present?
        $c = \OWA\Core\CoreAPI::configSingleton();
        if ( ! $c->isConfigFileLoaded() ) {
            
            return false;
        }
        
        // can DB connection be made?
        $db = \OWA\Core\CoreAPI::dbSingleton();
        if ( ! $db->connect() ) {
            
            return false;
        }
        
        // is base schema installed?
        if ( ! $this->isBaseSchemaInstalled() ) {
            
            return false;
        } else {
            
            \OWA\Core\CoreAPI::debug('base schema install check passed.');
        }
        
        // load config from DB
        $c->load( $this->c->get( 'base', 'configuration_id' ) );
        
        // is the install flag set
        if ( ! \OWA\Core\CoreAPI::getSetting('base', 'install_complete') ) {
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Check to see if schema is installed
     *
     * @return boolean
     */
    function isBaseSchemaInstalled() {
        
        $base_module_tables = ['user'];
        $tables_missing = [];
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
       
        // test for base tables
        foreach ( $base_module_tables as $table ) {
            
            \OWA\Core\CoreAPI::debug('Testing for existence of table: '. $table);
            
            $check = $db->get_results(sprintf(OWA_SQL_SHOW_TABLE, \OWA\Core\CoreAPI::getSetting('base', 'ns') . $table ) );
           
            // if a table is missing add it to this array
            if (empty($check)) {
            
                $tables_missing[] = $table;
                
                \OWA\Core\CoreAPI::debug('Did not find table: '. $table);
            }
        }
    
        if ( $tables_missing ) {
           
            \OWA\Core\CoreAPI::debug(sprintf("Base Schema is missing tables: %s", print_r($tables_missing, true)));
    
            return false;
            
        } else {
            
            return true;
        }
    }
}

?>