<?php
namespace OWA\Module\Base\Controller;


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

class ReportTransactionDetail extends \owa_reportController {

    function action() {

        $transactionId = $this->getParam('transactionId');

        $trans_detail = \owa_coreAPI::executeAPICommand(array(
	        
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



?>
