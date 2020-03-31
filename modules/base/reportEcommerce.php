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

require_once(OWA_BASE_DIR.'/owa_reportController.php');
require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Ecommerce Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.4.0
 */

class owa_reportEcommerceController extends owa_reportController {
    
    /**
     * Constructor
     *
     * @param array $params
     * @return
     */
    function __construct($params) {        
        return parent::__construct($params);
        $this->setRequiredCapability('view_reports_ecommerce');
    }
    
    function action() {
        
        $this->setSubview('base.reportEcommerce');
        $this->setTitle('Ecommerce');
        $this->set('metrics', 'visits,transactions,transactionRevenue,ecommerceConversionRate,revenuePerVisit,revenuePerTransaction');
        $this->set('sort', 'actions');
        $this->set('resultsPerPage', 30);        
        $this->set('trendChartMetric', 'transactions');
        $this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.transactions.formatted_value *> transactions completed.');
    }
}

/**
 * Ecommerce Tracking Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.3.0
 */

class owa_reportEcommerceView extends owa_view {
        
    function render() {
        
        // Assign Data to templates
        $this->body->set('metrics', $this->get('metrics'));
        $this->body->set('dimensions', $this->get('dimensions'));
        $this->body->set('sort', $this->get('sort'));
        $this->body->set('resultsPerPage', $this->get('resultsPerPage'));
        $this->body->set('dimensionLink', $this->get('dimensionLink'));
        $this->body->set('trendChartMetric', $this->get('trendChartMetric'));
        $this->body->set('trendTitle', $this->get('trendTitle'));
        $this->body->set('constraints', $this->get('constraints'));
        $this->body->set('gridTitle', $this->get('gridTitle'));
        $this->body->set('hideGrid', true);
        $this->body->set_template('report_ecommerce.php');
    }
}

?>