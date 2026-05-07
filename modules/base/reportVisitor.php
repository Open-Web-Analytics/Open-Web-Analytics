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

class owa_reportVisitorController extends owa_reportController {

    function action() {

        $visitorId = $this->getParam('visitorId');

        if (!$visitorId) {
            $visitorId = $this->getParam('visitor_id');
        }

        $v = owa_coreAPI::entityFactory('base.visitor');
        $v->load($visitorId);

        $lv = owa_coreAPI::executeApiCommand(array(

            'request_method'	=> 'GET',
			'module'			=> 'base',
			'version'			=> 'v1',
            'do'                => 'reports',
            'report_name'		=> 'latest_visits',
            'siteId'            => $this->getParam('siteId'),
            'page'                => $this->getParam('page'),
            'startDate'            => $this->getParam('startDate'),
            'endDate'            => $this->getParam('endDate'),
            'period'            => $this->getParam('period'),
            'visitorId'            => $v->get('id'),
            'resultsPerPage'    => 10
        ));

        $this->set('visits', $lv);
        $this->set('visitor_label', $v->getVisitorName());
        $first_visit_date = date( 'm/d/y',$v->get('first_session_timestamp' ) );
        $this->set('first_visit_date', $first_visit_date);
        $this->set('num_prior_visits', $v->get('num_prior_sessions') );
        $this->set('visitor_id', $visitorId);
        $this->set('visitor', $v);
        $this->set('visitor_avatar_id', $v->getAvatarId() );
        $this->setView('base.report');
        $this->setSubview('base.reportVisitor');
        $this->setTitle('Visitor History');
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

class owa_reportVisitorView extends owa_view {

    function render($data) {

        $this->body->set_template('report_visitor.tpl');
        $this->body->set( 'visitor_id', $this->get('visitor_id') );
        $this->body->set( 'visits', $this->get('visits') );
        $this->body->set( 'visitor', $this->get('visitor') );
        $this->body->set( 'num_prior_visits', $this->get('num_prior_visits') );
        $this->body->set( 'first_visit_date', $this->get('first_visit_date') );
        $this->body->set( 'visitor_label', $this->get('visitor_label') );
        $this->body->set( 'visitor_avatar_id', $this->get('visitor_avatar_id') );
        $this->body->set( 'visits', $this->get( 'visits') );
    }
}

?>