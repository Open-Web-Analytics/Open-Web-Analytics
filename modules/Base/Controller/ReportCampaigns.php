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
 * Campaigns Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class ReportCampaigns extends \OWA\Core\ReportController {

    function action() {

        $this->setSubview('base.reportDimension');
        $this->setTitle('Campaigns');
        $metrics = 'visits,pageViews,bounces';
        // enableEcommerceReporting is a PER-SITE setting. The global base setting
        // of the same name has been false since it was introduced -- it carries a
        // "move to site settings" note and nothing turns it on -- so reading it
        // here meant the e-commerce columns never appeared on this report, even
        // for a site with e-commerce enabled. Every other consumer
        // (ReportController::pre twice, ReportDashboard) already reads the site
        // setting; this was the last one left on the global.
        if ( \OWA\Core\CoreAPI::getSiteSetting( $this->getParam('siteId'), 'enableEcommerceReporting') ) {
            $metrics .= ',transactions,transactionRevenue';
        }

        $this->set('metrics', $metrics);
        $this->set('dimensions', 'campaign');
        $this->set('sort', 'visits-');
        $this->set('resultsPerPage', 30);
        $this->set('dimensionLink', array(
                'linkColumn'     => 'campaign',
                'template'         => array('do' => 'base.report', 'reportId' => 'campaign-detail', 'campaign' => '%s'),
                'valueColumns'     => 'campaign'));
        $this->set('constraints', 'campaign!=null');
        $this->set('trendChartMetric', 'visits');
        $this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.visits.formatted_value *> visits from campaigns.');
        $this->set('gridTitle', 'Top Campaigns');
    }
}

?>