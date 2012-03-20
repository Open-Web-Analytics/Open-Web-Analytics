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
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_reportDomClicksController extends owa_reportController {
	
	function action() {
		
		$d = owa_coreAPI::entityFactory('base.document');	
		
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
		
		if ($this->getParam('document_id')) {
			$did = $this->getParam('document_id');
			$d->load( $did );
			$pageUrl = $d->get('url');
			$this->set('constraints', 'pageUrl=='.urlencode($pageUrl));
			$title_slug = $pagePath;
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

/**
 * Dom Clicks Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_reportDomClicksView extends owa_view {
		
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
		$this->body->set('dimension_properties', $this->get('document'));
		$this->body->set('dimension_template', 'item_document.php');
		$this->body->set_template('report_dom_clicks.php');
		$this->body->set('document', $this->get('document'));
	}
}

?>