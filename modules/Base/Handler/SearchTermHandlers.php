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
 * Search Term Handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */

class SearchTermHandlers extends \OWA\Core\Observer {
    
    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {

        $terms = trim(strtolower((string) $event->get('search_terms')));

        if ($terms) {

            $st = \OWA\Core\CoreAPI::entityFactory('base.search_term_dim');
            $st_id = \OWA\Core\Lib::setStringGuid($terms);
            $st->getByPk('id', $st_id);
            $id = $st->get('id');

            if (!$id) {

                $st->set('id', $st_id);
                $st->set('terms', $terms);
                $ret = str_replace("","",$terms,$count);
                $st->set('term_count', $count);
                $ret = $st->create();

                if ( $ret ) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }

            } else {

                \OWA\Core\CoreAPI::debug('Not Logging. Search term already exists.');
                return OWA_EHS_EVENT_HANDLED;
            }
        } else {
            return OWA_EHS_EVENT_HANDLED;
        }

    }
}

?>