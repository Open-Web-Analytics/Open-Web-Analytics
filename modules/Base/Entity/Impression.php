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


namespace OWA\Module\Base\Entity;

/**
 * Visitor Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Impression extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('impression');
        // properties
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();
        $this->properties['visitor_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['visitor_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['session_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['session_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['tag_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['tag_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['placement_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['placement_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['campaign_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['campaign_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['ad_group_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['ad_group_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['ad_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['ad_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['site_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['site_id']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['last_impression_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['last_impression_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['last_impression_timestamp'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['last_impression_timestamp']->setDataType(OWA_DTD_BIGINT);
        $this->properties['timestamp'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['timestamp']->setDataType(OWA_DTD_BIGINT);
        $this->properties['year'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['year']->setDataType(OWA_DTD_INT);
        $this->properties['month'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['month']->setDataType(OWA_DTD_INT);
        $this->properties['day'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['day']->setDataType(OWA_DTD_INT);
        $this->properties['dayofyear'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dayofyear']->setDataType(OWA_DTD_INT);
        $this->properties['weekofyear'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['weekofyear']->setDataType(OWA_DTD_INT);
        $this->properties['hour'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['minute'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['msec'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['msec']->setDataType(OWA_DTD_BIGINT);
        $this->properties['url'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['url']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['ua_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['ua_id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['ip_address'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['host_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['host_id']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['host'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['host']->setDataType(OWA_DTD_VARCHAR255);
    }



}



?>