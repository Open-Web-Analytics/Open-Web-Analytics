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

if (!class_exists("owa_calculatedMetric")) {
	require_once(OWA_BASE_CLASS_DIR.'calculatedMetric.php');
}

/**
 * Pages Per Visit Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_pagesPerVisit extends owa_calculatedMetric {

	function __construct() {
	
		$this->setName('pagesPerVisit');
		$this->setLabel('Pages Per Visit');
		$this->setChildMetric('pageViews');
		$this->setChildMetric('visits');
		$this->setFormula('round(pageViews / visits, 2)');
		$this->setDataType('decimal');
		return parent::__construct();
	}
}

?>