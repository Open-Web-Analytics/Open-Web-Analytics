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
 * Action Event Fact Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.3.0
 */

class ActionFact extends \OWA\Core\Entity\FactTable {

    function __construct() {

        $this->setTableName('action_fact');

        // set common fact table columns
        $parent_columns = parent::__construct();

        foreach ($parent_columns as $pcolumn) {

            $this->setProperty($pcolumn);
        }

        $document_id = new \OWA\Module\Base\Classes\DbColumn('document_id', OWA_DTD_BIGINT);
        $document_id->setForeignKey('base.document');
        $this->setProperty($document_id);

        $action_name = new \OWA\Module\Base\Classes\DbColumn('action_name', OWA_DTD_VARCHAR255);
        $this->setProperty($action_name);

        $action_label = new \OWA\Module\Base\Classes\DbColumn('action_label', OWA_DTD_VARCHAR255);
        $this->setProperty($action_label);

        $action_group = new \OWA\Module\Base\Classes\DbColumn('action_group', OWA_DTD_VARCHAR255);
        $this->setProperty($action_group);

        $numeric_value = new \OWA\Module\Base\Classes\DbColumn('numeric_value', OWA_DTD_INT);
        $this->setProperty($numeric_value);
    }
}

?>