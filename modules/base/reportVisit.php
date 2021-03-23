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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Visit Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_reportVisitController extends owa_reportController {

    function action() {

        $visit = owa_coreAPI::executeApiCommand(array(
                'request_method'	=> 'GET',
	        	'module'			=> 'base',
	        	'version'			=> 'v1',
                'do'        		=> 'reports',
                'report_name'		=> 'visit',
                'sessionId'    => $this->getParam('session_id') ) );

        //setup Metrics
        $rs = owa_coreAPI::executeApiCommand(array(
	        	'request_method'	=> 'GET',
	        	'module'			=> 'base',
	        	'version'			=> 'v1',
                'do'        		=> 'reports',
                'report_name'		=> 'clickstream',
                'sessionId'    		=> $this->getParam('session_id') ) );

        $this->set('clickstream', $rs);
        $this->set('visit', $visit);
        $this->set('session_id', $this->getParam('session_id'));
        $this->setView('base.report');
        $this->setSubview('base.reportVisit');
        $this->setTitle('Visit Clickstream');
    }
}

/**
 * Visit Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_reportVisitView extends owa_view {

    function render() {

        // Assign data to templates
        $this->body->set_template('report_visit.tpl');
        $this->body->set('session_id', $this->get('session_id'));
        $this->body->set('visits', $this->get('visit'));
        $this->body->set('clickstream', $this->get('clickstream'));
    }
}

?>