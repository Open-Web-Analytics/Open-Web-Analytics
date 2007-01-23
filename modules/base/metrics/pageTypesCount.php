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

require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_metric.php');

/**
 * Page Types Count Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_pageTypesCount extends owa_metric {
	
	function owa_pageTypesCount($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function generate() {
		
		$r = owa_coreAPI::entityFactory('base.request');
		
		$d = owa_coreAPI::entityFactory('base.document');
		
		$this->params['related_objs'] = array('document_id' => $d);
		
		$this->setTimePeriod($this->params['period']);
		
		$this->params['select'] = "count(request.id) as count,
									document.page_title,
									document.page_type,
									document.url,
									document.id";
								
		$this->params['groupby'] = array('document.page_type');
		
		$this->params['orderby'] = array('count');
		
		return $r->query($this->params);
		
		/*
		 SELECT 
			count(requests.request_id) as count,
			documents.page_title,
			documents.page_type,
			documents.url,
			documents.id
		FROM 
			%s as requests, %s as documents 
		WHERE
			requests.document_id = documents.id
			%s
			%s 
		GROUP BY
			page_type
		ORDER BY
			count DESC
			",
			$this->setTable($this->config['requests_table']),
			$this->setTable($this->config['documents_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		 */
		
	}
	
	
}


?>