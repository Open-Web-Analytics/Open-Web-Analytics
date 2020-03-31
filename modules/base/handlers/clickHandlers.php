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
 * Click Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_clickHandlers extends owa_observer {

    /**
     * Notify Handler
     *
     * @access     public
     * @param     object $event
     */
    function notify($event) {

        $c = owa_coreAPI::entityFactory('base.click');

        $c->load( $event->get( 'guid' ) );

        if (! $c->wasPersisted() ) {
            $c->set('id', $event->get('guid') );
            $c->setProperties($event->getProperties());
            $c->set('visitor_id', $event->get('visitor_id'));
            $c->set('session_id', $event->get('session_id'));
            $c->set('ua_id', owa_lib::setStringGuid($event->get('HTTP_USER_AGENT')));

            // Make document id
            $c->set('document_id', owa_lib::setStringGuid($event->get('page_url')));

            // Make Target page id
            $c->set('target_id', owa_lib::setStringGuid($c->get('target_url')));

            // Make position id used for group bys
            $c->set('position', $c->get('click_x').$c->get('click_y'));

            $ret = $c->create();

            if ( $ret ) {

                // Tell others that "dom.click" has been logged
                $eq = owa_coreAPI::getEventDispatch();
                $nevent = $eq->makeEvent($event->getEventType().'_logged');
                $nevent->setProperties($event->getProperties());
                $eq->asyncNotify($nevent);

                return OWA_EHS_EVENT_HANDLED;
            } else {
                return OWA_EHS_EVENT_FAILED;
            }

        } else {
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>