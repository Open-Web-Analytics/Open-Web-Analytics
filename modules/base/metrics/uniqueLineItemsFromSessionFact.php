<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
// <a href="//www.openwebanalytics.com">Open Web Analytics</a>
// Copyright Peter Adams. All rights reserved.
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
 * Ecommerce Unique Line Items Metric
 *
 * A distinct count of the line items of ecommerce transactions
 * Derived from owa_session fact table
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 - 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class owa_uniqueLineItemsFromSessionFact extends owa_metric {

    function __construct() {

        $this->setName('uniqueLineItems');
        $this->setLabel('Unique Items');
        $this->setEntity('base.session');
        $this->setColumn('commerce_items_count');
        $this->setSelect(sprintf("SUM(%s)", $this->getColumn()));
        $this->setDataType('integer');
        return parent::__construct();
    }
}

?>