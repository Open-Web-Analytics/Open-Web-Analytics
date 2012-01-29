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

/**
 * Pages Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_reportPagesController extends owa_reportController {
	
	function action() {
			
		$this->setSubview('base.reportSimpleDimensional');
		$this->setTitle('Web Pages');
		$this->set('metrics', 'pageViews,visits,uniquePageViews');
		// add ametrics override setting
		$this->set('dimensions', 'pagePath,pageTitle,pageType,pageUrl');
		$this->set('excludeColumns', "'pageUrl'");
		$this->set('sort', 'pageViews-');
		$this->set('resultsPerPage', 30);
		$this->set('dimensionLink', array(
				'linkColumn' 	=> 'pagePath', 
				'template' 		=> array('do' => 'base.reportDocument', 'pageUrl' => '%s'), 
				'valueColumns' 	=> 'pageUrl'));
		$this->set('trendChartMetric', 'pageViews');
		$this->set('trendTitle', 'There were <*= this.d.resultSet.aggregates.pageViews.formatted_value *> page views for <*= this.d.resultSet.aggregates.uniquePageViews.value *> unique pages.');
		$this->set('gridTitle', 'Top Pages');		
	}
}

?>