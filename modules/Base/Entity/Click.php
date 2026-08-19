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
 * Click Request Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Click extends \OWA\Core\Entity\FactTable {

    function __construct() {

        $this->setTableName('click');

        // set common fact table columns
        $parent_columns = parent::__construct();

        foreach ($parent_columns as $pcolumn) {

            $this->setProperty($pcolumn);
        }

        // move to abstract
        //$this->properties['id'] = new \owa_dbColumn;
        //$this->properties['id']->setDataType(OWA_DTD_BIGINT);
        //$this->properties['id']->setPrimaryKey();

        // drop
        $this->properties['last_impression_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['last_impression_id']->setDataType(OWA_DTD_BIGINT);

        // move to abstract
        //$visitor_id = new \owa_dbColumn('visitor_id', OWA_DTD_BIGINT);
        //$visitor_id->setForeignKey('base.visitor');
        //$this->setProperty($visitor_id);

        // move to abstract
        //$session_id = new \owa_dbColumn('session_id', OWA_DTD_BIGINT);
        //$session_id->setForeignKey('base.session');
        //$this->setProperty($session_id);

        $document_id = new \OWA\Module\Base\Classes\DbColumn('document_id', OWA_DTD_BIGINT);
        $document_id->setForeignKey('base.document');
        $this->setProperty($document_id);

        // setStringGuid( target_url ) -- see ClickHandlers. Deliberately NOT
        // declared as a foreign key: a click target is any URL on the web, so
        // most values reference a page this installation has never seen and
        // never will. On one installation 266,498 clicks carry a target_id and
        // 59,083 resolve to a document; the rest are external links. A foreign
        // key asserts a referential guarantee, and there is none here.
        //
        // Those 59,083 still have to be rewritten when document ids change,
        // which is why RederiveDimensionIdsCli names this column explicitly
        // rather than discovering it from key metadata.
        $this->properties['target_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['target_id']->setDataType(OWA_DTD_BIGINT);

        $this->properties['target_url'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['target_url']->setDataType(OWA_DTD_VARCHAR255);

        // move to abstract
        //$this->properties['timestamp'] = new \owa_dbColumn;
        //$this->properties['timestamp']->setDataType(OWA_DTD_INT);
        /*
        $this->properties['year'] = new \owa_dbColumn;
        $this->properties['year']->setDataType(OWA_DTD_INT);
        $this->properties['month'] = new \owa_dbColumn;
        $this->properties['month']->setDataType(OWA_DTD_INT);
        $this->properties['day'] = new \owa_dbColumn;
        $this->properties['day']->setDataType(OWA_DTD_INT);
        $this->properties['dayofyear'] = new \owa_dbColumn;
        $this->properties['dayofyear']->setDataType(OWA_DTD_INT);
        $this->properties['weekofyear'] = new \owa_dbColumn;
        $this->properties['weekofyear']->setDataType(OWA_DTD_INT);
        */
        // drop these soon
        $this->properties['hour'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['minute'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
        $this->properties['second'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['second']->setDataType(OWA_DTD_INT);
        $this->properties['msec'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['msec']->setDataType(OWA_DTD_VARCHAR255);

        $this->properties['click_x'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['click_x']->setDataType(OWA_DTD_INT);
        $this->properties['click_y'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['click_y']->setDataType(OWA_DTD_INT);
        $this->properties['page_width'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['page_width']->setDataType(OWA_DTD_INT);
        $this->properties['page_height'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['page_height']->setDataType(OWA_DTD_INT);
        $this->properties['position'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['position']->setDataType(OWA_DTD_INT);
        $this->properties['approx_position'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['approx_position']->setDataType(OWA_DTD_BIGINT);
        $this->properties['dom_element_x'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_x']->setDataType(OWA_DTD_INT);
        $this->properties['dom_element_y'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_y']->setDataType(OWA_DTD_INT);
        $this->properties['dom_element_name'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_name']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['dom_element_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_id']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['dom_element_value'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_value']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['dom_element_tag'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_tag']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['dom_element_text'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_text']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['dom_element_class'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_class']->setDataType(OWA_DTD_VARCHAR255);
        $this->properties['dom_element_parent_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['dom_element_parent_id']->setDataType(OWA_DTD_VARCHAR255);

        // drop
        $this->properties['tag_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['tag_id']->setDataType(OWA_DTD_BIGINT);

        //drop
        $this->properties['placement_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['placement_id']->setDataType(OWA_DTD_BIGINT);

        // move to abstract
        //$this->properties['campaign_id'] = new \owa_dbColumn;
        //$this->properties['campaign_id']->setDataType(OWA_DTD_BIGINT);

        //drop
        $this->properties['ad_group_id'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['ad_group_id']->setDataType(OWA_DTD_BIGINT);

        // move to abstract
        //$this->properties['ad_id'] = new \owa_dbColumn;
        //$this->properties['ad_id']->setDataType(OWA_DTD_BIGINT);

        // move to absctract
        //$site_id = new \owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
        //$site_id->setForeignKey('base.site', 'site_id');
        //$this->setProperty($site_id);

        // move to absctract
        //$ua_id = new \owa_dbColumn('ua_id', OWA_DTD_BIGINT);
        //$ua_id->setForeignKey('base.ua');
        //$this->setProperty($ua_id);

        // move to abstract
        //$this->properties['ip_address'] = new \owa_dbColumn;
        //$this->properties['ip_address']->setDataType(OWA_DTD_VARCHAR255);

        // drop
        $this->properties['host'] = new \OWA\Module\Base\Classes\DbColumn;
        $this->properties['host']->setDataType(OWA_DTD_VARCHAR255);

        // move to abstract
        //wrong data type
        //$host_id = new \owa_dbColumn('host_id', OWA_DTD_VARCHAR255);
        //$host_id->setForeignKey('base.host');
        //$this->setProperty($host_id);

        // move to abstract
        //$yyyymmdd =  new \owa_dbColumn;
        //$yyyymmdd->setName('yyyymmdd');
        //$yyyymmdd->setDataType(OWA_DTD_INT);
        //$yyyymmdd->setIndex();
        //$this->setProperty($yyyymmdd);

    }
}

?>