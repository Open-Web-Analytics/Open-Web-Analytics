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
 * Products Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class ReportProducts extends \OWA\Core\ReportController {

    function action() {

        $this->setSubview('base.reportSimpleDimensional');
        $this->setTitle('Products');
        $this->set('metrics', 'lineItemQuantity,lineItemRevenue');
        $this->set('dimensions', 'productName');
        // 'actions' is not one of this report's metrics -- it is not queried
        // here at all, so the sort could not resolve and the dimensional query
        // returned no rows: the page rendered with aggregates of 0 and an empty
        // grid, which reads as "no sales" rather than as a fault. Its two
        // siblings with the identical metric list (ReportProductSkus,
        // ReportProductCategories) both sort by lineItemQuantity-.
        $this->set('sort', 'lineItemQuantity-');
        $this->set('resultsPerPage', 30);
        $this->set('dimensionLink', array('linkColumn' => 'productName',
                                                'template' => array('do' => 'base.reportProductDetail', 'productName' => '%s'),
                                                'valueColumns' => 'productName'));
        $this->set('trendChartMetric', 'lineItemQuantity');
        $this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.lineItemQuantity.formatted_value *> products sold.');

    }
}

?>