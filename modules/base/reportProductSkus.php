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
 * Product SKUs Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.4.0
 */

class owa_reportProductSkusController extends owa_reportController {
    
    function action() {
        
        $dim_name = 'productSku';
        $this->setSubview('base.reportSimpleDimensional');
        $this->setTitle('Product SKUs');
        $this->set('metrics', 'lineItemQuantity,lineItemRevenue');
        $this->set('dimensions', $dim_name);
        $this->set('sort', 'lineItemQuantity-');
        $this->set('resultsPerPage', 30);
        $this->set('dimensionLink', array('linkColumn' => $dim_name, 
                                                'template' => array('do' => 'base.reportProductSkuDetail', $dim_name => '%s'), 
                                                'valueColumns' => $dim_name));
        $this->set('trendChartMetric', 'lineItemQuantity');
        $this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.lineItemQuantity.formatted_value *> products sold across all SKUs.');
                
    }
}

?>