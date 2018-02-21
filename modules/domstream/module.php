<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2016 Peter Adams. All rights reserved.
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

require_once(OWA_BASE_DIR.'/owa_module.php');

/**
 * Remote Queue Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2016 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.6.1
 */

class owa_domstreamModule extends owa_module {
	
	function __construct() {
		
		$this->name = 'domstream';
		$this->display_name = 'Domstream';
		$this->group = 'logging';
		$this->author = 'Peter Adams';
		$this->version = '1.0';
		$this->description = 'Logs the users mouse and other DOM movements.';
		$this->config_required = false;
		$this->required_schema_version = 1;
		
		// register named queues
				
		return parent::__construct();
	}
	
	function registerFilters() {
		
		// adds tracking cmd to js tracker.
		if ( owa_coreAPI::getSetting( 'domstream', 'is_active' ) ) {
		
			$this->registerFilter('tracker_tag_cmds', $this, 'addToTracker', 99);
		}
	}
	
	/**
	 * Adds domstream logging to the JS tracker tag.
 	 * @return array
 	 */
	function addToTracker( $cmds ) {
		
		$cmds[] = "owa_cmds.push(['trackDomStream']);";
		
		return $cmds;
	}
	
	/**
	 * Registers Event Handlers with queue queue
	 *
	 */
	function _registerEventHandlers() {
		
		$this->registerEventHandler('dom.stream', 'domstreamHandlers');
	}
	
	/**
	 * Registers Reports in Main Navigation
	 *
	 */
	function registerNavigation() {
		
		$this->addNavigationLinkInSubGroup( 'Content', 'base.reportDomstreams', 'Domstreams', 5);
	}
	
	/**
	 * Register API methods
	 *
	 */
	function registerApiMethods() {
	
		$this->registerApiMethod('getDomstreams', 
				array( $this, 'getDomstreams' ), 
				array( 
					'startDate', 
					'endDate', 
					'document_id', 
					'siteId', 
					'resultsPerPage', 
					'page', 
					'format' ), 
				'', 
				'view_reports'
		);
		
		$this->registerApiMethod('getDomstream', 
				array($this, 'getDomstream'), 
				array('domstream_guid'),
				'', 
				'view_reports' 
		);
	}
	
	// api method callback
	function getDomstreams($start_date, $end_date, $document_id = '', $siteId = '', $resultsPerPage = 20, $page = 1, $format = '') {
		
		$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
		$db = owa_coreAPI::dbSingleton();
		$db->selectFrom('owa_domstream');
		$db->selectColumn("domstream_guid, max(timestamp) as timestamp, page_url, duration");
		//$db->selectColumn('id');
		$db->selectColumn('document_id');
		$db->groupby('domstream_guid');
		//$db->selectColumn('events');
		$db->where('yyyymmdd', array('start' => $start_date, 'end' => $end_date), 'BETWEEN');
		if ($document_id) {
			$db->where('document_id', $document_id);
		}
		
		if ($siteId) {
			$db->where('site_id', $siteId);
		}
		
		$db->orderBy('timestamp', 'DESC');
		
		// pass limit to rs object if one exists
		$rs->setLimit($resultsPerPage);
			
		// pass page to rs object if one exists
		$rs->setPage($page);
		
		$results = $rs->generate($db);

		$rs->setLabels(array('id' => 'Domstream ID', 'page_url' => 'Page Url', 'duration' => 'Duration', 'timestamp' => 'Timestamp'));
		
		if ($format) {
			owa_lib::setContentTypeHeader($format);
			return $rs->formatResults($format);		
		} else {
			return $rs;
		}
	}
	
	// api method callback gets an individual domstream
	function getDomstream( $domstream_guid ) {
		
		if ( ! $domstream_guid ) {
			return;
		}
		// Fetch document object
		$d = owa_coreAPI::entityFactory('base.domstream');
		//$d->load($this->getParam('domstream_id'));
		//$json = new Services_JSON();
		//$d->set('events', $json->decode($d->get('events')));
		
		$db = owa_coreAPI::dbSingleton();
		$db->select('*');
		$db->from( $d->getTableName() );
		$db->where( 'domstream_guid', $domstream_guid );
		$db->orderBy('timestamp', 'ASC');
		$ret = $db->getAllRows();
		//print_r($ret);
		$combined = '';
		
		if ( $ret ) {
			// if rows then combine the events
			foreach ($ret as $row) {
				$combined = $this->mergeStreamEvents( htmlspecialchars_decode( $row['events'] ), $combined );
			}
			
			$row['events'] = json_decode( $combined  );
		} else {
			// no rows found for some reasonÉ..
			$error = 'No domstream rows found for domstream_guid: ' . $domstream_guid;
			owa_coreAPI::debug( $error );
			$row = array('errors' => $error);
		}
			
		$t = new owa_template;
		$t->set_template('json.php');
		//$json = new Services_JSON();
		// set
		
		// if not found look on the request scope.
		$callback = owa_coreAPI::getRequestParam('jsonpCallback');
		if ( ! $callback ) {
			
			$t->set('json', json_encode( $row ) );
		} else {
			$body = sprintf("%s(%s);", $callback, json_encode( $row ) );
			$t->set('json', $body);
		}
		return $t->fetch();	
	}
	
	function mergeStreamEvents($new, $old = '') {
    	
		if ( $old) {
			$old = json_decode($old);
		} else {
			$old = array();
		}
		owa_coreAPI::debug('old: '.print_r($old, true));
		$new = json_decode($new);
		owa_coreAPI::debug('new: '.print_r($new, true));
		//$combined = array_merge($old, $new);
		//array_splice($old, count($old), 0, $new);
		
		foreach ($new as $v) {
			$old[] = $v;
		}
		$combined = $old;
		owa_coreAPI::debug('combined: '.print_r($combined, true));
		owa_coreAPI::debug('combined count: '.count($combined));
		$combined = json_encode($combined);
		return $combined;
    	
    }
	
	
}