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

require_once(OWA_BASE_DIR.'/owa_module.php');

/**
 * Base Package Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_baseModule extends owa_module {
	
	/**
	 * Constructor
	 * 
	 */
	function __construct() {
		
		$this->name = 'base';
		$this->display_name = 'Open Web Analytics';
		$this->group = 'Base';
		$this->author = 'Peter Adams';
		$this->version = 9;
		$this->description = 'Base functionality for OWA.';
		$this->config_required = false;
		$this->required_schema_version = 9;
		
		// create event queues
		
		// register queue type implementations 
		$this->registerImplementation('event_queue_types', 'file', 'owa_fileEventQueue', OWA_MODULES_DIR.'base/classes/fileEventQueue.php');
		$this->registerImplementation('event_queue_types', 'database', 'owa_dbEventQueue', OWA_MODULES_DIR.'base/classes/dbEventQueue.php');
		$this->registerImplementation('event_queue_types', 'http', 'owa_httpEventQueue', OWA_MODULES_DIR.'base/classes/httpEventQueue.php');
		
		// register named queues
		$this->registerEventQueue( 'incoming_tracking_events', array(
			
			'queue_type'			=> 	'file',
			'path'					=>	owa_coreAPI::getSetting('base', 'async_log_dir'),
			'rotation_interval'		=> 3600
		));
				
		$this->registerEventQueue( 'processing', array(
			
			'queue_type'			=> 'database',
			'server'				=> owa_coreAPI::getSetting('base', 'db_host'),
			'port'					=> owa_coreAPI::getSetting('base', 'db_port'),
			'username'				=> owa_coreAPI::getSetting('base', 'db_user'),
			'password'				=> owa_coreAPI::getSetting('base', 'db_password')
		));
		
		$this->setupTrackingProperties();
		
		return parent::__construct();
	}
	
	/**
	 * Register Tracking Event Properties
	 *
	 *
	 */
	 
	 public function setupTrackingProperties() {
		 
		 $environmental = array(
									
			'REMOTE_HOST'		=> array(
				'callbacks'			=> array( 'owa_trackingEventHelpers::remoteHostDefault' ),
				'default_value'		=> '(not set)',
				'required'			=> true,
				'data_type'			=> 'string',
				'filter'			=> true
			),
				
			'HTTP_USER_AGENT'	=> array(
				'callbacks'			=> array( 'owa_trackingEventHelpers::userAgentDefault' ),
				'default_value'		=> '(not set)',
				'required'			=> true,
				'data_type'			=> 'string',
				'filter'			=> true
			),
				
			'HTTP_HOST'			=> array(
				'callbacks'			=> array( 'owa_trackingEventHelpers::httpHostDefault' ),
				'default_value'		=> '(not set)',
				'required'			=> true,
				'data_type'			=> 'string',
				'filter'			=> true
			),
				
			'language'			=> array(
				'callbacks'			=> array( 'owa_trackingEventHelpers::languageDefault' ),
				'default_value'		=> '(not set)',
				'required'			=> true,
				'data_type'			=> 'string',
				'filter'			=> true
			),
				
			'ip_address'		=> array(
				'callbacks'			=> array( 'owa_trackingEventHelpers::ipAddressDefault' ),
				'default_value'		=> '(not set)',
				'required'			=> true,
				'data_type'			=> 'string',
				'filter'			=> true
			),
				
			'timestamp'			=> array(
				'callbacks'			=> array( 'owa_trackingEventHelpers::timestampDefault' ),
				'default_value'		=> '(not set)',
				'required'			=> true,
				'data_type'			=> 'integer',
				'filter'			=> false
			),
				
			'microtime'			=> array(
				'callbacks'			=> array( 'owa_trackingEventHelpers::microtimeDefault' ),
				'default_value'		=> '(not set)',
				'required'			=> true,
				'data_type'			=> 'string',
				'filter'			=> false
			)
			
		);
		
		$this->registerTrackingProperties( 'environmental', $environmental );
		
		$regular = array(
	
			'page_type'						=> array(
				'default_value'					=> '(not set)',
				'required'						=> true,
				'data_type'						=> 'string'
			),
				
			'page_url'						=> array(
				'default_value'					=> '(not set)',
				'required'						=> true,
				'data_type'						=> 'url',
				'callbacks'						=> array( 'owa_trackingEventHelpers::makeUrlCanonical' )
			),
			
			'page_title' 					=> array(
				'required'						=> true,
				'callbacks'						=> array( 'owa_trackingEventHelpers::utfEncodeProperty' ),
				'data_type'						=> 'string',
				'default_value'					=> '(not set)'
			),
	
			'days_since_first_session' 		=> array(
				'required'						=> true,
				'callbacks'						=> array( ),
				'data_type'						=> 'integer',
				'default_value'					=> false,
				'alternative_key'				=> 'dsfs'
			),
			
			'days_since_prior_session' 		=> array(
				'required'						=> true,
				'callbacks'						=> array( ),
				'data_type'						=> 'integer',
				'default_value'					=> false,
				'alternative_key'				=> 'dsps'
			),
			
			'num_prior_sessions' 			=> array(
				'required'						=> true,
				'callbacks'						=> array( ),
				'data_type'						=> 'integer',
				'default_value'					=> false,
				'alternative_key'				=> 'nps'
			),
				
			'is_new_visitor'				=> array (
				'required'						=> true,
				'data_type'						=> 'boolean',
				' default_value'				=> false			
			),
			
			'user_name'						=> array(
				'required'						=> true,
				'callbacks'						=> array( 'owa_trackingEventHelpers::setUserName' ),
				'default_value'					=> '(not set)'
			),
			
			'user_email'					=> array(
				'required'						=> true,
				'callbacks'						=> array( 'owa_trackingEventHelpers::setEmailAddress' ),
				'default_value'					=> '(not set)',
				'alternative_key'				=> 'email_address'
			),
			
			'HTTP_REFERER'					=> array(
				'required'						=> false,
				'data_type'						=> 'url',
				'callbacks'						=> array()
			),
	
			'target_url'					=> array(
				'required'						=> false,
				'data_type'						=> 'url',
				'callbacks'						=> array( 'owa_trackingEventHelpers::makeUrlCanonical' )
			),
			
			'source'						=> array(
				'required'						=> true,
				'data_type'						=> 'string',
				'callbacks'						=> array( 'owa_trackingEventHelpers::lowercaseString' ),
				'default_value'					=> '(not set)'
			),
			
			'medium'						=> array(
				'required'						=> true,
				'data_type'						=> 'string',
				'callbacks'						=> array( 'owa_trackingEventHelpers::lowercaseString' ),
				'default_value'					=> '(not set)'
			),
			
			'session_referer'				=> array(
				'required'						=> false,
				'data_type'						=> 'url',
				'callbacks'						=> array()
			),
			// @todo investigate if this should be a required property so that a proper join can occur.
			'search_terms'					=> array(
				'required'						=> false,
				'callbacks'						=> array( 'owa_trackingEventHelpers::setSearchTerms' ),
				'default_value'					=> '(not set)'
			
			),
			
			'attribs'						=> array(
				'required'						=> false,
				'data_type'						=> 'json',
				'callbacks'						=> '',
				'default_value'					=> ''
			)
				
		);
		
		$this->registerTrackingProperties( 'regular', $regular );

		$derived = array(
	
			'year' 				=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveYear')
			),
				
			'month' 			=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveMonth')
			),
				
			'day' 				=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveDay')
			),
				
			'yyyymmdd' 			=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveYyyymmdd')
			),
				
			'dayofweek' 		=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveDayOfWeek')
			),
			
			'dayofyear' 		=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveDayOfYear')
			),
				
			'weekofyear' 		=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveWeekOfYear')
			),
				
			'hour' 				=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveHour')
			),
				
			'minute' 			=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveMinute')
			),
				
			'second' 			=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveSecond')
			),
				
			'sec' 				=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveSec')
			),
				
			'msec' 				=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::deriveMsec')
			),
	
			'page_uri' 			=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::derivePageUri', 'owa_trackingEventHelpers::makeUrlCanonical')
			),
		
			'is_repeat_visitor' => array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::setRepeatVisitorFlag')
			),
				
			'full_host'			=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::resolveFullHost'),
				'default_value'		=> '(not set)'
			),
			
			'host'				=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::getHostDomain'),
				'default_value'		=> '(not set)'
			),
			
			'browser_type'		=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::resolveBrowserType')
			),
			
			'is_browser'		=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::isBrowser'),
				'default_value'		=> false
			),
			
			'browser'			=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::resolveBrowserVersion'),
				'default_value'		=> '(unknown)'
			),
			
			'is_robot'			=> array(
				'required'			=> true,
				'callbacks'			=> array('owa_trackingEventHelpers::isRobot'),
				'default_value'		=> false
			),
			
			'os'				=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::resolveOs' ),
				'default_value'		=> '(unknown)'
			),
			
			'is_entry_page'		=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::resolveEntryPage' ),
				'default_value'		=> false
			),
			
			'country'			=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::resolveCountry' ),
				'default_value'		=> false
			),
			
			'city'				=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::resolveCity' ),
				'default_value'		=> false
			),
			
			'state'				=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::resolveState' ),
				'default_value'		=> false
			),
			
			'latitude'			=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::resolveLatitude' ),
				'default_value'		=> false
			),
	
			'longitude'			=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::resolveLongitude' ),
				'default_value'		=> false
			),
			
			'country_code'		=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::resolveCountryCode' ),
				'default_value'		=> false
			),
			
			'prior_page'		=> array(
				'required'			=> true,
				'callbacks'			=> array( 'owa_trackingEventHelpers::setPriorPage', 'owa_trackingEventHelpers::makeUrlCanonical' ),
				'default_value'		=> false
			),	
			//related object IDs
			/* @todo these should really be moved to handlers and logic encoded in entity objects.*/
		
			'document_id' 		=> array(
			
				'alternative_key'	=> 'page_url',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			),
			
			'ua_id' 			=> array(
			
				'alternative_key'	=> 'HTTP_USER_AGENT',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			),
			
			'location_id' 		=> array(
			
				'alternative_key'	=> 'country',
				'callbacks'			=> 'owa_trackingEventHelpers::generateLocationId'
			),
			
			'host_id' 			=> array(
			
				'alternative_key'	=> 'host',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			),
			
			'os_id' 			=> array(
			
				'alternative_key'	=> 'os',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			),
			
			'campaign_id' 		=> array(
			
				'alternative_key'	=> 'campaign',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			),
			
			'ad_id' 			=> array(
			
				'alternative_key'	=> 'ad',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			),
			
			'source_id' 		=> array(
			
				'alternative_key'	=> 'source',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			),
	
			'referer_id' 		=> array(
			
				'alternative_key'	=> 'session_referer',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			),		
			
			'referring_search_term_id' => array(
			
				'alternative_key'	=> 'search_terms',
				'callbacks'			=> 'owa_trackingEventHelpers::generateDimensionId'
			)
		);
		
		$this->registerTrackingProperties( 'derived', $derived );

	 }
	
	/**
	 * Register Filters
	 *
	 * The following lines register filter methods. 
	 */
	function registerFilters() {
				
		// I dont think this is used any more as logic is not in the tracking clients
		//$this->registerFilter('attributed_campaign', $this, 'attributeCampaign', 10);
		
		// @TODO hostip appears to be defunk. remove this block completely at some point.
		//if ( owa_coreAPI::getSetting('base', 'geolocation_service') === 'hostip' ) {
		
			//$this->registerFilter('geolocation', 'hostip', 'get_location', 10, 'classes');			
		//}


		if ( defined( 'OWA_MAIL_EXCEPTIONS' ) ) {
		
			$this->registerFilter('post_processed_tracking_event', $this, 'checkEventForType');
		}
		
		if ( owa_coreAPI::getSetting( 'base', 'anonymize_ips' ) ) {
			
			$this->registerFilter('post_processed_tracking_event', $this, 'anonymizeIpAddress');
		}
		
		$this->registerFilter('tracker_tag_cmds', $this, 'addTrackerCmds', 0);
		
		
	}
	
	function addTrackerCmds( $cmds ) {
		
		$cmds[] = "owa_cmds.push(['trackPageView']);";
		$cmds[] = "owa_cmds.push(['trackClicks']);";
		
		return $cmds;
	}
		
	/**
	 * Register Background jobs
	 *
	 * The following lines register background jobs used by the
	 * background daemon. 
	 */
	function registerBackgroundJobs() {
	
		// event procesing daemon jobs
		$this->registerBackgroundJob('process_event_queue', 'cli.php cmd=processEventQueue', owa_coreAPI::getSetting('base', 'processQueuesJobSchedule'), 10);
	
	}
	
	
	/**
	 * Register CLI Commands
	 *
	 * The following lines register various command line interface (CLI) controller. 
	 */
	function registerCliCommands() {
			
		$this->registerCliCommand('update', 'base.updatesApplyCli');
		$this->registerCliCommand('build', 'base.build');
		$this->registerCliCommand('flush-cache', 'base.flushCacheCli');
		$this->registerCliCommand('processEventQueue', 'base.processEventQueue');
		$this->registerCliCommand('install', 'base.installCli');
		$this->registerCliCommand('activate', 'base.moduleActivateCli');
		$this->registerCliCommand('deactivate', 'base.moduleDeactivateCli');
		$this->registerCliCommand('install-module', 'base.moduleInstallCli');
		$this->registerCliCommand('add-site', 'base.sitesAddCli');
		$this->registerCliCommand('flush-processed-events', 'base.flushProcessedEventsCli');
		$this->registerCliCommand('prune-event-queue-archives', 'base.pruneEventQueueArchivesCli');
	}
	
	/**
	 * Register API methods
	 *
	 * The following lines register various API methods. 
	 */
	function registerApiMethods() {
			
		$this->registerApiMethod('getResultSet', 
				array($this, 'getResultSet'), 
				array(
					'metrics', 
					'dimensions', 
					'siteId', 
					'constraints', 
					'sort', 
					'resultsPerPage', 
					'page', 
					'offset', 
					'period', 
					'startDate', 
					'endDate', 
					'startTime', 
					'endTime', 
					'format',
					'segment'), 
				'', 
				'view_reports'
		);
		
		
		$this->registerApiMethod('getLatestVisits', 
				array($this, 'getLatestVisits'), 
				array( 'startDate', 'endDate', 'visitorId', 'siteId', 'resultsPerPage', 'page', 'format'), 
				'', 
				'view_reports'
		);
		
		$this->registerApiMethod('getClickstream', 
				array($this, 'getClickstream'), 
				array( 'sessionId', 'resultsPerPage', 'page','format'),
				'', 
				'view_reports'
		);
		
		$this->registerApiMethod('getVisitDetail', 
				array($this, 'getVisitDetail'), 
				array( 'sessionId', 'format'),
				'', 
				'view_reports'
		);
		
		$this->registerApiMethod('getTransactionDetail', 
				array($this, 'getTransactionDetail'), 
				array( 'transactionId', 'format'),
				'', 
				'view_reports'
		);
		
		$this->registerApiMethod('getDomClicks', 
				array($this, 'getDomClicks'), 
				array(
					'pageUrl', 
					'siteId', 
					'startDate', 
					'endDate', 
					'document_id', 
					'period',
					'resultsPerPage', 
					'page',
					'format'
				),
				'', 
				'view_reports'
		);
		
		$this->registerApiMethod('getTransactions', 
				array($this, 'getTransactions'), 
				array( 
					'siteId', 
					'startDate', 
					'endDate', 
					'period',
					'sort',
					'resultsPerPage', 
					'page',
					'format'
				),
				'', 
				'view_reports'
		);
		
		$this->registerApiMethod('getLatestActions', 
				array($this, 'getLatestActions'), 
				array('startDate', 'endDate', 'visitorId', 'sessionId', 'action_group','siteId','resultsPerPage', 'page', 'format'),
				'', 
				'view_reports' 
		);
	}
	
	/**
	 * Registers Admin panels
	 *
	 */
	function registerAdminPanels() {
		
		$this->addAdminPanel(array(
				'do' 			=> 'base.optionsGeneral', 
				'priviledge' 	=> 'admin', 
				'anchortext' 	=> 'Main Configuration',
				'group'			=> 'General',
				'order'			=> 1)
		);
		
		$this->addAdminPanel(array(
				'do' 			=> 'base.users', 
				'priviledge' 	=> 'admin', 
				'anchortext' 	=> 'User Management',
				'group'			=> 'General',
				'order'			=> 2)
		);
									
		
									
		$this->addAdminPanel(array(
				'do' 			=> 'base.sites', 
				'priviledge' 	=> 'admin', 
				'anchortext' 	=> 'Tracked Sites',
				'group'			=> 'General',
				'order'			=> 3)
		);
								
		$this->addAdminPanel(array(
				'do' 			=> 'base.optionsModules', 
				'priviledge' 	=> 'admin', 
				'anchortext' 	=> 'Modules',
				'group'			=> 'General',
				'order'			=> 3)
		);		
		
		/*
		$this->addAdminPanel(array(
				'do' 			=> 'base.optionsGoals', 
				'priviledge' 	=> 'admin', 
				'anchortext' 	=> 'Goal Settings',
				'group'			=> 'General',
				'order'			=> 3)
		);	
		*/	
	}
	
	
	/**
	 * Register Metrics
	 *
	 * The following lines register various data metrics. 
	 */
	function registerMetrics() {		
		
		$fact_table_entities = array(
			'base.session',
			'base.request',
			'base.action_fact',
			'base.domstream',
			'base.click',
			'base.commerce_transaction_fact',
			'base.commerce_line_item_fact'
		);
		
		// page views
		$this->registerMetricDefinition(array(
			'name'			=> 'pageViews',
			'label'			=> 'Page Views',
			'description'	=> 'The total number of pages viewed.',
			'group'			=> 'Site Usage',
			'entity'		=> 'base.request',
			'metric_type'	=> 'count',
			'data_type'		=> 'integer',
			'column'		=> 'id'
			
		));
		
		$this->registerMetricDefinition(array(
			'name'			=> 'pageViews',
			'label'			=> 'Page Views',
			'description'	=> 'The total number of pages viewed.',
			'group'			=> 'Site Usage',
			'entity'		=> 'base.session',
			'metric_type'	=> 'sum',
			'data_type'		=> 'integer',
			'column'		=> 'num_pageviews'
			
		));
		
		
		// unique visitors
		foreach($fact_table_entities as $factEntity ) {
		
			$this->registerMetricDefinition(array(
				'name'			=> 'uniqueVisitors',
				'label'			=> 'Unique Visitors',
				'description'	=> 'The total number of unique visitors.',
				'group'			=> 'Site Usage',
				'entity'		=> $factEntity,
				'metric_type'	=> 'distinct_count',
				'data_type'		=> 'integer',
				'column'		=> 'visitor_id'
				
			));
		}
		
		// visits

		
			// owa_session uses a different column name and has it's own metric registration above.
				$this->registerMetricDefinition(array(
					'name'			=> 'visits',
					'label'			=> 'Visits',
					'description'	=> 'The total number of visits/sessions.',
					'group'			=> 'Site Usage',
					'entity'		=> 'base.session',
					'metric_type'	=> 'distinct_count', // 'count', 'distinct_count', 'sum', or 'calculated'
					'data_type'		=> 'integer', // 'integrer', 'currency'
					'column'		=> 'id'
					
				));
			
				$this->registerMetricDefinition(array(
					'name'			=> 'visits',
					'label'			=> 'Visits',
					'description'	=> 'The total number of visits/sessions.',
					'group'			=> 'Site Usage',
					'entity'		=> 'base.request',
					'metric_type'	=> 'distinct_count', // 'count', 'distinct_count', 'sum', or 'calculated'
					'data_type'		=> 'integer', // 'integrer', 'currency'
					'column'		=> 'session_id'
					
				));
		
		$this->registerMetric(
			'visitors', 
			array(
				'base.visitors',
				'base.visitorsFromRequestFact'
			),
			'',
			'Visitors',
			'The total number of visitors',
			'Site Usage'
		);
		
		$this->registerMetric(
			'newVisitors', 
			'base.newVisitors',
			'',
			'New Visitors',
			'The total number of new visitors',
			'Site Usage'
		);
		
		$this->registerMetric(
			'repeatVisitors', 
			'base.repeatVisitors',
			'',
			'Repeat Visitors',
			'The total number of repeat visitors',
			'Site Usage'
		);
		
		$this->registerMetric(
			'bounces', 
			'base.bounces',
			'',
			'Bounces',
			'The total number of visits with a single page view',
			'Site Usage'
		);
		
		$this->registerMetric(
			'visitDuration', 
			'base.visitDuration',
			'',
			'Visit Duration',
			'The average duration of visits.',
			'Site Usage'
		);
		
		$this->registerMetric(
			'uniquePageViews', 
			'base.uniquePageViews',
			'',
			'Unique Page Views',
			'The total number of unique pages viewed.',
			'Site Usage'
		);
		
		$this->registerMetric(
			'bounceRate', 
			'base.bounceRate',
			'',
			'Bounce Rate',
			'The percentage of visits that were bounces.',
			'Site Usage'
		);
		
		$this->registerMetric(
			'pagesPerVisit', 
			'base.pagesPerVisit',
			'',
			'Pages Per Visit',
			'The average pages viewed per visit.',
			'Site Usage'
		);
		
		$this->registerMetric(
			'actions', 
			'base.actions',
			'',
			'Actions',
			'The total number of action events.',
			'Actions'
		);
		
		$this->registerMetric(
			'uniqueActions', 
			'base.uniqueActions',
			'',
			'Unique Actions',
			'Total number of unique action events.',
			'Actions'
		);
		
		$this->registerMetric(
			'actionsValue', 
			'base.actionsValue',
			'',
			'Action Value',
			'Total value of all action events.',
			'Actions'
		);
		
		$this->registerMetric(
			'feedRequests', 
			'base.feedRequests',
			'',
			'Feed Requests',
			'Total number of feed requests.',
			'Feeds'
		);
		
		$this->registerMetric(
			'feedReaders', 
			'base.feedReaders',
			'',
			'Feed Readers',
			'Total number of feed readers.',
			'Feeds'
		);
		
		$this->registerMetric(
			'feedSubscriptions', 
			'base.feedSubscriptions',
			'',
			'Feed Subscriptions',
			'Total number of feed subscribers.',
			'Feeds'
		);
		
		// goals
		$gcount = owa_coreAPI::getSetting('base', 'numGoals');
		for ($num = 1; $num <= $gcount;$num++) {
			$params = array('goal_number' => $num);
			
			$metric_name = 'goal'.$num.'Completions';
			$this->registerMetric(
				$metric_name, 
				'base.goalNCompletions', 
				$params,
				"Goal $num Completions",
				"The total number of goal $num completions.",
				'Goals'
			);
			
			$metric_name = 'goal'.$num.'Starts';
			$this->registerMetric(
				$metric_name, 
				'base.goalNStarts', 
				$params,
				"Goal $num Starts",
				"The total number of goal $num starts.",
				'Goals'
			);
			
			$metric_name = 'goal'.$num.'Value';
			$this->registerMetric(
				$metric_name, 
				'base.goalNValue', 
				$params,
				"Goal $num Value",
				"The total value of goal $num achieved.",
				'Goals'
			);
		}
		
		$this->registerMetric(
			'goalCompletionsAll', 
			'base.goalCompletionsAll',
			'',
			'Goal Completions',
			'The total number of goal completions.',
			'Goals'
		);
		
		$this->registerMetric(
			'goalStartsAll', 
			'base.goalStartsAll',
			'',
			'Goal Starts',
			'The total number of goal starts.',
			'Goals'
		);
		
		$this->registerMetric(
			'goalValueAll', 
			'base.goalValueAll',
			'',
			'Goal Value',
			'The total value of all goals achieved.',
			'Goals'
		);
		
		$this->registerMetric(
			'goalConversionRateAll', 
			'base.goalConversionRateAll',
			'',
			'Goal Conversion Rate',
			'The rate of goals achieved in all visits.',
			'Goals'
		);
		
		$this->registerMetric(
			'goalAbandonRateAll', 
			'base.goalAbandonRateAll',
			'',
			'Goal Abandon Rate',
			'The rate of goal abandons in all visits.',
			'Goals'
		);
		
		// ecommerce metrics
		$this->registerMetric(
			'lineItemQuantity', 
			array(
				'base.lineItemQuantity',
				'base.lineItemQuantityFromSessionFact'
			),
			'',
			'Item Quantity',
			'The total umber of items purchased.',
			'E-commerce'
		);
		
		$this->registerMetric(
			'lineItemRevenue', 
			array(
				'base.lineItemRevenue',
				'base.lineItemRevenueFromSessionFact'
			),
			'',
			'Item Revenue',
			'Total revenue from items purchased.',
			'E-commerce'
		);
				
		$this->registerMetric(
			'transactions', 
			array(
				'base.transactions',
				'base.transactionsFromSessionFact'
			),
			'',
			'Transactions',
			'Total number of transactions.',
			'E-commerce'
		);
		
		$this->registerMetric(
			'transactionRevenue', 
			array(
				'base.transactionRevenue',
				'base.transactionRevenueFromSessionFact'
			),
			'',
			'Revenue',
			'Total revenue from all transactions.',
			'E-commerce'
		);
		
		$this->registerMetric(
			'taxRevenue', 
			array(
				'base.taxRevenue',
				'base.taxRevenueFromSessionFact'
			),
			'',
			'Tax Revenue',
			'Total revenue from taxes.',
			'E-commerce'
		);
		
		$this->registerMetric(
			'shippingRevenue', 
			array(
				'base.shippingRevenue',
				'base.shippingRevenueFromSessionFact'
			),
			'',
			'Shipping Revenue',
			'Total revenue from shipping.',
			'E-commerce'
		);
		
		$this->registerMetric(
			'uniqueLineItems', 
			array(
				'base.uniqueLineItems',
				'base.uniqueLineItemsFromSessionFact'
			),
			'',
			'Unique Items',
			'Total number of unique items purchased.',
			'E-commerce'
		);
				
		$this->registerMetric(
			'revenuePerTransaction', 
			'base.revenuePerTransaction',
			'',
			'Revenue Per Transaction',
			'Average revenue per transaction.',
			'E-commerce'
		);
		
		$this->registerMetric(
			'revenuePerVisit', 
			'base.revenuePerVisit',
			'',
			'Revenue Per Visit',
			'Average revenue generated per visit.',
			'E-commerce'
		);
		
		$this->registerMetric(
			'ecommerceConversionRate', 
			'base.ecommerceConversionRate',
			'',
			'E-commerce Conversion Rate',
			'The rate of visits that resulted in an e-commerce transaction.',
			'E-commerce');
		
		$this->registerMetric(
			'domClicks', 
			'base.domClicks',
			'',
			'Clicks',
			'Total number of clicks on DOM elements.',
			'Clicks'
		);
	}
		
	/**
	 * Register Dimensions
	 *
	 * The following lines register various data dimensions.
	 * To register a dimenison use the registerDimension method.
	 * See owa_module class for documentation on this method.
	 */
	function registerDimensions() {
		
		// fact table entity names used by a number of dimensions.
		$fact_table_entities = array(
			'base.action_fact',
			'base.request',
			'base.session',
			'base.domstream',
			'base.click',
			'base.commerce_transaction_fact',
			'base.commerce_line_item_fact'
		);
		
		
		// Time Dimensions
		$this->registerDimension(
			'date', 
			$fact_table_entities, 
			'yyyymmdd', 
			'Date', 
			'time', 
			'The full date.', 
			'', 
			true, 
			'yyyymmdd'
		);
		
		$this->registerDimension(
			'day', 
			$fact_table_entities, 
			'day', 
			'Day', 
			'time', 
			'The day of the month (1-31).', 
			'', 
			true
		);
		
		$this->registerDimension(
			'month', 
			$fact_table_entities, 
			'month', 
			'Month', 
			'time', 
			'The month of the year (1-12).', 
			'', 
			true,
			'yyyymm'
		);
		
		$this->registerDimension(
			'year', 
			$fact_table_entities, 
			'year', 
			'Year', 
			'time', 
			'The year.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'dayofweek', 
			$fact_table_entities, 
			'dayofweek', 
			'Day of Week', 
			'time', 
			'The day of the week (1-7).', 
			'', 
			true);
			
		$this->registerDimension(
			'dayofyear', 
			$fact_table_entities, 
			'dayofyear', 
			'Day of Year', 
			'time', 
			'The day of the year (1-365).', 
			'', 
			true
		);
		
		$this->registerDimension(
			'weekofyear', 
			$fact_table_entities, 
			'weekofyear', 
			'Week of Year', 
			'time', 
			'The week of the year (1-52).', 
			'', 
			true
		);
		
		$this->registerDimension(
			'date', 
			'base.feed_request', 
			'yyyymmdd', 
			'Date', 
			'time', 
			'The date.', 
			'', 
			true, 
			'yyyymmdd'
		);
		
		$this->registerDimension(
			'day', 
			'base.feed_request', 
			'day', 
			'Day', 
			'time', 
			'The day.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'month', 
			'base.feed_request', 
			'month', 
			'Month', 
			'time', 
			'The month.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'year', 
			'base.feed_request', 
			'year', 
			'Year', 
			'time', 
			'The year.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'dayofweek', 
			'base.feed_request', 
			'dayofweek', 
			'Day of Week', 
			'time', 
			'The day of the week.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'dayofyear', 
			'base.feed_request', 
			'dayofyear', 
			'Day of Year', 
			'time', 
			'The day of the year.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'weekofyear', 
			'base.feed_request', 
			'weekofyear', 
			'Week of Year', 
			'date', 
			'The week of the year.', 
			'', 
			true
		);
		
		// Site Dimensions
		$this->registerDimension(
			'siteId', 
			$fact_table_entities, 
			'site_id', 
			'Site ID', 
			'site', 
			'The ID of the the web site.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'siteDomain', 
			'base.site', 
			'domain', 
			'Site Domain', 
			'site', 
			'The domain of the web site.'
		);
		
		$this->registerDimension(
			'siteName', 
			'base.site', 
			'name', 
			'Site Name', 
			'site', 
			'The name of the site.'
		);
		
		/*
		$this->registerDimension(
			'siteId', 
			'base.site', 
			'site_id', 
			'Site ID', 
			'site', 
			'The ID of the site.'
		);
		*/
		
		$this->registerDimension(
			'siteId', 
			'base.feed_request', 
			'site_id', 
			'Site ID', 
			'site', 
			'The ID of the the web site.', 
			'', 
			true
		);
		
		// Visitor Dimensions
		$this->registerDimension(
			'visitorId', 
			'base.visitor', 
			'id', 
			'Visitor ID', 
			'visitor', 
			'The ID of the visitor.'
		);
		
		$this->registerDimension(
			'userName', 
			$fact_table_entities, 
			'user_name', 
			'User Name', 
			'visitor', 
			'The name or ID of the user.'
		);
		
		$this->registerDimension(
			'userEmail', 
			'base.visitor', 
			'user_email', 
			'Email Address', 
			'visitor', 
			'The email address of the user.'
		);
		
		$this->registerDimension(
			'isRepeatVisitor', 
			$fact_table_entities, 
			'is_repeat_visitor', 
			'Repeat Visitor', 
			'visitor', 
			'Repeat Site Visitor.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'isNewVisitor', 
			$fact_table_entities, 
			'is_new_visitor', 
			'New Visitor', 
			'visitor', 
			'New Site Visitor.', 
			'', 
			true
		);
		
		// Visit/Session Dimensions
		$this->registerDimension(
			'sessionId', 
			'base.session', 
			'id', 
			'Session ID', 
			'visit-special', 
			'The ID of the session/visit.'
		);
		
		$this->registerDimension(
			'entryPageUrl', 
			'base.document', 
			'url', 
			'Entry Page URL', 
			'visit', 
			'The URL of the entry page.', 
			'first_page_id'
		);
		
		$this->registerDimension(
			'entryPagePath', 
			'base.document', 
			'uri', 
			'Entry Page Path', 
			'visit', 
			'The URI of the entry page.', 
			'first_page_id'
		);
		
		$this->registerDimension(
			'entryPageTitle', 
			'base.document', 
			'page_title', 
			'Entry Page Title', 
			'visit', 
			'The title of the entry page.', 
			'first_page_id'
		);
		
		$this->registerDimension(
			'entryPageType', 
			'base.document', 
			'page_type', 
			'Entry Page Type', 
			'visit', 
			'The page type of the entry page.', 
			'first_page_id'
		);
		
		$this->registerDimension(
			'exitPageUrl', 
			'base.document', 
			'url', 
			'Exit Page URL', 
			'visit', 
			'The URL of the exit page.', 
			'last_page_id'
		);
		
		$this->registerDimension(
			'exitPagePath', 
			'base.document', 
			'uri', 
			'Exit Page Path', 
			'visit', 
			'The URI of the exit page.', 
			'last_page_id'
		);
		
		$this->registerDimension(
			'exitPageTitle', 
			'base.document', 
			'page_title', 
			'Exit Page Title', 
			'visit', 
			'The title of the exit page.', 
			'last_page_id'
		);
		
		$this->registerDimension(
			'exitPageType', 
			'base.document', 
			'page_type', 
			'Exit Page Type', 
			'visit', 
			'The page type of the exit page.', 
			'last_page_id'
		);
		
		$this->registerDimension(
			'timeSinceLastVisit', 
			'base.session', 
			'time_sinse_priorsession', 
			'Time Since Last Visit', 
			'visit-special', 
			'The time since the last visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'daysSinceLastVisit', 
			$fact_table_entities, 
			'days_since_prior_session', 
			'Days Since Last Visit', 
			'visit', 
			'The number of days since the last visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'daysSinceFirstVisit', 
			$fact_table_entities, 
			'days_since_first_session', 
			'Days Since First Visit', 
			'visit', 
			'The number of days since the first visit of the user.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'priorVisitCount', 
			$fact_table_entities, 
			'num_prior_sessions', 
			'Prior Visits', 
			'visit', 
			'The number of prior visits, excluding the current one.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'pagesViewsInVisit', 
			'base.session', 
			'num_pageviews', 
			'Pages Viewed In Visit', 
			'visit', 
			'The number of pages viewed in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'revenueInVisit', 
			'base.session', 
			'commerce_trans_revenue', 
			'Revenue in Visit', 
			'visit', 
			'Revenue generate from e-commerce transactions in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'itemRevenueInVisit', 
			'base.session', 
			'commerce_item_revenue', 
			'Item Revenue in Visit', 
			'visit', 
			'Revenue generate from e-commerce transaction items in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'shippingRevenueInVisit', 
			'base.session', 
			'commerce_shipping_revenue', 
			'Shipping Revenue in Visit', 
			'visit', 
			'Revenue generate from e-commerce shipping in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'taxRevenueInVisit', 
			'base.session', 
			'commerce_tax_revenue', 
			'Tax Revenue in Visit', 
			'visit', 
			'Revenue generate from e-commerce tax in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'transactionsInVisit', 
			'base.session', 
			'commerce_trans_count', 
			'Transactions in Visit', 
			'visit', 
			'Number of e-commerce transactions completed in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'itemQuantityInVisit', 
			'base.session', 
			'commerce_items_quantity', 
			'Item Quantity in Visit', 
			'visit', 
			'Number of e-commerce items purchased completed in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'distinctItemsInVisit', 
			'base.session', 
			'commerce_items_count', 
			'Distinct Items in Visit', 
			'visit', 
			'Number of distinct items purchased in Visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'goalsInVisit', 
			'base.session', 
			'num_goals', 
			'Goals in Visit', 
			'visit', 
			'Goals completed in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'goalStartsInVisit', 
			'base.session', 
			'num_goal_starts', 
			'Goal Starts in Visit', 
			'visit', 
			'Goals started in a visit.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'goalValueInVisit', 
			'base.session', 
			'goals_value', 
			'Goal Value in Visit', 
			'visit', 
			'Total value from all goals in a visit.', 
			'',
			true
		);
		
		
		
		// System/Technology Dimensions
		$this->registerDimension(
			'browserVersion', 
			'base.ua', 
			'browser', 
			'Browser Version', 
			'system', 
			'The browser version of the visitor.'
		);
		
		$this->registerDimension(
			'browserType', 
			'base.ua', 
			'browser_type', 
			'Browser Type', 
			'system', 
			'The browser type of the visitor.'
		);
		
		$this->registerDimension(
			'osType', 
			'base.os', 
			'name', 
			'Operating System', 
			'system', 
			'The operating System of the visitor.'
		);
		
		$this->registerDimension(
			'language', 
			$fact_table_entities, 
			'language', 
			'Language', 
			'system', 
			'The language of the visit.', 
			'', 
			true
		);
		
		// Geo Dimensions
		$this->registerDimension(
			'city', 
			'base.location_dim', 
			'city', 
			'City', 
			'geo', 
			'The city of the visitor.'
		);
		
		$this->registerDimension(
			'country', 
			'base.location_dim', 
			'country', 
			'Country', 
			'geo', 
			'The country of the visitor.'
		);
		
		$this->registerDimension(
			'latitude', 
			'base.location_dim', 
			'latitude', 
			'Latitude', 
			'geo', 
			'The latitude of the visitor.'
		);
		
		$this->registerDimension(
			'longitude', 
			'base.location_dim', 
			'longitude', 
			'Longitude', 
			'geo', 
			'The longitude of the visitor.'
		);
		
		$this->registerDimension(
			'countryCode', 
			'base.location_dim', 
			'country_code', 
			'Country Code', 
			'geo', 
			'The ISO country code of the visitor.'
		);
		
		$this->registerDimension(
			'stateRegion', 
			'base.location_dim', 
			'state', 
			'State/Region', 
			'geo', 
			'The state or region of the visitor.'
		);
		
		// Network Dimensions
		$this->registerDimension(
			'ipAddress', 
			$fact_table_entities, 
			'ip_address', 
			'IP Address', 
			'network', 
			'The IP address of the visitor.',
			'',
			true
		);
		
		$this->registerDimension(
			'hostName', 
			'base.host', 
			'host', 
			'Host Name', 
			'network', 
			'The host name of the network used by the visitor.'
		);
		
		// Campaign Dimensions
		$this->registerDimension(
			'medium', 
			$fact_table_entities,
			'medium', 
			'Medium', 
			'campaign', 
			'The medium where visit originated from.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'source', 
			'base.source_dim', 
			'source_domain', 
			'Source', 
			'campaign', 
			'The traffic source of the visit.'
		);
		
		$this->registerDimension(
			'campaign', 
			'base.campaign_dim', 
			'name', 
			'Campaign', 
			'campaign', 
			'The campaign that originated the visit.'
		);
		
		$this->registerDimension(
			'ad', 
			'base.ad_dim', 
			'name', 
			'Ad', 
			'campaign', 
			'The name of the ad that originated the visit.'
		);
		
		$this->registerDimension(
			'adType', 
			'base.ad_dim', 
			'type', 
			'Ad Type', 
			'campaign', 
			'The type of ad that originated the visit.'
		);
		
		$this->registerDimension(
			'referralPageUrl', 
			'base.referer', 
			'url', 
			'Referral Page URL', 
			'campaign', 
			'The url of the referring web page.'
		);
		
		$this->registerDimension(
			'referralPageTitle', 
			'base.referer', 
			'page_title', 
			'Referral Page Title', 
			'campaign', 
			'The title of the referring web page.'
		);
		
		$this->registerDimension(
			'referralSearchTerms', 
			'base.search_term_dim', 
			'terms', 
			'Search Terms', 
			'campaign', 
			'The referring search terms.', 
			'referring_search_term_id'
		);
		
		$this->registerDimension(
			'referralLinkText', 
			'base.referer', 
			'refering_anchortext', 
			'Referral Link Text', 
			'campaign', 
			'The text of the referring link.'
		);
		
		$this->registerDimension(
			'isSearchEngine', 
			'base.referer', 
			'is_searchengine', 
			'Search Engine', 
			'campaign', 
			'Is traffic source a search engine.'
		);
		
		$this->registerDimension(
			'referralWebSite', 
			'base.referer', 
			'site', 
			'Referral Web Site', 
			'campaign', 
			'The full domain of the referring web site.'
		);
		
		$this->registerDimension(
			'latestAttributions', 
			'base.session', 
			'latest_attributions', 
			'Latest Attributions', 
			'campaign-special', 
			'The latest campaign attributions.', 
			'', 
			true
		);
		
		// Page Content
		$this->registerDimension(
			'priorPageUrl', 
			'base.document', 
			'url', 
			'Prior Page URL', 
			'content', 
			'The URL of the prior page.', 
			'prior_document_id'
		);
		
		$this->registerDimension(
			'priorPagePath', 
			'base.document', 
			'uri', 
			'Prior Page Path', 
			'content', 
			'The URI of the prior page.', 
			'prior_document_id'
		);
		
		$this->registerDimension(
			'priorPageTitle', 
			'base.document', 
			'page_title', 
			'Prior Page Title', 
			'content', 
			'The title of the prior page.', 
			'prior_document_id'
		);
		
		$this->registerDimension(
			'priorPageType', 
			'base.document', 
			'page_type', 
			'Prior Page Type', 
			'content', 
			'The page type of the prior page.', 
			'prior_document_id'
		);
		
		$this->registerDimension(
			'pageUrl', 
			'base.document', 
			'url', 
			'Page URL', 
			'content', 
			'The URL of the web page.', 
			'document_id'
		);
		
		$this->registerDimension(
			'pagePath', 
			'base.document', 
			'uri', 
			'Page Path', 
			'content', 
			'The path of the web page.', 
			'document_id'
		);
		
		$this->registerDimension(
			'pageTitle', 
			'base.document', 
			'page_title', 
			'Page Title', 
			'content', 
			'The title of the web page.', 
			'document_id'
		);
		
		$this->registerDimension(
			'pageType', 
			'base.document', 
			'page_type', 
			'Page Type', 
			'content', 
			'The page type of the web page.', 
			'document_id'
		);
		
		// Action Event Dimensions
		$this->registerDimension(
			'actionName', 
			'base.action_fact', 
			'action_name', 
			'Action Name', 
			'actions', 
			'The name of the action.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'actionGroup', 
			'base.action_fact', 
			'action_group', 
			'Action Group', 
			'actions', 
			'The group that an action belongs to.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'actionLabel', 
			'base.action_fact', 
			'action_label', 
			'Action Label', 
			'actions', 
			'The label associated with an action.', 
			'', 
			true
		);	
		
		// Ecommerce Dimensions
		$this->registerDimension(
			'productName', 
			'base.commerce_line_item_fact', 
			'product_name', 
			'Product Name', 
			'ecommerce', 
			'The name of the product purchased.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'productSku', 
			'base.commerce_line_item_fact', 
			'sku', 
			'Product SKU', 
			'ecommerce', 
			'The SKU code of the product purchased.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'productCategory', 
			'base.commerce_line_item_fact', 
			'category', 
			'Product Category', 
			'ecommerce', 
			'The category of product purchased.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'transactionOriginator', 
			'base.commerce_transaction_fact', 
			'order_source', 
			'Originator', 
			'ecommerce', 
			'The store or location that originated the transaction.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'transactionId', 
			'base.commerce_transaction_fact', 
			'order_id', 
			'Transaction ID', 
			'ecommerce', 
			'The id of the e-commerce transaction.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'transactionGateway', 
			'base.commerce_transaction_fact', 
			'gateway', 
			'Payment Gateway', 
			'ecommerce', 
			'The payment gateway/provider used to clear the transaction.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'daysToTransaction', 
			'base.commerce_transaction_fact', 
			'days_since_first_session', 
			'Days To Purchase', 
			'ecommerce', 
			'The number of days since the first visit and an e-commerce transaction.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'daysToTransaction', 
			'base.commerce_transaction_fact', 
			'days_since_first_session', 
			'Days To Purchase', 
			'ecommerce', 
			'The number of days since the first visit and an e-commerce transaction.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'visitsToTransaction', 
			'base.commerce_transaction_fact', 
			'num_prior_sessions', 
			'Visits To Purchase', 
			'ecommerce', 
			'The number of visits before the transaction occurred.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'visitsToTransaction', 
			'base.commerce_transaction_fact', 
			'num_prior_sessions', 
			'Visits To Purchase', 
			'ecommerce', 
			'The number of visits prior to an e-commerce transaction.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'timestamp', 
			'base.commerce_transaction_fact', 
			'timestamp', 
			'Time', 
			'ecommerce-special', 
			'The timestamp of the transaction.', 
			'', 
			true
		);
		
		// Click Dimensions
		$this->registerDimension(
			'domElementId', 
			'base.click', 
			'dom_element_id', 
			'Dom ID', 
			'dom', 
			'The id of the dom element.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'domElementName', 
			'base.click', 
			'dom_element_name', 
			'Dom Name', 
			'dom', 
			'The name of the dom element.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'domElementText', 
			'base.click', 
			'dom_element_text', 
			'Dom Text', 
			'dom', 
			'The text associated the dom element.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'domElementValue', 
			'base.click', 
			'dom_element_value', 
			'Dom Value', 
			'dom', 
			'The value of the dom element.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'domElementTag', 
			'base.click', 
			'dom_element_tag', 
			'Dom Tag', 
			'dom', 
			'The html tag of the dom element.', 
			'', 
			true
		);
		
		$this->registerDimension(
			'domElementClass', 
			'base.click', 
			'dom_element_class', 
			'Dom Class', 
			'dom', 
			'The class of the dom element.', 
			'', 
			true
		);
			
		// Feed Dimensions
		$this->registerDimension(
			'feedType', 
			'base.feed_request', 
			'feed_format', 
			'Feed Type', 
			'feed', 
			'The type or format of the feed.', 
			'', 
			true
		);
		
		// Custom variable Dimensions
		$cv_max = owa_coreAPI::getSetting( 'base', 'maxCustomVars' );
		for ($i = 1; $i <= $cv_max;$i++) {
			
			$cvar_name_col = 'cv'.$i.'_name';
			$cvar_name_label = "Custom Var $i Name";
			$cvar_name_description = "The name of custom variable $i.";
			$this->registerDimension(
					'customVarName'.$i,
					array(
						'base.action_fact',
						'base.request',
						'base.session',
						'base.domstream',
						'base.click',
						'base.commerce_transaction_fact',
						'base.commerce_line_item_fact'
					),
					$cvar_name_col,
					$cvar_name_label,
					'custom variables',
					$cvar_name_description,
					'',
					true,
					'string'
			);	
			
			$cvar_value_col = 'cv'.$i.'_value';
			$cvar_value_label = "Custom Var $i Value";
			$cvar_value_description = "The value of custom variable $i.";
			$this->registerDimension(
					'customVarValue'.$i,
					array(
						'base.action_fact',
						'base.request',
						'base.session',
						'base.domstream',
						'base.click',
						'base.commerce_transaction_fact',
						'base.commerce_line_item_fact'
					),
					$cvar_value_col,
					$cvar_value_label,
					'custom variables',
					$cvar_value_description,
					'',
					true,
					'string'
			);	
		}
	}
	
	function registerNavigation() {
		
		$this->addNavigationSubGroup('Dashboard', 'base.reportDashboard', 'Dashboard', 1);		
		
		//Ecommerce
		$this->addNavigationSubGroup('Ecommerce', 'base.reportEcommerce', 'Ecommerce', 5, 'view_reports_ecommerce');
		$this->addNavigationLinkInSubGroup('Ecommerce', 'base.reportRevenue', 'Revenue', 2);
		$this->addNavigationLinkInSubGroup('Ecommerce', 'base.reportTransactions', 'Transactions', 3);
		$this->addNavigationLinkInSubGroup('Ecommerce', 'base.reportVisitsToPurchase', 'Visits To Purchase', 4);
		$this->addNavigationLinkInSubGroup('Ecommerce', 'base.reportDaysToPurchase', 'Days To Purchase', 5);
		
		//Content
		$this->addNavigationSubGroup('Content', 'base.reportContent', 'Content', 4);
		$this->addNavigationLinkInSubGroup( 'Content', 'base.reportPages', 'Top Pages', 1);
		$this->addNavigationLinkInSubGroup( 'Content', 'base.reportPageTypes', 'Page Types', 2);
		$this->addNavigationLinkInSubGroup( 'Content', 'base.reportFeeds', 'Feeds', 7);
		$this->addNavigationLinkInSubGroup( 'Content', 'base.reportEntryPages', 'Entry Pages', 3);
		$this->addNavigationLinkInSubGroup( 'Content', 'base.reportExitPages', 'Exit Pages', 4);
		
		
		//Actions
		$this->addNavigationSubGroup('Action Tracking', 'base.reportActionTracking', 'Action Tracking', 1);
		$this->addNavigationLinkInSubGroup('Action Tracking', 'base.reportActionGroups', 'Action Groups', 2);
		
		//Visitors
		$this->addNavigationSubGroup( 'Visitors', 'base.reportVisitors', 'Visitors', 3);
		$this->addNavigationLinkInSubGroup( 'Visitors', 'base.reportGeolocation', 'Geo-location', 1);
		$this->addNavigationLinkInSubGroup( 'Visitors', 'base.reportHosts', 'Domains', 2);								
		$this->addNavigationLinkInSubGroup( 'Visitors', 'base.reportVisitorsLoyalty', 'Visitor Loyalty', 3);
		$this->addNavigationLinkInSubGroup( 'Visitors', 'base.reportVisitorsRecency', 'Visitor Recency', 4);
		$this->addNavigationLinkInSubGroup( 'Visitors', 'base.reportVisitorsAge', 'Visitor Age', 5);
		$this->addNavigationLinkInSubGroup( 'Visitors', 'base.reportBrowsers', 'Browser Types', 6);
		$this->addNavigationLinkInSubGroup( 'Visitors', 'base.reportOs', 'Operating Systems', 7);
		
		//Traffic
		$this->addNavigationSubGroup('Traffic', 'base.reportTraffic', 'Traffic', 2);
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportKeywords', 'Search Terms', 1);								
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportAnchortext', 'Inbound Link Text', 2);
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportSearchEngines', 'Search Engines', 3);
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportReferringSites', 'Referring Web Sites', 4);
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportCampaigns', 'Campaigns', 5);
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportAds', 'Ad Performance', 6);
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportAdTypes', 'Ad Types', 7);
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportCreativePerformance', 'Creative Performance', 8);
		$this->addNavigationLinkInSubGroup( 'Traffic', 'base.reportAttributionHistory', 'Attribution History', 8);
		
		//Goals
		$this->addNavigationSubGroup('Goals', 'base.reportGoals', 'Goals', 5);
		$this->addNavigationLinkInSubGroup( 'Goals', 'base.reportGoalFunnel', 'Funnel Visualization', 1);	
				
	}
	
	/**
	 * Registers Package Files To be Built
	 *
	 */
	function registerBuildPackages() {
		
		$package = array(
			'name'			=> 'owa.tracker',
			'output_dir'	=> OWA_MODULES_DIR.'base/js/',
			'type'			=> 'js',
			'files'			=> array(
					'json2' 		=> array(
											'path' 			=> OWA_MODULES_DIR.'base/js/includes/json2.js',
											'compression'	=> 'minify'
										),
					'lazyload'		=> array(
											'path'			=>	OWA_MODULES_DIR.'base/js/includes/lazyload-2.0.min.js'	
										),
					'owa'			=> array(
											'path'			=>	OWA_MODULES_DIR.'base/js/owa.js',
											'compression'	=> 'minify'
										),
					'owa.tracker' 	=> array(
											'path'			=> OWA_MODULES_DIR.'base/js/owa.tracker.js',
											'compression'	=> 'minify'
										)
			)
		);
		
		$this->registerBuildPackage( $package );
		
		$package = array(
			'name'			=> 'owa.reporting',
			'output_dir'	=> OWA_MODULES_DIR.'base/js/',
			'type'			=> 'js',
			'files'			=> array(
					'lazy-load' 			=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/lazyload-2.0.min.js'
												),
					'json2' 				=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/json2.js'
												),
					'jquery'				=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/jquery-1.6.4.min.js'
												),
					'sprintf'				=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/jquery.sprintf.js'
													
												), // needed?
					'jquery-ui'         	=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/jquery-ui-1.8.12.custom.min.js'
												),
					'jquery-ui-selectmenu' 	=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/jquery.ui.selectmenu.js'
												
												),
					'chosen' 				=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/chosen.jquery.js',
													'compression'	=> 'minify'
												),
					'sparkline' 			=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/jquery.sparkline.min.js'
												),
					'jqgrid' 				=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/jquery.jqGrid.min.js'
												),
					'excanvas' 				=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/excanvas.compiled.js'
												),
					'flot'					=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/flot_v0.7/jquery.flot.min.js'
												),
					'flot-resize'			=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/flot_v0.7/jquery.flot.resize.min.js'
												),
					'flot-pie'				=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/flot_v0.7/jquery.flot.pie.min.js'
												),
					'jqote'					=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/includes/jquery/jQote2/jquery.jqote2.min.js'
												),
					'owa'					=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/owa.js',
													'compression'	=> 'minify'
												),
					'owa.report'			=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/owa.report.js',
													'compression'	=> 'minify'
												),
					'owa.resultSetExplorer' => array(
													'path'			=> OWA_MODULES_DIR.'base/js/owa.resultSetExplorer.js',
													'compression'	=> 'minify'
												),
					'owa.sparkline'			=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/owa.sparkline.js',
													'compression'	=> 'minify'
												),
					'owa.areaChart'			=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/owa.areachart.js',
													'compression'	=> 'minify'
												),
					'owa.pieChart'			=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/owa.piechart.js',
													'compression'	=> 'minify'
												),
					'owa.kpibox'			=> array(
													'path'			=> OWA_MODULES_DIR.'base/js/owa.kpibox.js',
													'compression'	=> 'minify'
												)
				)
		);
		
		$this->registerBuildPackage( $package );
		
		
		$package = array(
			'name'			=> 'owa.reporting-css',
			'output_dir'	=> OWA_MODULES_DIR.'base/css/',
			'type'			=> 'css',
			'files'			=> array(
					'jqueryui' 		=> array(
											'path' 	=> OWA_MODULES_DIR.'base/css/jquery-ui.css'
										),
					'selectmenu'		=> array(
											'path'	=>	OWA_MODULES_DIR.'base/css/jquery.ui.selectmenu.css'	
										),
					'jqgrid'			=> array(
											'path'	=>	OWA_MODULES_DIR.'base/css/ui.jqgrid.css'
										),
					'chosen' 	=> array(
											'path'	=> OWA_MODULES_DIR.'base/css/chosen.css'
										),
					'owa.css' 	=> array(
											'path'	=> OWA_MODULES_DIR.'base/css/owa.css'
										),
					'owa.admin.css' 	=> array(
											'path'	=> OWA_MODULES_DIR.'base/css/owa.admin.css'
										),
					'owa.report.css' 	=> array(
											'path'	=> OWA_MODULES_DIR.'base/css/owa.report.css'
										),
			)
		);
		
		$this->registerBuildPackage( $package );
	}
	
	/**
	 * Registers Event Handlers with queue queue
	 *
	 */
	function _registerEventHandlers() {
		
		// Page Requests
		$this->registerEventHandler(array('base.page_request', 'base.first_page_request'), 'requestHandlers');
		// Sessions
		$this->registerEventHandler(array('base.page_request_logged', 'base.first_page_request_logged'), 'sessionHandlers');
		// Clicks
		$this->registerEventHandler('dom.click', 'clickHandlers');
		// Feed requests
		$this->registerEventHandler('base.feed_request', 'feedRequestHandlers');
		
		// actions
		$this->registerEventHandler('track.action', 'actionHandler');
		
		// ecommerce
		
		// handles new ecommerce transactions
		$this->registerEventHandler('ecommerce.transaction', 'commerceTransactionHandlers');
		
		// updates session once ecommerce transactions are persisted
		$this->registerEventHandler(array(
				'ecommerce.transaction_persisted', 
				'ecommerce.async_transaction_persisted'), 
			'sessionCommerceSummaryHandlers'
		);
		
		$this->registerEventHandler('base.new_session', 'visitorUpdateHandlers');
		
		
		// register standard dimension handlers to listen for events 
		// that populate fact tables.
		
		// Note: ecommerce.async_transaction_persisted events are ommited here
		// because it the event gets alll non ecommerce dimensional properties
		// from a previously persisted session entity
		$fact_events = array(
			'base.page_request_logged', 
			'base.first_page_request_logged', 
			'base.new_session',
			'dom.stream_logged',
			'dom.click_logged',
			'track.action_logged',
			'ecommerce.transaction_persisted'
		);
		
		$standard_dimension_handlers = array(
			'refererHandlers',
			'searchTermHandlers',
			'osHandlers',
			'sourceHandlers',
			'campaignHandlers',
			'adHandlers',
			'userAgentHandlers',
			'hostHandlers',
			'visitorHandlers',
			'locationHandlers'
		);
		
		foreach ($standard_dimension_handlers as $handler) {
			
			$this->registerEventHandler($fact_events, $handler);
		}
		
		// Documents
		$this->registerEventHandler(
			array(
				'base.page_request_logged', 
				'base.first_page_request_logged', 
				'base.feed_request_logged',
				'track.action',
				'dom.stream',
				'dom.click',
				'ecommerce.transaction'
			), 
			'documentHandlers'
		);
		
		// Goal Conversions
		$this->registerEventHandler(
			array(
				'base.new_session', 
				'base.session_update', 
				'ecommerce.transaction_persisted'
			), 
			'conversionHandlers'
		);
		
		// Nofifcation handler
		if ( owa_coreAPI::getSetting( 'base', 'announce_visitors' ) 
			&& owa_coreAPI::getSetting( 'base', 'notice_email' ) ) {
			
			$this->registerEventHandler( 'base.new_session', 'notifyHandlers' );
		}
		
		// install complete handler
		$this->registerEventHandler('install_complete', $this, 'installCompleteHandler');
		// User management
		$this->registerEventHandler(array('base.set_password', 'base.reset_password', 'base.new_user_account'), 'userHandlers');
	}
	
	function _registerEventProcessors() {
		
		$this->addEventProcessor('base.page_request', 'base.processRequest');
		$this->addEventProcessor('base.first_page_request', 'base.processFirstRequest');
	}
	
	function _registerEntities() {
								
		$this->registerEntity(array(
				'request', 
				'session', 
				'document', 
				'feed_request', 
				'click', 
				'ua', 
				'referer', 
				'site', 
				'visitor', 
				'host',
				'exit',
				'os',
				'impression', 
				'configuration',
				'user',
				'domstream',
				'action_fact',
				'search_term_dim',
				'ad_dim', 
				'source_dim', 
				'campaign_dim',
				'location_dim',
				'commerce_transaction_fact',
				'commerce_line_item_fact',
				'queue_item',
				'site_user')
			);
		
	}
	
	function installCompleteHandler($event) {
		
		//owa_coreAPI::debug('test handler: '.print_r($event, true));
	}

	/**
	 * Convienence method for generating a data result set
	 *
	 * Takes an array of values that contain necessary params to compute the results set.
	 * Strings use ',' to seperate their values if needed. Array name/value pairs include:
	 * 
	 * array(metrics => 'foo,bar'
	 *      , dimensions => 'dim1,dim2,dim3'
	 *      , period => 'today'
	 *      , startDate => 'yyyymmdd'
	 *      , endDate => 'yyyymmdd'
	 *      , startTime => timestamp
	 *      , endTime => timestamp
	 *      , constraints => 'con1=foo, con2=bar'
	 *      , page => 1
	 *      , offset => 0
	 *      , limit => 10
	 *      , sort => 'dim1,dim2'
	 *
	 *
	 * @param $params array
	 * @return paginatedResultSet obj
	 * @link http://wiki.openwebanalytics.com/index.php?title=REST_API
	 */
	function getResultSet($metrics, $dimensions = '', $siteId = '', $constraints = '', $sort = '', $resultsPerPage = '', $page = '', $offset = '', $period = '', $startDate = '', $endDate = '', $startTime = '', $endTime = '', $format = '', $segment = '') {
		
		//print_r(func_get_args());
		// create the metric obj for the first metric
		require_once(OWA_BASE_CLASS_DIR.'resultSetManager.php');
		$rsm = new owa_resultSetManager;
		
		if ($metrics) {
			$rsm->metrics = $rsm->metricsStringToArray($metrics);
		} else {
			return false;
		}

		// set dimensions
		if ($dimensions) {
			$rsm->setDimensions($rsm->dimensionsStringToArray($dimensions));
		}
		
		if ($segment) {
			$rsm->setSegment($segment);
		}
			
		// set period
		if (!$period) {
			$period = 'today';
		}
		
		$rsm->setTimePeriod($period, 
						  $startDate, 
						  $endDate, 
						  $startTime, 
						  $endTime); 
		
		// set constraints
		if ($constraints) {
			
			$rsm->setConstraints($rsm->constraintsStringToArray($constraints));
		}
		
		//site_id
		if ($siteId) {
			$rsm->setSiteId($siteId);
		}
		
		// set sort order
		if ($sort) {
			$rsm->setSorts($rsm->sortStringToArray($sort));
		}
				
		// set limit
		if ($resultsPerPage) {
			$rsm->setLimit($resultsPerPage);
		}
		
		// set limit  (alt key)
		if ($resultsPerPage) {
			$rsm->setLimit($resultsPerPage);
		}
		
		// set page
		if ($page) {
			$rsm->setPage($page);
		}
		
		// set offset
		if ($offset) {
			$rsm->setOffset($offset);
		}
		
		// set format
		if ($format) {
			$rsm->setFormat($format);
		}
		
		// get results
		$rs = $rsm->getResults();
		
		if ($format) {
			owa_lib::setContentTypeHeader($format);
			return $rs->formatResults($format);		
		} else {
			return $rs;
		}
	}
	
	
	
	function getVisitDetail($sessionId, $format = '') {
	
		if ($sessionId) {
		
			$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
			$db = owa_coreAPI::dbSingleton();
			
			$s = owa_coreAPI::entityFactory('base.session');
			$h = owa_coreAPI::entityFactory('base.host');
			$l = owa_coreAPI::entityFactory('base.location_dim');
			$ua = owa_coreAPI::entityFactory('base.ua');
			$d = owa_coreAPI::entityFactory('base.document');
			$v = owa_coreAPI::entityFactory('base.visitor');
			$r = owa_coreAPI::entityFactory('base.referer');
			$sr = owa_coreAPI::entityFactory('base.source_dim');
			$db->selectFrom($s->getTableName(), 'session');
			
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $l->getTableName(), 'location', 'location_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $h->getTableName(), 'host', 'host_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $ua->getTableName(), 'ua', 'ua_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), 'document', 'first_page_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $v->getTableName(), 'visitor', 'visitor_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $r->getTableName(), 'referer', 'referer_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $sr->getTableName(), 'source', 'source_id');
			
			$db->selectColumn('session.timestamp as session_timestamp, session.is_new_visitor as session_is_new_visitor, session.num_pageviews as session_num_pageviews, session.last_req as session_last_req, session.id as session_id, session.user_name as session_user_name, session.site_id as site_id, session.visitor_id as visitor_id, session.medium as medium');
							   
			$db->selectColumn('host.host as host_host');
			$db->selectColumn('location.city as location_city, location.country as location_country');
			$db->selectColumn('ua.browser_type as browser_type');
			$db->selectColumn('document.url as document_url, document.page_title as document_page_title, document.page_type as document_page_type');
			$db->selectColumn('visitor.user_email as visitor_user_email');
			$db->selectColumn('source.source_domain as source');
			$db->selectColumn('referer.url as referer_url, referer.page_title as referer_page_title, referer.snippet as referer_snippet');
			
			$db->where('session.id', $sessionId);
			
			$results = $rs->generate($db);
			$rs->resultsRows = $results;
			
			if ($format) {
				owa_lib::setContentTypeHeader($format);
				return $rs->formatResults($format);		
			} else {
				return $rs;
			}
		}
	}
	
	function getLatestVisits($startDate = '', $endDate = '', $visitorId = '', $siteId = '', $resultsPerPage = 20, $page = 1, $format = '') {
		
		$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
		$db = owa_coreAPI::dbSingleton();
		
		$s = owa_coreAPI::entityFactory('base.session');
		$h = owa_coreAPI::entityFactory('base.host');
		$l = owa_coreAPI::entityFactory('base.location_dim');
		$ua = owa_coreAPI::entityFactory('base.ua');
		$d = owa_coreAPI::entityFactory('base.document');
		$v = owa_coreAPI::entityFactory('base.visitor');
		$r = owa_coreAPI::entityFactory('base.referer');
		$sr = owa_coreAPI::entityFactory('base.source_dim');
		$st = owa_coreAPI::entityFactory('base.search_term_dim');
		
		$db->selectFrom($s->getTableName(), 'session');
		
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $l->getTableName(), 'location', 'location_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $h->getTableName(), 'host', 'host_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $ua->getTableName(), 'ua', 'ua_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), 'document', 'first_page_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $v->getTableName(), 'visitor', 'visitor_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $r->getTableName(), 'referer', 'referer_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $sr->getTableName(), 'source', 'source_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $st->getTableName(), 'search_term', 'referring_search_term_id');
		
		$db->selectColumn('session.timestamp as session_timestamp, session.is_new_visitor as session_is_new_visitor, session.num_pageviews as session_num_pageviews, session.last_req as session_last_req, session.id as session_id, session.user_name as session_user_name, session.site_id as site_id, session.visitor_id as visitor_id, session.medium as medium');
						   
		$db->selectColumn('host.host as host_host');
		$db->selectColumn('location.city as location_city, location.country as location_country');
		$db->selectColumn('ua.browser_type as browser_type');
		$db->selectColumn('document.url as document_url, document.page_title as document_page_title, document.page_type as document_page_type');
		$db->selectColumn('visitor.user_email as visitor_user_email');
		$db->selectColumn('source.source_domain as source');
		$db->selectColumn('referer.url as referer_url, referer.page_title as referer_page_title, referer.snippet as referer_snippet');
		$db->selectColumn('search_term.terms as search_term');
		
		if ($visitorId) {
			$db->where('visitor_id', $visitorId);
		}
		
		if ($siteId) {
			$db->where('site_id', $siteId);
		}
		
		if ($startDate && $endDate) {
			$db->where('session.yyyymmdd', array('start' => $startDate, 'end' => $endDate), 'BETWEEN');
		}
		
		$db->orderBy('timestamp', 'DESC');
		
		// pass limit to rs object if one exists
		$rs->setLimit($resultsPerPage);
			
		// pass page to rs object if one exists
		$rs->setPage($page);
		
		$results = $rs->generate($db);
		$rs->resultsRows = $results;
		
		if ($format) {
			owa_lib::setContentTypeHeader($format);
			return $rs->formatResults($format);		
		} else {
			return $rs;
		}
	}
	
	function getLatestActions( $startDate = '', $endDate = '', $visitorId= '', $sessionId = '',
							   $action_group ='', $siteId = '', $resultsPerPage = 20, 
							   $page = 1, $format = '') {
							   
		$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
		$db = owa_coreAPI::dbSingleton();
		
		$a = owa_coreAPI::entityFactory('base.action_fact');
		$d = owa_coreAPI::entityFactory('base.document');
		
		$db->selectFrom($a->getTableName(), 'action');
		
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), 'document', 'document_id');
		
		
		$db->selectColumn('action.timestamp, action.action_name, action.action_label, action.action_group, action.numeric_value');
		$db->selectColumn('document.url as document_url, document.page_title as document_page_title, document.page_type as document_page_type');
		
		if ($visitorId) {
			$db->where('action.visitor_id', $visitorId);
		}
		
		if ($sessionId) {
			$db->where('action.session_id', $sessionId);
		}
		
		if ($siteId) {
			$db->where('site_id', $siteId);
		}
		
		if ($startDate && $endDate) {
			$db->where('action.yyyymmdd', array('start' => $startDate, 'end' => $endDate), 'BETWEEN');
		}
		
		$db->orderBy('action.timestamp', 'DESC');
		
		// pass limit to rs object if one exists
		$rs->setLimit($resultsPerPage);
			
		// pass page to rs object if one exists
		$rs->setPage($page);
		
		$results = $rs->generate($db);
		$rs->resultsRows = $results;
		
		if ($format) {
			owa_lib::setContentTypeHeader($format);
			return $rs->formatResults($format);		
		} else {
			return $rs;
		}
		
		
	
	}
	
	function getClickstream($sessionId, $resultsPerPage = 100, $page = 1, $format = '') {
		
		$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
		$db = owa_coreAPI::dbSingleton();
		$db->selectFrom('owa_request', 'request');
		$db->selectColumn("*");
		// pass constraints into where clause
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_document', 'document', 'document_id', 'document.id');
		
		if ($sessionId) {
			$db->where('session_id', $sessionId);
		}
				
		$db->orderBy('timestamp','DESC');
		
		// pass limit to rs object if one exists
		$rs->setLimit($resultsPerPage);
			
		// pass page to rs object if one exists
		$rs->setPage($page);
		
		$results = $rs->generate($db);
		$rs->resultsRows = $results;
		//print_r($rs);
		if ($format) {
			owa_lib::setContentTypeHeader($format);
			return $rs->formatResults($format);		
		} else {
			
			return $rs;
		}
	}
	
	/**
	 * Retrieves full detail of an ecommerce transaction
	 *
	 * @param	$transactionId	string the id of the transaction you want
	 * @param	$format			string the format you want returned
	 * @return	
	 */
	function getTransactionDetail( $transactionId, $format = 'php' ) {
		
		$t = owa_coreAPI::entityFactory( 'base.commerce_transaction_fact' );
		$t->getbyColumn('order_id',$transactionId);
		$trans_detail = array();
	
		$id = $t->get( 'id' );
		if ( $id ) {
			$trans_detail = $t->_getProperties();
			// fetch line items	
			$db = owa_coreAPI::dbSingleton();
		
			$db->selectFrom( 'owa_commerce_line_item_fact' );
			$db->selectColumn( '*' );
			$db->where( 'order_id', $transactionId );
			$lis = $db->getAllRows();
			$trans_detail['line_items'] = $lis;
		}
		
		return $trans_detail;
	}
	
	/**
	 * Deprecated 
	 *
	 * @todo remove	
	 *	
	*/
	function attributeCampaign( $tracking_event ) {
		
		$mode = owa_coreAPI::getSetting('base', 'campaign_attribution_mode');
		// direct mode means that that we attribute the latest campaign touch
		// if the request originaled from the touching the campaign.
		if ( $mode === 'direct' ) {
			if ( $tracking_event->get( 'from_campaign' ) ) {
				$campaigns = array_reverse( $tracking_event->get( 'campaign_touches' ) );
				//$tracking_event->set( 'attributed_campaign', $campaigns[0] );
				return $campaigns[0];
			}
		// orginal mode means that we always attribute the request to the
		// first touch regardless of the medium/source that generated the request
		} elseif ( $mode === 'original' ) {
			$campaigns = $tracking_event->get( 'campaign_touches' );
			//$tracking_event->set( 'attributed_campaign', $campaigns[0] );
			return $campaigns[0];
		}
	}
	
	function getTransactions($siteId, $startDate, $endDate, $period, $sort = 'desc', $resultsPerPage = 25, $page = 1, $format = 'json') {
		
		$db = owa_coreAPI::dbSingleton();
		$db->selectFrom('owa_commerce_transaction_fact');
		$db->selectColumn("*");
		$db->orderBy('timestamp', $sort);
		$db->where('site_id', $siteId);

		if ( $period ) {
			
			$p = owa_coreAPI::supportClassFactory('base', 'timePeriod');
			$p->set($period);
			$startDate = $p->startDate->get('yyyymmdd');
			$endDate = $p->endDate->get('yyyymmdd');
		}
		
		if ($startDate && $endDate) {
			$db->where('yyyymmdd', array('start' => $startDate, 'end' => $endDate), 'BETWEEN');
		}
		
		// pass limit to rs object if one exists
		$rs->setLimit($resultsPerPage);
			
		// pass page to rs object if one exists
		$rs->setPage($page);
		
		$results = $rs->generate($db);
		//$rs->resultsRows = $results;
		
		if ($format) {
			owa_lib::setContentTypeHeader($format);
			return $rs->formatResults($format);		
		} else {
			return $rs;
		}
		
	}
	
	function getDomClicks($pageUrl, $siteId, $startDate, $endDate, $document_id = '', $period = '', $resultsPerPage = 100, $page = 1, $format = 'jsonp') {
		
		// Fetch document object
		$d = owa_coreAPI::entityFactory('base.document');
		
		if ( ! $document_id ) {
	
			$eq = owa_coreAPI::getEventDispatch();
			$document_id = $d->generateId( $eq->filter('page_url',  urldecode( $pageUrl ), $siteId ) ) ;
		}
			
		$d->getByColumn('id', $document_id);
		
		
		$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
		$db = owa_coreAPI::dbSingleton();
		$db->selectFrom('owa_click');
		$db->selectColumn("click_x as x,
							click_y as y,
							page_width,
							page_height,
							dom_element_x,
							dom_element_y,
							position");
		
		
		$db->orderBy('click_y', 'ASC');
		$db->where('document_id', $document_id);
		$db->where('site_id', $siteId);
		
		
		if ( $period ) {
			
			$p = owa_coreAPI::supportClassFactory('base', 'timePeriod');
			$p->set($period);
			$startDate = $p->startDate->get('yyyymmdd');
			$endDate = $p->endDate->get('yyyymmdd');
		}
		
		if ($startDate && $endDate) {
			$db->where('yyyymmdd', array('start' => $startDate, 'end' => $endDate), 'BETWEEN');
		}
		
		// pass limit to rs object if one exists
		$rs->setLimit($resultsPerPage);
			
		// pass page to rs object if one exists
		$rs->setPage($page);
		
		$results = $rs->generate($db);
		//$rs->resultsRows = $results;
		
		if ($format) {
			owa_lib::setContentTypeHeader($format);
			return $rs->formatResults($format);		
		} else {
			return $rs;
		}
	}
    
    function checkEventForType( $event ) {
		
		$type = $event->getEventType();
		
		if ( $type === 'unknown_event_type' ) {
			
			$e = owa_coreAPI::errorSingleton();
			$e->mailErrorMsg( print_r( $event->getProperties(), true ), 'Unknown Event Type' );		
		}
		
		return $event;
	}
	
	public function anonymizeIpAddress( $event ) {
	
		$ip_address = $event->get( 'ip_address');
		
		if ( $ip_address && strpos($ip_address, '.' ) ) {
		
			$ip = explode( '.', $ip_address );
			array_pop($ip);
			$ip = implode('.', $ip);
			
			$event->set( 'ip_address', $ip);
			$event->set('full_host', '(not set)');
		}
		
		return $event;
	}

}


?>