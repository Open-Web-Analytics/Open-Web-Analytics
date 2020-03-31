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

if(!class_exists('owa_observer')) {
    require_once(OWA_BASE_DIR.'owa_observer.php');
}

/**
 * OWA Visitor Update Event handlers
 *
 * Used to update certain properties of an existing visitor entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_visitorUpdateHandlers extends owa_observer {

    /**
     * Notify Event Handler
     *
     * @param     unknown_type $event
     * @access     public
     */
    function notify($event) {

        if ( $event->get( 'visitor_id' ) ) {

            $v = owa_coreAPI::entityFactory('base.visitor');

            $v->load( $event->get( 'visitor_id' ) );

            if ( $v->wasPersisted() ) {

                $v->set('num_prior_sessions', $this->summarizePriorSessions( $v->get('id') ) );

                owa_coreAPI::debug("Updating... Visitor already exists.");

                $ret = $v->save();

                if ( $ret ) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }
            }

        } else {

            owa_coreAPI::debug("Not updating... no visitor ID present.");
            return OWA_EHS_EVENT_HANDLED;
        }
    }
    
    function summarizePriorSessions($id) {

        $ret = owa_coreAPI::summarize(array(
                'entity'        => 'base.session',
                'columns'        => array('num_prior_sessions' => 'max'),
                'constraints'    => array( 'visitor_id' => $id ) ) );

        return $ret['num_prior_sessions_max'];
    }
}

?>