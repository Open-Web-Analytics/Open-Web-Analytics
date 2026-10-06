<?php
namespace OWA\Module\Base\Controller;


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
 * Updates Application Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class UpdatesApplyCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Applies pending schema updates for every module, in order. When an update needs a choice from the operator, it stops and says what to run.',
            'arguments'   => array(
                'listpending'                  => 'List the modules with pending updates, and apply nothing.',
                'apply=<module>.<sequence>'    => 'Apply one update.',
                'rollback=<module>.<sequence>' => 'Roll back one update.',
                '--force'                      => 'With apply=: apply out of sequence.',
                'since=<cutoff>'               => 'For the 1.x history migration: migrate events newer than a date (yyyymmdd) or a period such as 2years.',
                '--all'                        => 'For the 1.x history migration: migrate everything.',
            ),
        );
    }
    
    function __construct($params) {
        define('OWA_UPDATING', true);
        return parent::__construct($params);
    }

    function action() {
        
        // fetch list of modules that require updates
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        
        if ($this->isParam('listpending')) {
            
            return $this->listPendingUpdates();
        }
        
        if ($this->getParam('apply')) {
            
            return $this->apply($this->get('apply'));
        }
        
        if ($this->getParam('rollback')) {
            
            return $this->rollback($this->get('rollback'));
        }
        
        $modules = $s->getModulesNeedingUpdates();
        //print_r($modules);
        //return;
        
        // foreach do update in order
        if (!empty($modules)) {
            $error = false;
            
            foreach ($modules as $k => $v) {
            
                $ret = $s->modules[$v]->update();

                if ($ret != true):
                    $awaiting = (string) ( $s->modules[$v]->update_awaiting ?? '' );
                    $error = true;
                    break;
                endif;

            }

            if ( ( $awaiting ?? '' ) !== '' ) {
                // Nothing failed: an update needs the operator first, and has said what.
                \OWA\Core\CoreAPI::notice( sprintf(
                    'Updates paused: one needs %s. Do what it says above, then run cmd=update again.', $awaiting ) );
            } elseif ($error === true) {
                \OWA\Core\CoreAPI::notice($this->getMsg(3307));
            } else {
                
                // add data to container
                \OWA\Core\CoreAPI::notice($this->getMsg(3308));
            }
        } else {
            \OWA\Core\CoreAPI::notice("There are no modules with pending updates to apply.");
        }
    
    
    }
    
    function listPendingUpdates() {
        
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $modules = $s->getModulesNeedingUpdates();
        if ($modules) {
            \OWA\Core\CoreAPI::notice(sprintf("Updates pending include: %s",print_r($modules, true)));
        } else {
            \OWA\Core\CoreAPI::notice("No updates are pending.");
        }
    }
    
    function apply($update) {
    
        list($module, $seq) = explode('.', $update);
        $u = \OWA\Core\CoreAPI::updateFactory($module, $seq);
        
        // check for force command param
        //
        // The key is 'force', not '--force'. The dashes used to be part of the
        // parameter NAME because cli.php required key=value and stored whatever
        // was left of the '=' verbatim, so 'cmd=update --force=1' produced a
        // parameter literally called '--force'. cli.php now strips a leading --
        // and treats a valueless switch as true, so all three spellings arrive
        // here as 'force': --force, --force=1 and force=1.
        $force = false;
        if ($this->isParam('force')) {
            
            $force = true;
        }
        
        $ret = $u->apply($force);
        
        if ($ret) {
            \OWA\Core\CoreAPI::notice("Updates applied successfully.");
        }
    }
    
    function rollback($update) {
        list($module, $seq) = explode('.', $update);
        $u = \OWA\Core\CoreAPI::updateFactory($module, $seq);

        // rollback() returns false when it refuses an out-of-sequence rollback
        // and when down() fails part way. Reporting "completed" regardless told
        // operators the schema had moved when nothing had happened.
        $ret = $u->rollback();

        if ($ret) {
            \OWA\Core\CoreAPI::notice("Rollback completed.");
        } else {
            \OWA\Core\CoreAPI::notice("Rollback did NOT complete. See the messages above for whether it was refused or failed part way.");
        }
    }
    
}

?>