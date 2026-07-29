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
 * Feed Request handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class FeedRequestHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify( $event ) {

        // Make entity
        $f = \OWA\Core\CoreAPI::entityFactory('base.feed_request');

        $f->load( $event->get('guid') );

        if ( ! $f->wasPersisted() ) {

            // needed??
            $event->set('feed_reader_guid', $event->setEnvGUID() );
            // set feedreader flag to true, browser flag to false
            $event->set('is_feedreader', true);
            $event->set('is_browser', false);

            // set params on entity
            $f->setProperties( $event->getProperties() );

            // Set Primary Key
            $f->set( 'id', $event->get('guid') );

            // Make ua id
            $f->set('ua_id', \OWA\Core\Lib::setStringGuid($event->get('HTTP_USER_AGENT')));

            // Make OS id
            $f->set('os_id', \OWA\Core\Lib::setStringGuid($event->get('os')));

            // Make document id
            $f->set('document_id', \OWA\Core\Lib::setStringGuid($event->get('page_url')));

            // Generate Host id
            $f->set('host_id', \OWA\Core\Lib::setStringGuid($event->get('host')));

            $f->set('subscription_id', $event->get( 'feed_subscription_id' ) );
            // Persist to database
            $ret = $f->create();

            if ( $ret ) {

                $eq = \OWA\Core\CoreAPI::getEventDispatch();

                $nevent = $eq->makeEvent($event->getEventType().'_logged');

                $nevent->setProperties($event->getProperties());

                $eq->notify($nevent);

                return OWA_EHS_EVENT_HANDLED;

            } else {

                return OWA_EHS_EVENT_FAILED;
            }
        } else {

            \OWA\Core\CoreAPI::debug('Not persisting. Feed request already exists.');

            return OWA_EHS_EVENT_HANDLED;

        }
    }
}

?>