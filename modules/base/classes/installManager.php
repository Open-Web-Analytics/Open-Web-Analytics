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


class owa_installManager extends owa_base {

    function __construct($params = '') {

        return parent::__construct($params);
    }

    function installSchema() {

        $service = owa_coreAPI::serviceSingleton();
        $base = $service->getModule('base');
        $status = $base->install();
        return $status;

    }

    function createAdminUser($user_id, $email_address, $password = '') {

        //create user entity
        $u = owa_coreAPI::entityFactory('base.user');
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

                $ret = $u->createNewUser($user_id, owa_user::ADMIN_USER_ROLE, $password, $email_address, owa_user::ADMIN_USER_REAL_NAME);
                owa_coreAPI::debug("Admin user created successfully.");
                return $password;

            } else {
                owa_coreAPI::debug($this->getMsgAsString(3306));
            }
        } else {
            owa_coreAPI::debug("Admin user already exists.");
        }

    }

    function createDefaultSite($domain, $name = '', $description = '', $site_family = '', $site_id = '') {

        if (!$name) {
            $name = $domain;
        }

        $site = owa_coreAPI::entityFactory('base.site');

        if (!$site_id) {
            $site_id = $site->generateSiteId($domain);
        }


        // Check to see if default site already exists
        $this->e->notice('Checking for existence of default site.');

        // create site_id....how???
        $site->getByColumn('site_id', $site_id);
        $id = $site->get('id');

        if(empty($id)) {
            // Create default site
            $site->set('id', $site->generateId($site_id));
            $site->set('site_id', $site_id);
            $site->set('name', $name);
            $site->set('description', $description);
            $site->set('domain', $domain);
            $site->set('site_family', $site_family);
            $site_status = $site->create();

            if ($site_status == true) {
                $this->e->notice('Created default site.');
            } else {
                $this->e->notice('Creation of default site failed.');
            }

        } else {
            $this->e->notice(sprintf("Default site already exists (id = %s). nothing to do here.", $id));
        }

        return $site->get('site_id');
    }

    function checkDbConnection() {

        // Check DB connection status
        $db = owa_coreAPI::dbSingleton();
        $db->connect();
        if ($db->connection_status === true) {
            return true;
        } else {
            return false;
        }
    }
    
    function isInstallComplete() {
        
        // is config file present?
        $c = owa_coreAPI::configSingleton();
        if ( ! $c->isConfigFileLoaded() ) {
            
            return false;
        }
        
        // can DB connection be made?
        $db = owa_coreAPI::dbSingleton();
        if ( ! $db->connect() ) {
            
            return false;
        }
        
        // is base schema installed?
        if ( ! $this->isBaseSchemaInstalled() ) {
            
            return false;
        } else {
            
            owa_coreAPI::debug('base schema install check passed.');
        }
        
        // load config from DB
        $c->load( $this->c->get( 'base', 'configuration_id' ) );
        
        // is the install flag set
        if ( ! owa_coreAPI::getSetting('base', 'install_complete') ) {
            
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
        
        $db = owa_coreAPI::dbSingleton();
       
        // test for base tables
        foreach ( $base_module_tables as $table ) {
            
            owa_coreAPI::debug('Testing for existence of table: '. $table);
            
            $check = $db->get_results(sprintf(OWA_SQL_SHOW_TABLE, owa_coreAPI::getSetting('base', 'ns') . $table ) );
           
            // if a table is missing add it to this array
            if (empty($check)) {
            
                $tables_missing[] = $table;
                
                owa_coreAPI::debug('Did not find table: '. $table);
            }
        }
    
        if ( $tables_missing ) {
           
            owa_coreAPI::debug(sprintf("Base Schema is missing tables: %s", print_r($tables_missing, true)));
    
            return false;
            
        } else {
            
            return true;
        }
    }
}

?>