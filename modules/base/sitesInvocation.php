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


require_once(OWA_BASE_DIR.'/owa_adminController.php');
require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Tracked Sites Tag Generator Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_sitesInvocationController extends owa_adminController {

    function __construct($params) {

        $this->setRequiredCapability('edit_sites');
        return parent::__construct($params);
    }

    function action() {
        $site_id = $this->getParam('siteId');
        $this->set('site_id', $site_id);
        $s = owa_coreAPI::entityFactory('base.site');
        $s->getByColumn('site_id', $site_id);
        $this->set('site', $s);
        $this->setSubview('base.sitesInvocation');
        $this->setView('base.options');
    }
}



/**
 * Sites Invocation Instructions
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class owa_sitesInvocationView extends owa_view {

    function render($data) {

        $site = $this->get('site');

        if ($site->get('name')) {
            $name = sprintf("%s (%s)", $site->get('domain'), $site->get('name'));
        } else {
            $name = $site->get('domain');
        }


        //page title
        $this->t->set('page_title', 'Tracking Tags');
        $this->body->set('site', $site);
        $this->body->set('name', $name);
        $this->body->set('options', array());
        // load body template
        $this->body->set_template('sites_invocation.tpl');

        $this->body->set('site_id', $this->get('site_id'));

        $this->body->set('tracking_code', owa_coreAPI::getJsTrackerTag( $this->get('site_id') ) );
    }
}

?>