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
 * Commerce Transaction Fact Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class owa_commerce_transaction_fact extends owa_factTable {

    function __construct() {

        $this->setTableName('commerce_transaction_fact');

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

        $order_source = new owa_dbColumn('order_source', 'OWA_DTD_VARCHAR255');
        $this->setProperty($order_source);

        $gateway = new owa_dbColumn('gateway', 'OWA_DTD_VARCHAR255');
        $this->setProperty($gateway);

        $total = new owa_dbColumn('total_revenue', 'OWA_DTD_BIGINT');
        $this->setProperty($total);

        $tax = new owa_dbColumn('tax_revenue', 'OWA_DTD_BIGINT');
        $this->setProperty($tax);

        $shipping = new owa_dbColumn('shipping_revenue', 'OWA_DTD_BIGINT');
        $this->setProperty($shipping);

    }
}

?>