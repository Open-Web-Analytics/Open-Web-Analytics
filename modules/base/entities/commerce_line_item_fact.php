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

if ( ! class_exists( 'owa_factTable' ) ) {
    require_once( OWA_BASE_CLASS_DIR . 'factTable.php');
}

/**
 * Commerce Transaction Line Item Fact Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class owa_commerce_line_item_fact extends owa_factTable {

    function __construct() {

        $this->setTableName('commerce_line_item_fact');

        // set common fact table columns
        $parent_columns = parent::__construct();

        foreach ($parent_columns as $pcolumn) {

            $this->setProperty($pcolumn);
        }

        $document_id = new owa_dbColumn('document_id', 'OWA_DTD_BIGINT');
        $document_id->setForeignKey('base.document');
        $this->setProperty($document_id);

        $order_id = new owa_dbColumn('order_id', 'OWA_DTD_VARCHAR255');
        $order_id->setIndex();
        $this->setProperty($order_id);

        $sku = new owa_dbColumn('sku', 'OWA_DTD_VARCHAR255');
        $this->setProperty($sku);

        $product_name = new owa_dbColumn('product_name', 'OWA_DTD_VARCHAR255');
        $this->setProperty($product_name);

        $category = new owa_dbColumn('category', 'OWA_DTD_VARCHAR255');
        $this->setProperty($category);

        $unit_price = new owa_dbColumn('unit_price', 'OWA_DTD_BIGINT');
        $this->setProperty($unit_price);

        $quantity = new owa_dbColumn('quantity', 'OWA_DTD_INT');
        $this->setProperty($quantity);

        $item_revenue = new owa_dbColumn('item_revenue', 'OWA_DTD_BIGINT');
        $this->setProperty($item_revenue);

    }
}

?>