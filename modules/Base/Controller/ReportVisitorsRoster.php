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
 * Visitors Roster Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 * @depricated
 * @todo        remove
 */

class ReportVisitorsRoster extends \OWA\Core\ReportController {

    function __construct($params) {

        $this->priviledge_level = 'viewer';
        return parent::__construct($params);
    }

    function action() {


        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->selectColumn("distinct session.visitor_id as visitor_id, visitor.user_name, visitor.user_email");
        $db->selectFrom('owa_session', 'session');
        $db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_visitor', 'visitor', 'visitor_id', 'visitor.id');

        $db->where('site_id', $this->getParam('site_id'));

        // make new timeperiod of a day
        $period = \OWA\Core\CoreAPI::makeTimePeriod('day', array('startDate' => $this->getParam('first_session')));
        $start = $period->getStartDate();
        $end = $period->getEndDate();
        //print_r($period);
        // set new period so lables show up right.
        $db->where('first_session_timestamp',
                   array('start' => $start->getTimestamp(), 'end' => $end->getTimestamp()),
                   'BETWEEN');

        $ret = $db->getAllRows();

        $this->set('visitors', $ret);
        $this->setSubview('base.reportVisitorsRoster');
        $this->setTitle('New Visitors from', $period->getStartDate()->label);
    }

}



?>
