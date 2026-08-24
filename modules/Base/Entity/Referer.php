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
 * Referer Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Referer extends \OWA\Core\Entity {

    function __construct() {

        $this->setTableName('referer');
        $this->setCachable();
        // properties
        $this->properties['id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['id']->setDataType(OWA_DTD_BIGINT);
        $this->properties['id']->setPrimaryKey();
        $this->properties['url'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['url']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['site_name'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['site_name']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['site'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['site']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['query_terms'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['query_terms']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['refering_anchortext'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['refering_anchortext']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['page_title'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['page_title']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['snippet'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['snippet']->setDataType(OWA_DTD_TEXT);
        $this->properties['is_searchengine'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['is_searchengine']->setDataType(OWA_DTD_TINYINT);
    }

}

?>