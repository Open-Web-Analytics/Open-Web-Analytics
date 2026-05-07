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

/**
 * Top Visitors metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_topVisitors extends owa_metric {

    function owa_topVisitors($params = null) {

        return owa_topVisitors::__construct($params = null);

    }

    function __construct($params = null) {

        parent::__construct($params);
    }

    function calculate() {

        $this->db->selectColumn("count(visitor_id) as count, visitor_id as vis_id, user_name, user_email");
        $this->db->selectFrom('owa_session');
        $this->db->groupBy('vis_id');
        $this->db->orderBy('count', $this->getOrder());

        $ret = $this->db->getAllRows();

        return $ret;

    }

    function paginationCount() {

        $this->db->selectColumn("count(distinct visitor_id) as count");
        $this->db->selectFrom('owa_session');

        $ret = $this->db->getOneRow();

        return $ret['count'];

    }


}


?>