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

class ReportDomClicks extends \OWA\Core\ReportController {
    
    function action() {
        
        $d = \OWA\Core\CoreAPI::entityFactory('base.document');
        
        if ($this->getParam('pageUrl')) {
            $pageUrl = $this->getParam('pageUrl');
            $d->getByColumn('url', $pageUrl);
            $this->set('constraints', 'pageUrl=='.urlencode($pageUrl));
            $title_slug = $pageUrl;
        }
        
        if ($this->getParam('pagePath')) {
            $pagePath = $this->getParam('pagePath');
            $d->getByColumn('uri', $pagePath);
            $this->set('constraints', 'pagePath=='.urlencode($pagePath));
            $title_slug = $pagePath;
        }
        
        // Only assigned inside the branch below, but read by setTitle() after it.
        $title_slug = '';

        if ($this->getParam('document_id')) {
            $did = $this->getParam('document_id');
            $d->load( $did );
            $pageUrl = $d->get('url');
            $this->set('constraints', 'pageUrl=='.urlencode($pageUrl));
            $title_slug = isset($pagePath) ? $pagePath : '';
        }
        
        $this->setTitle('Dom Clicks: ', $title_slug);
        $this->set('document', $d);
        $this->setSubview('base.reportDomClicks');
        $this->set('metrics', 'domClicks');
        $this->set('sort', 'domClicks');
        $this->set('resultsPerPage', 30);
        $this->set('trendChartMetric', 'domClicks');
        $this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.domClicks.formatted_value *> dom clicks for this web page.');
    }
}



?>
