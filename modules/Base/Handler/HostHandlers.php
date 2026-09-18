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
 * Host Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class HostHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify( $event ) {

        /*
         * The id comes from the dimension, derived from content on the event.
         *
         * It used to be read off the event, where the tracking-property
         * pipeline had hashed it before dispatch. That made this handler a
         * passive consumer of an upstream derivation -- and note the guard
         * below WAS an early return on a missing id, so once the pipeline stops
         * deriving, a handler left unconverted would stop writing its dimension
         * rows silently, with no error at all.
         */
        $host_id = \OWA\Module\Base\Entity\Host::deriveId( $event->getProperties() );

        if ( $host_id === null ) {

            \OWA\Core\CoreAPI::notice('Not persisting host dimension. No host on the event.');

            return OWA_EHS_EVENT_HANDLED;
        }

        $h = \OWA\Core\CoreAPI::entityFactory('base.host');

        $h->getByPk( 'id', $host_id );

        $id = $h->get('id');

        if (!$id) {

            $h->setProperties( $event->getProperties() );
            $h->set( 'id', $host_id );
            $ret = $h->create();

            if ( $ret ) {
                return OWA_EHS_EVENT_HANDLED;
            } else {
                return OWA_EHS_EVENT_FAILED;
            }

        } else {

            $h->detectIdCollision( 'host', $event->get( 'host' ) );

            \OWA\Core\CoreAPI::debug('Not Persisting. Host already exists.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>