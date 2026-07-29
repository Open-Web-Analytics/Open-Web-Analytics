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
 * Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class ProcessFirstRequest extends \owa_processEventController {

    function __construct($params) {

        return parent::__construct($params);
    }

    function pre() {

        return false;
    }

    function action() {

        $fh_state_name = \owa_coreAPI::getSetting('base', 'first_hit_param');
        //print_r($fh_state_name);
        $fh = \owa_coreAPI::getStateParam($fh_state_name);
        \owa_coreAPI::debug('cookiename: '.$fh_state_name);
        //owa_coreAPI::debug(print_r($_COOKIE, true));
        if (!empty($fh)) {

            $this->event->replaceProperties($fh);
            $this->event->setEventType('base.first_page_request');
            //owa_coreAPI::debug(print_r($this->event, true));
            // Delete first_hit Cookie
            \owa_coreAPI::clearState($fh_state_name);

        }

        $this->setView('base.pixel');
        $this->setViewMethod('image');
    }
}

?>