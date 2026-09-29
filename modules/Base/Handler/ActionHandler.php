<?php
namespace OWA\Module\Base\Handler;


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
 * Action Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */

class ActionHandler extends \OWA\Core\Observer {
    
    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {
        
        $a = \OWA\Core\CoreAPI::entityFactory('base.action_fact');
        
        $a->load( $event->get( 'guid' ), 'id', \OWA\Core\Db::factDateConstraint( $event->get('yyyymmdd') ) );
        
        if ( ! $a->wasPersisted() ) {
            
            $a->setProperties( $event->getProperties() );
            // Set Primary Key
            $a->set( 'id', $event->get('guid') );
            $a->set('action_name', strtolower(trim((string) $event->get('action_name'))));
            $a->set('action_group', strtolower(trim((string) $event->get('action_group'))));
            $a->set('action_label', strtolower(trim((string) $event->get('action_label'))));
            $a->set('numeric_value', $event->get('numeric_value') * 1);
            
            $ret = $a->create();
            
            if ( $ret ) {
                // Tell others that "track.action" has been logged
                /*
                 * The *_logged raise was here. It existed to hand the v1
                 * star-schema handlers a second event to hang off, and they
                 * are unregistered -- so it raised an event with no listeners
                 * on every beacon.
                 */
            
                return OWA_EHS_EVENT_HANDLED;
            } else {
                return OWA_EHS_EVENT_FAILED;
            }
        }
    }
}

?>