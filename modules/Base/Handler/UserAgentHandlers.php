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
 * OWA User Agent Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class UserAgentHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {

        if ( $event->get('HTTP_USER_AGENT') ) {

            $ua = \OWA\Core\CoreAPI::entityFactory('base.ua');

            $ua->getByColumn('id', \OWA\Core\Lib::setStringGuid($event->get('HTTP_USER_AGENT')));

            if (!$ua->get('id')) {

                $ua->setProperties($event->getProperties());
                $ua->set('ua', $event->get('HTTP_USER_AGENT'));
                $ua->set('id', \OWA\Core\Lib::setStringGuid($event->get('HTTP_USER_AGENT')));
                $ret = $ua->create();

                if ( $ret ) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }

            } else {

                $ua->detectIdCollision( 'ua', $event->get('HTTP_USER_AGENT') );

                $old = $ua->get('browser_type');
                $new = $event->get('browser_type');
				
                if ( $new != $old && $new != 'Default Browser') {
	                \OWA\Core\CoreAPI::debug("updating ua: $new old: $old");
                    $ua->set('browser_type', $new);
                    $ua->set('browser', $event->get('browser') );
                    $ret = $ua->save();

                    if ( $ret ) {
                        \OWA\Core\CoreAPI::debug('Updating user agent with new browser type: '. $new);
                        return OWA_EHS_EVENT_HANDLED;
                    } else {
                        return OWA_EHS_EVENT_FAILED;
                    }
                }

                \OWA\Core\CoreAPI::debug('not logging, user agent already exists.');
                return OWA_EHS_EVENT_HANDLED;
            }

        } else {

            \OWA\Core\CoreAPI::debug('not logging, no user agent present.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>