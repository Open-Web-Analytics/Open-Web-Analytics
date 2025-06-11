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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Transaction Detail Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 - 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class owa_reportTransactionDetailController extends owa_reportController {

    function action() {

        $transactionId = $this->getParam('transactionId');

        $trans_detail = owa_coreAPI::executeAPICommand(array(
	        
	        	'request_method' 	=> 'GET',
	        	'module'			=> 'base',
	        	'version'			=> 'v1',
                'do'            	=> 'reports',
                'report_name'		=> 'transaction',
                'transactionId'    => $transactionId
        ));
		$trans_detail = (array) $trans_detail;
        $this->set('trans_detail', $trans_detail);
        $this->setSubview('base.reportTransactionDetail');
        $this->setTitle('Transaction Detail for: ', $transaction_id);
    }

}

/**
 * Transaction Detail Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 - 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_reportTransactionDetailView extends owa_view {

    function render() {

        $this->body->set( 'trans_detail', $this->get( 'trans_detail' ) );
        $this->body->set_template( 'report_transaction_detail.php' );
    }

}

?>