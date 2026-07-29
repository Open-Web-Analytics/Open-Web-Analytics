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
 * Document Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class ReportDocument extends \OWA\Core\ReportController {

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

        $this->setTitle('Page Detail: ');

        $this->set('document', $d);
        $this->set('metrics', 'visits,pageViews');
        $this->set('resultsPerPage', 30);
        $this->set('trendChartMetric', 'pageViews');
        $this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.pageViews.formatted_value *> page views for this page.');
        $this->setSubview('base.reportDocument');
    }

}




?>
