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
 * Host Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Host extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('host');
        $this->setCachable();
        // properties
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();
        $this->properties['ip_address'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['host'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['host']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['full_host'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['full_host']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['city'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['city']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['country'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['country']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['latitude'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['latitude']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['longitude'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['longitude']->setDataType(OWA_DTD_VARCHAR255);
    }


}



?>