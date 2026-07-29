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
 * Campaign Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class CampaignHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {

        if ($event->get('campaign')) {
            $d = \OWA\Core\CoreAPI::entityFactory('base.campaign_dim');

            $new_id = $d->generateId(trim( strtolower( (string) $event->get('campaign') ) ) );
            $d->getByPk('id', $new_id);
            $id = $d->get('id');

            if (!$id) {

                $d->set('id', $new_id);
                $d->set('name', $event->get('campaign'));
                $ret = $d->create();

                if ( $ret ) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }

            } else {

                \OWA\Core\CoreAPI::debug('Not Persisting. Campaign already exists.');
                return OWA_EHS_EVENT_HANDLED;
            }
        } else {
            \OWA\Core\CoreAPI::debug('Noting to handle. No Campaign properties found on event.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>