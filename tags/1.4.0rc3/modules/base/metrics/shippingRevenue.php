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
 * Ecommerce Tax Revenue Metric
 *
 * A Sum of the tax revenue of ecommerce transactions
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 - 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_shippingRevenue extends owa_metric {

	function __construct() {
	
		$this->setName('shippingRevenue');
		$this->setLabel('Shipping');
		$this->setEntity('base.commerce_transaction_fact');
		$this->setColumn('shipping_revenue');
		$this->setSelect(sprintf("SUM(%s)", $this->getColumn()));
		$this->setDataType('currency');
		return parent::__construct();
	}
}

?>