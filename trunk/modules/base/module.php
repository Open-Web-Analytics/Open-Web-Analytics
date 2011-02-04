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
		$this->version = 6;
		$this->description = 'Base functionality for OWA.';
		$this->config_required = false;
		$this->required_schema_version = 6;
		
		/**
		 * Register Filters
		 *
		 * The following lines register filter methods. 
		 */
		$this->registerFilter('operating_system', $this, 'determineOperatingSystem', 0);
		$this->registerFilter('ip_address', $this, 'setIp', 0);
		$this->registerFilter('full_host', $this, 'resolveHost', 0);
		$this->registerFilter('host', $this, 'getHostDomain', 0);
		$this->registerFilter('attributed_campaign', $this, 'attributeCampaign', 10);
		$this->registerFilter('geolocation', 'hostip', 'get_location', 10, 'classes');
		//Clean Query Strings 
		if (owa_coreAPI::getSetting('base', 'clean_query_string')) {
			$this->registerFilter('page_url', $this, 'makeUrlCanonical',0);
			$this->registerFilter('prior_page', $this, 'makeUrlCanonical',0);
			$this->registerFilter('target_url', $this, 'makeUrlCanonical',0);
		}
		// event procesing daemon jobs
		$this->registerBackgroundJob('process_event_queue', 'cli.php cmd=processEventQueue', owa_coreAPI::getSetting('base', 'processQueuesJobSchedule'), 10);
		
		/**
		 * Register Service Implementations
		 *
		 * The following lines register various service implementations. 
		 */
		
		/**
		 * Register Metrics
		 *
		 * The following lines register various data metrics. 
		 */
		$this->registerMetric('pageViews', 'base.pageViews');
		$this->registerMetric('pageViews', 'base.pageViewsFromSessionFact');
		$this->registerMetric('uniqueVisitors', 'base.uniqueVisitors');
		$this->registerMetric('visits', 'base.visits');
		$this->registerMetric('visits', 'base.visitsFromRequestFact');
		$this->registerMetric('visitors', 'base.visitors');
		$this->registerMetric('visitors', 'base.visitorsFromRequestFact');
		$this->registerMetric('newVisitors', 'base.newVisitors');
		$this->registerMetric('repeatVisitors', 'base.repeatVisitors');
		$this->registerMetric('bounces', 'base.bounces');
		$this->registerMetric('visitDuration', 'base.visitDuration');
		$this->registerMetric('uniquePageViews', 'base.uniquePageViews');
		$this->registerMetric('bounceRate', 'base.bounceRate');
		$this->registerMetric('pagesPerVisit', 'base.pagesPerVisit');
		$this->registerMetric('actions', 'base.actions');
		$this->registerMetric('uniqueActions', 'base.uniqueActions');
		$this->registerMetric('actionsValue', 'base.actionsValue');
		//$this->registerMetric('actionsPerVisit', 'base.actionsPerVisit');
		$this->registerMetric('feedRequests', 'base.feedRequests');
		$this->registerMetric('feedReaders', 'base.feedReaders');
		$this->registerMetric('feedSubscriptions', 'base.feedSubscriptions');
		
		// goals
		$gcount = owa_coreAPI::getSetting('base', 'numGoals');
		for ($num = 1; $num <= $gcount;$num++) {
			$params = array('goal_number' => $num);
			
			$metric_name = 'goal'.$num.'Completions';
			$this->registerMetric($metric_name, 'base.goalNCompletions', $params);
			
			$metric_name = 'goal'.$num.'Starts';
			$this->registerMetric($metric_name, 'base.goalNStarts', $params);
			
			$metric_name = 'goal'.$num.'Value';
			$this->registerMetric($metric_name, 'base.goalNValue', $params);
		}
		
		$this->registerMetric('goalCompletionsAll', 'base.goalCompletionsAll');
		$this->registerMetric('goalStartsAll', 'base.goalStartsAll');
		$this->registerMetric('goalValueAll', 'base.goalValueAll');
		$this->registerMetric('goalConversionRateAll', 'base.goalConversionRateAll');
		$this->registerMetric('goalAbandonRateAll', 'base.goalAbandonRateAll');
		
		// ecommerce metrics
		$this->registerMetric('lineItemQuantity', 'base.lineItemQuantity');
		$this->registerMetric('lineItemQuantity', 'base.lineItemQuantityFromSessionFact');
		$this->registerMetric('lineItemRevenue', 'base.lineItemRevenue');
		$this->registerMetric('lineItemRevenue', 'base.lineItemRevenueFromSessionFact');
		$this->registerMetric('transactions', 'base.transactions');
		$this->registerMetric('transactions', 'base.transactionsFromSessionFact');
		$this->registerMetric('transactionRevenue', 'base.transactionRevenue');
		$this->registerMetric('transactionRevenue', 'base.transactionRevenueFromSessionFact');
		$this->registerMetric('taxRevenue', 'base.taxRevenue');
		$this->registerMetric('taxRevenue', 'base.taxRevenueFromSessionFact');
		$this->registerMetric('shippingRevenue', 'base.shippingRevenue');
		$this->registerMetric('shippingRevenue', 'base.shippingRevenueFromSessionFact');
		$this->registerMetric('uniqueLineItems', 'base.uniqueLineItems');
		$this->registerMetric('uniqueLineItems', 'base.uniqueLineItemsFromSessionFact');
		$this->registerMetric('revenuePerTransaction', 'base.revenuePerTransaction');
		$this->registerMetric('revenuePerVisit', 'base.revenuePerVisit');
		$this->registerMetric('ecommerceConversionRate', 'base.ecommerceConversionRate');
		$this->registerMetric('domClicks', 'base.domClicks');
		/**
		 * Register Dimensions
		 *
		 * The following lines register various data dimensions. 
		 */
		$this->registerDimension('browserVersion', 'base.ua', 'browser', 'Browser Version', 'visitor', 'The browser version of the visitor.');
		$this->registerDimension('browserType', 'base.ua', 'browser_type', 'Browser Type', 'visitor', 'The browser type of the visitor.');
		$this->registerDimension('osType', 'base.os', 'name', 'Operating System', 'visitor', 'The operating System of the visitor.');
		$this->registerDimension('ipAddress', 'base.host', 'ip_address', 'IP Address', 'visitor', 'The IP address of the visitor.');
		$this->registerDimension('hostName', 'base.host', 'full_host', 'Host Name', 'visitor', 'The host name used by the visitor.');
		$this->registerDimension('city', 'base.location_dim', 'city', 'City', 'visitor', 'The city of the visitor.');
		$this->registerDimension('country', 'base.location_dim', 'country', 'Country', 'visitor', 'The country of the visitor.');
		$this->registerDimension('latitude', 'base.location_dim', 'latitude', 'Latitude', 'visitor', 'The latitude of the visitor.');
		$this->registerDimension('longitude', 'base.location_dim', 'longitude', 'Longitude', 'visitor', 'The longitude of the visitor.');
		$this->registerDimension('countryCode', 'base.location_dim', 'country_code', 'Country Code', 'visitor', 'The ISO country code of the visitor.');
		$this->registerDimension('stateRegion', 'base.location_dim', 'state', 'State/Region', 'visitor', 'The state or region of the visitor.');
		
		$this->registerDimension('timeSinceLastVisit', 'base.session', 'time_sinse_priorsession', 'Time Since Last Visit', 'visitor', 'The time since the last visit.', '', true);
		$this->registerDimension('isRepeatVisitor', 'base.session', 'is_repeat_visitor', 'Repeat Visitor', 'visitor', 'Repeat Site Visitor.', '', true);
		$this->registerDimension('isNewVisitor', 'base.session', 'is_new_visitor', 'New Visitor', 'visitor', 'New Site Visitor.', '', true);
		$this->registerDimension('language', 'base.session', 'language', 'Language', 'visit', 'The language of the visit.', '', true);
		$this->registerDimension('language', 'base.request', 'language', 'Language', 'visit', 'The language of the visit.', '', true);
		// campaign related
		$this->registerDimension('medium', 'base.session', 'medium', 'Medium', 'visit', 'The medium of channel of visit.', '', true);
		$this->registerDimension('latestAttributions', 'base.session', 'latest_attributions', 'Latest Attributions', 'visit', 'The latest campaign attributions.', '', true);
		$this->registerDimension('source', 'base.source_dim', 'source_domain', 'Source', 'visit', 'The traffic source of the visit.');
		$this->registerDimension('campaign', 'base.campaign_dim', 'name', 'Campaign', 'visit', 'The campaign that originated the visit.');
		$this->registerDimension('ad', 'base.ad_dim', 'name', 'Ad', 'visit', 'The name of the ad that originated the visit.');
		$this->registerDimension('adType', 'base.ad_dim', 'type', 'Ad Type', 'visit', 'The type of ad that originated the visit.');
		
		$this->registerDimension('siteDomain', 'base.site', 'domain', 'Site Domain', 'visit', 'The domain of the site.');
		$this->registerDimension('siteName', 'base.site', 'name', 'Site Name', 'visit', 'The name of the site.');
		$this->registerDimension('siteId', 'base.site', 'site_id', 'Site ID', 'visit', 'The ID of the site.');
		$this->registerDimension('userName', 'base.visitor', 'user_name', 'User Name', 'visitor', 'The name or ID of the user.');
		$this->registerDimension('userEmail', 'base.visitor', 'user_email', 'Email Address', 'visitor', 'The email address of the user.');
		
		// Date and time oriented dimensions
		$this->registerDimension('date', 'base.session', 'yyyymmdd', 'Date', 'visit', 'The date.', '', true, 'yyyymmdd');
		$this->registerDimension('day', 'base.session', 'day', 'Day', 'visit', 'The day.', '', true);
		$this->registerDimension('month', 'base.session', 'month', 'Month', 'visit', 'The month.', '', true);
		$this->registerDimension('year', 'base.session', 'year', 'Year', 'visit', 'The year.', '', true);
		$this->registerDimension('dayofweek', 'base.session', 'dayofweek', 'Day of Week', 'visit', 'The day of the week.', '', true);
		$this->registerDimension('dayofyear', 'base.session', 'dayofyear', 'Day of Year', 'visit', 'The day of the year.', '', true);
		$this->registerDimension('weekofyear', 'base.session', 'weekofyear', 'Week of Year', 'visit', 'The week of the year.', '', true);
		$this->registerDimension('siteId', 'base.session', 'site_id', 'Site ID', 'visit', 'The ID of the the web site.', '', true);
		$this->registerDimension('daysSinceLastVisit', 'base.session', 'days_since_prior_session', 'Days Since Last Visit', 'visit', 'The number of days since the last visit.', '', true);
		$this->registerDimension('daysSinceFirstVisit', 'base.session', 'days_since_first_session', 'Days Since First Visit', 'visit', 'The number of days since the first visit of the user.', '', true);
	
		$this->registerDimension('priorVisitCount', 'base.session', 'num_prior_sessions', 'Prior Visits', 'visit', 'The number of prior visits, excluding the current one.', '', true);
		
		$this->registerDimension('priorVisitCount', 'base.request', 'num_prior_sessions', 'Prior Visits', 'visit', 'The number of prior visits, excluding the current one.', '', true);
		
		$this->registerDimension('date', 'base.request', 'yyyymmdd', 'Date', 'visit', 'The date.', '', true, 'yyyymmdd');
		$this->registerDimension('day', 'base.request', 'day', 'Day', 'visit', 'The day.', '', true);
		$this->registerDimension('month', 'base.request', 'month', 'Month', 'visit', 'The month.', '', true);
		$this->registerDimension('year', 'base.request', 'year', 'Year', 'visit', 'The year.', '', true);
		$this->registerDimension('dayofweek', 'base.request', 'dayofweek', 'Day of Week', 'visit', 'The day of the week.', '', true);
		$this->registerDimension('dayofyear', 'base.request', 'dayofyear', 'Day of Year', 'visit', 'The day of the year.', '', true);
		$this->registerDimension('weekofyear', 'base.request', 'weekofyear', 'Week of Year', 'visit', 'The week of the year.', '', true);
		$this->registerDimension('siteId', 'base.request', 'site_id', 'Site ID', 'visit', 'The ID of the the web site.', '', true);
		
		$this->registerDimension('actionName', 'base.action_fact', 'action_name', 'Action Name', 'actions', 'The name of the action.', '', true);
		$this->registerDimension('actionGroup', 'base.action_fact', 'action_group', 'Action Group', 'actions', 'The group that an action belongs to.', '', true);
		$this->registerDimension('actionLabel', 'base.action_fact', 'action_label', 'Action Label', 'actions', 'The label associated with an action.', '', true);
		$this->registerDimension('date', 'base.action_fact', 'yyyymmdd', 'Date', 'action', 'The date.', '', true, 'yyyymmdd');
		$this->registerDimension('siteId', 'base.acton_fact', 'site_id', 'Site ID', 'visit', 'The ID of the the web site.', '', true);
		
		// visit
		$this->registerDimension('entryPageUrl', 'base.document', 'url', 'Entry Page URL', 'visit', 'The URL of the entry page.', 'first_page_id');
		$this->registerDimension('entryPagePath', 'base.document', 'uri', 'Entry Page Path', 'visit', 'The URI of the entry page.', 'first_page_id');
		$this->registerDimension('entryPageTitle', 'base.document', 'page_title', 'Entry Page Title', 'visit', 'The title of the entry page.', 'first_page_id');
		$this->registerDimension('entryPageType', 'base.document', 'page_type', 'Entry Page Type', 'visit', 'The page type of the entry page.', 'first_page_id');
		$this->registerDimension('exitPageUrl', 'base.document', 'url', 'Exit Page URL', 'visit', 'The URL of the exit page.', 'last_page_id');
		$this->registerDimension('exitPagePath', 'base.document', 'uri', 'Exit Page Path', 'visit', 'The URI of the exit page.', 'last_page_id');
		$this->registerDimension('exitPageTitle', 'base.document', 'page_title', 'Exit Page Title', 'visit', 'The title of the exit page.', 'last_page_id');
		$this->registerDimension('exitPageType', 'base.document', 'page_type', 'Exit Page Type', 'visit', 'The page type of the exit page.', 'last_page_id');
		$this->registerDimension('priorPageUrl', 'base.document', 'url', 'Prior Page URL', 'visit', 'The URL of the prior page.', 'prior_document_id');
		$this->registerDimension('priorPagePath', 'base.document', 'uri', 'Prior Page Path', 'visit', 'The URI of the prior page.', 'prior_document_id');
		$this->registerDimension('priorPageTitle', 'base.document', 'page_title', 'Prior Page Title', 'visit', 'The title of the prior page.', 'prior_document_id');
		$this->registerDimension('priorPageType', 'base.document', 'page_type', 'Prior Page Type', 'visit', 'The page type of the prior page.', 'prior_document_id');
		
		// traffic sources
		$this->registerDimension('referralPageUrl', 'base.referer', 'url', 'Referral Page URL', 'traffic sources', 'The url of the referring web page.');
		$this->registerDimension('referralPageTitle', 'base.referer', 'page_title', 'Referral Page Title', 'traffic sources', 'The title of the referring web page.');
		$this->registerDimension('referralSearchTerms', 'base.search_term_dim', 'terms', 'Search Terms', 'traffic sources', 'The referring search terms.', 'referring_search_term_id');
		$this->registerDimension('referralLinkText', 'base.referer', 'refering_anchortext', 'Referral Link Text', 'traffic sources', 'The text of the referring link.');
		$this->registerDimension('isSearchEngine', 'base.referer', 'is_searchengine', 'Search Engine', 'traffic sources', 'Is traffic source a search engine.');
		$this->registerDimension('referralWebSite', 'base.referer', 'site', 'Referral Web Site', 'traffic sources', 'The full domain of the referring web site.');
		
		// content
		$this->registerDimension('pageUrl', 'base.document', 'url', 'Page URL', 'content', 'The URL of the web page.', 'document_id');
		$this->registerDimension('pagePath', 'base.document', 'uri', 'Page Path', 'content', 'The path of the web page.', 'document_id');
		$this->registerDimension('pageTitle', 'base.document', 'page_title', 'Page Title', 'content', 'The title of the web page.', 'document_id');
		$this->registerDimension('pageType', 'base.document', 'page_type', 'Page Type', 'content', 'The page type of the web page.', 'document_id');
		
		// feeds
		$this->registerDimension('date', 'base.feed_request', 'yyyymmdd', 'Date', 'date', 'The date.', '', true, 'yyyymmdd');
		$this->registerDimension('day', 'base.feed_request', 'day', 'Day', 'date', 'The day.', '', true);
		$this->registerDimension('month', 'base.feed_request', 'month', 'Month', 'date', 'The month.', '', true);
		$this->registerDimension('year', 'base.feed_request', 'year', 'Year', 'date', 'The year.', '', true);
		$this->registerDimension('dayofweek', 'base.feed_request', 'dayofweek', 'Day of Week', 'date', 'The day of the week.', '', true);
		$this->registerDimension('dayofyear', 'base.feed_request', 'dayofyear', 'Day of Year', 'date', 'The day of the year.', '', true);
		$this->registerDimension('weekofyear', 'base.feed_request', 'weekofyear', 'Week of Year', 'date', 'The week of the year.', '', true);
		$this->registerDimension('feedType', 'base.feed_request', 'feed_format', 'Feed Type', 'feed', 'The type or format of the feed.', '', true);
		$this->registerDimension('siteId', 'base.feed_request', 'site_id', 'Site ID', 'request', 'The ID of the the web site.', '', true);
		
		//clicks
		$this->registerDimension('date', 'base.click', 'yyyymmdd', 'Date', 'visit', 'The date.', '', true, 'yyyymmdd');
		// IDs
		$this->registerDimension('visitorId', 'base.visitor', 'id', 'Visitor ID', 'visitor', 'The ID of the visitor.');
		$this->registerDimension('sessionId', 'base.session', 'id', 'Session ID', 'visit', 'The ID of the session/visit.');
		
		$this->registerDimension('daysToTransaction', 'base.commerce_transaction_fact', 'days_since_first_session', 'Days To Purchase', 'ecommerce', 'The number of days since the first visit and an e-commerce transaction.', '', true);
		$this->registerDimension('visitsToTransaction', 'base.commerce_transaction_fact', 'num_prior_sessions', 'Visits To Purchase', 'ecommerce', 'The number of visits prior to an e-commerce transaction.', '', true);
		
		// productName
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
		// productSku
		$this->registerDimension('productSku', 'base.commerce_line_item_fact', 'sku', 'Product SKU', 'ecommerce', 'The SKU code of the product purchased.', '', true);
		// productCategory
		$this->registerDimension('productCategory', 'base.commerce_line_item_fact', 'category', 'Product Category', 'ecommerce', 'The category of product purchased.', '', true);
		// transactionOriginator
		$this->registerDimension('transactionOriginator', 'base.commerce_transaction_fact', 'order_source', 'Originator', 'ecommerce', 'The store or location that originated the transaction.', '', true);
		// transactionId
		$this->registerDimension('transactionId', 'base.commerce_transaction_fact', 'order_id', 'Transaction ID', 'ecommerce', 'The id of the e-commerce transaction.', '', true);
		$this->registerDimension('transactionGateway', 'base.commerce_transaction_fact', 'gateway', 'Payment Gateway', 'ecommerce', 'The payment gateway or provider used in the e-commerce transaction.', '', true);
		// daysToTransaction
		$this->registerDimension('daysToTransaction', 'base.commerce_transaction_fact', 'days_since_first_session', "Days To Purchase', 'ecommerce', 'The number of days between the visitor's first visit and when transaction occurred.", '', true);
		// visitsToTransaction
		$this->registerDimension('visitsToTransaction', 'base.commerce_transaction_fact', 'num_prior_sessions', "Visits To Purchase', 'ecommerce', 'The number of visits before the transaction occurred.", '', true);
		$this->registerDimension('date', 'base.commerce_line_item_fact', 'yyyymmdd', 'Date', 'ecommerce', 'The date.', '', true, 'yyyymmdd');
		$this->registerDimension('date', 'base.commerce_transaction_fact', 'yyyymmdd', 'Date', 'ecommerce', 'The date.', '', true, 'yyyymmdd');
		$this->registerDimension('timestamp', 'base.commerce_transaction_fact', 'timestamp', 'Time', 'ecommerce', 'The timestamp of the transaction.', '', true);
		$this->registerDimension('siteId', 'base.commerce_line_item_fact', 'site_id', 'Site Id', 'ecommerce', 'The site ID.', '', true, 'site_id');
		$this->registerDimension('siteId', 'base.commerce_transaction_fact', 'site_id', 'Site Id', 'ecommerce', 'The site ID.', '', true, 'site_id');
		// dom clicks
		$this->registerDimension('siteId', 'base.click', 'site_id', 'Site Id', 'site', 'The site ID.', '', true, 'site_id');
		$this->registerDimension('date', 'base.click', 'yyyymmdd', 'Date', 'date', 'The date.', '', true, 'yyyymmdd');
		$this->registerDimension('domElementId', 'base.click', 'dom_element_id', 'Dom ID', 'dom', 'The id of the dom element.', '', true);
		$this->registerDimension('domElementName', 'base.click', 'dom_element_name', 'Dom Name', 'dom', 'The name of the dom element.', '', true);
		$this->registerDimension('domElementText', 'base.click', 'dom_element_text', 'Dom Text', 'dom', 'The text associated the dom element.', '', true);
		$this->registerDimension('domElementValue', 'base.click', 'dom_element_value', 'Dom Value', 'dom', 'The value of the dom element.', '', true);
		$this->registerDimension('domElementTag', 'base.click', 'dom_element_tag', 'Dom Tag', 'dom', 'The html tag of the dom element.', '', true);
		$this->registerDimension('domElementClass', 'base.click', 'dom_element_class', 'Dom Class', 'dom', 'The class of the dom element.', '', true);
		
		/**
		 * Register CLI Commands
		 *
		 * The following lines register various command line interface (CLI) controller. 
		 */
		$this->registerCliCommand('update', 'base.updatesApplyCli');
		$this->registerCliCommand('build', 'base.build');
		$this->registerCliCommand('flush-cache', 'base.flushCacheCli');
		$this->registerCliCommand('processEventQueue', 'base.processEventQueue');
		$this->registerCliCommand('install', 'base.installCli');
		$this->registerCliCommand('activate', 'base.moduleActivateCli');
		$this->registerCliCommand('deactivate', 'base.moduleDeactivateCli');
		$this->registerCliCommand('install-module', 'base.moduleInstallCli');
		
		/**
		 * Register API methods
		 *
		 * The following lines register various API methods. 
		 */
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
					'format'), 
				'', 
				'view_reports'
		);
		
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
		
		$this->registerApiMethod('getDomstream', 
				array($this, 'getDomstream'), 
				array('domstream_guid'),
				'', 
				'view_reports' 
		);
		
		return parent::__construct();
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
	
	function registerNavigation() {
		
		$this->addNavigationLink('Reports', '', 'base.reportDashboard', 'Dashboard', 1);
		$this->addNavigationLink('Reports', '', 'base.reportVisitors', 'Visitors', 3);
		
		$this->addNavigationLink('Reports', '', 'base.reportContent', 'Content', 4);		
		
		$this->addNavigationLink('Reports', '', 'base.reportEcommerce', 'Ecommerce', 1);
		$this->addNavigationLink('Reports', 'Ecommerce', 'base.reportRevenue', 'Revenue', 2);
		$this->addNavigationLink('Reports', 'Ecommerce', 'base.reportTransactions', 'Transactions', 3);
		$this->addNavigationLink('Reports', 'Ecommerce', 'base.reportVisitsToPurchase', 'Visits To Purchase', 4);
		$this->addNavigationLink('Reports', 'Ecommerce', 'base.reportDaysToPurchase', 'Days To Purchase', 5);

		$this->addNavigationLink('Reports', 'Content', 'base.reportPages', 'Top Pages', 1);
		$this->addNavigationLink('Reports', 'Content', 'base.reportPageTypes', 'Page Types', 2);
		$this->addNavigationLink('Reports', 'Content', 'base.reportFeeds', 'Feeds', 7);
		$this->addNavigationLink('Reports', 'Content', 'base.reportEntryPages', 'Entry Pages', 3);
		$this->addNavigationLink('Reports', 'Content', 'base.reportExitPages', 'Exit Pages', 4);
		$this->addNavigationLink('Reports', 'Content', 'base.reportDomstreams', 'Domstreams', 5);
		$this->addNavigationLink('Reports', '', 'base.reportActionTracking', 'Action Tracking', 1);
		$this->addNavigationLink('Reports', 'Action Tracking', 'base.reportActionGroups', 'Action Groups', 2);
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportGeolocation', 'Geo-location', 1);
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportHosts', 'Domains', 2);								
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportVisitorsLoyalty', 'Visitor Loyalty', 3);
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportVisitorsRecency', 'Visitor Recency', 4);
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportVisitorsAge', 'Visitor Age', 5);
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportBrowsers', 'Browser Types', 6);
		$this->addNavigationLink('Reports', 'Visitors', 'base.reportOs', 'Operating Systems', 7);
		
		$this->addNavigationLink('Reports', '', 'base.reportTraffic', 'Traffic', 2);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportKeywords', 'Search Terms', 1);								
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportAnchortext', 'Inbound Link Text', 2);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportSearchEngines', 'Search Engines', 3);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportReferringSites', 'Referring Web Sites', 4);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportCampaigns', 'Campaigns', 5);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportAds', 'Ad Performance', 6);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportAdTypes', 'Ad Types', 7);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportCreativePerformance', 'Creative Performance', 8);
		$this->addNavigationLink('Reports', 'Traffic', 'base.reportAttributionHistory', 'Attribution History', 8);
		$this->addNavigationLink('Reports', '', 'base.reportGoals', 'Goals', 5);
		$this->addNavigationLink('Reports', 'Goals', 'base.reportGoalFunnel', 'Funnel Visualization', 1);	
				
	}
	
	/**
	 * Registers Event Handlers with queue queue
	 *
	 */
	function _registerEventHandlers() {
		
		// User management
		$this->registerEventHandler(array('base.set_password', 'base.reset_password', 'base.new_user_account'), 'userHandlers');
		// Page Requests
		$this->registerEventHandler(array('base.page_request', 'base.first_page_request'), 'requestHandlers');
		// Sessions
		$this->registerEventHandler(array('base.page_request_logged', 'base.first_page_request_logged'), 'sessionHandlers');
		// Clicks
		$this->registerEventHandler('dom.click', 'clickHandlers');
		// Documents
		$this->registerEventHandler(array(
				'base.page_request_logged', 
				'base.first_page_request_logged', 
				'base.feed_request_logged') , 'documentHandlers');
		// Referers
		$this->registerEventHandler('base.new_session', 'refererHandlers');
		// Search Terms
		$this->registerEventHandler('base.new_session', 'searchTermHandlers');
		// Location
		$this->registerEventHandler( array( 'base.new_session', 'ecommerce.transaction' ), 'locationHandlers' );
		// operating systems
		$this->registerEventHandler('base.new_session', 'osHandlers');
		// source dimension
		$this->registerEventHandler('base.page_request', 'sourceHandlers');
		// campaign dimension
		$this->registerEventHandler('base.page_request', 'campaignHandlers');
		// ad dimension
		$this->registerEventHandler('base.page_request', 'adHandlers');
		// conversions
		$this->registerEventHandler(array(
				'base.new_session', 
				'base.session_update', 
				'ecommerce.transaction_persisted' ), 'conversionHandlers');
		// User Agent dimension
		$this->registerEventHandler(array('base.feed_request', 'base.new_session'), 'userAgentHandlers');
		// Hosts
		$this->registerEventHandler(array('base.feed_request', 'base.new_session'), 'hostHandlers');
		// Hosts
		$this->registerEventHandler('base.feed_request', 'feedRequestHandlers');
		// User management
		$this->registerEventHandler('base.new_session', 'visitorHandlers');
		// Nofifcation handlers
		$this->registerEventHandler('base.new_session', 'notifyHandlers');
		// install complete handler
		$this->registerEventHandler('install_complete', $this, 'installCompleteHandler');
		// domstreams
		$this->registerEventHandler('dom.stream', 'domstreamHandlers');
		// actions
		$this->registerEventHandler('track.action', 'actionHandler');
		// Commerce
		$this->registerEventHandler('ecommerce.transaction', 'commerceTransactionHandlers');
		$this->registerEventHandler('ecommerce.transaction_persisted', 'sessionCommerceSummaryHandlers');
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
				'queue_item')
			);
		
	}
	
	function installCompleteHandler($event) {
		
		//owa_coreAPI::debug('test handler: '.print_r($event, true));
	}
	
	/**
	 * Determine the operating system of the browser making the request
	 *
	 * @param string $user_agent
	 * @return string
	 */
	function determineOperatingSystem($os = '', $ua) {
		
		if (empty($os)) {
		
			$matches = array(
				'Win.*NT 5\.0'					=>'Windows 2000',
				'Win.*NT 5.1'					=>'Windows XP',
				'Win.*(Vista|XP|2000|ME|NT|9.?)'=>'Windows $1',
				'Windows .*(3\.11|NT)'			=>'Windows $1',
				'Win32'							=>'Windows [prior to 1995]',
				'Linux 2\.(.?)\.'				=>'Linux 2.$1.x',
				'Linux'							=>'Linux [unknown version]',
				'FreeBSD .*-CURRENT$'			=>'FreeBSD -CURRENT',
				'FreeBSD (.?)\.'				=>'FreeBSD $1.x',
				'NetBSD 1\.(.?)\.'				=>'NetBSD 1.$1.x',
				'(Free|Net|Open)BSD'			=>'$1BSD [unknown]',
				'HP-UX B\.(10|11)\.'			=>'HP-UX B.$1.x',
				'IRIX(64)? 6\.'					=>'IRIX 6.x',
				'SunOS 4\.1'					=>'SunOS 4.1.x',
				'SunOS 5\.([4-6])'				=>'Solaris 2.$1.x',
				'SunOS 5\.([78])'				=>'Solaris $1.x',
				'Mac_PowerPC'					=>'Mac OS [PowerPC]',
				'Mac OS X'						=>'Mac OS X',
				'X11'							=>'UNIX [unknown]',
				'Unix'							=>'UNIX [unknown]',
				'BeOS'							=>'BeOS [unknown]',
				'QNX'							=>'QNX [unknown]',
			);
			
			$uas = array_map(create_function('$a', 'return "#.*$a.*#";'), array_keys($matches));
			
			$os = preg_replace($uas, array_values($matches), $ua);
		}
			
		return $os;
	}
	
	/**
	 * Get IP address from request
	 *
	 * @return string
	 * @access private
	 */
	function setIp($ip) {
	
		$HTTP_X_FORWARDED_FOR = owa_coreAPI::getServerParam('HTTP_X_FORWARDED_FOR');
		$HTTP_CLIENT_IP = owa_coreAPI::getServerParam('HTTP_CLIENT_IP');
		
		// check for a non-unknown proxy address
		if (!empty($HTTP_X_FORWARDED_FOR) && strpos(strtolower($HTTP_X_FORWARDED_FOR), 'unknown') === false) {
			
			// if more than one use the last one
			if (strpos($HTTP_X_FORWARDED_FOR, ',') === false) {
				$ip = $HTTP_X_FORWARDED_FOR;
			} else {
				$ips = array_reverse(explode(",", $HTTP_X_FORWARDED_FOR));
				$ip = $ips[0];
			}
		
		// or else just use the remote address	
		} else {
		
			if ($HTTP_CLIENT_IP) {
		    	$ip = $HTTP_CLIENT_IP;
			}
			
		}
		
		return $ip;
	
	}
	
	/**
	 * Resolve hostname from IP address
	 * 
	 * @access public
	 */
	function resolveHost($remote_host = '', $ip_address = '') {
	
		// See if host is already resolved
		if (empty($remote_host)) {
			
			// Do the host lookup
			if (owa_coreAPI::getSetting('base', 'resolve_hosts')) {
				$remote_host = @gethostbyaddr($ip_address);
			}
			
		}
		
		return $remote_host;
	}
	
	function getHostDomain($fullhost = '', $ip_address = '') {
	
		$host = '';
		
		if (!empty($fullhost)) {
		
			// Sometimes gethostbyaddr returns 'unknown' or the IP address if it can't resolve the host
			if ($fullhost === 'localhost') {
				$host = 'localhost';
			} elseif ($fullhost === 'unknown') {
				$host = $ip_address;
			} elseif ($fullhost != $ip_address) {
		
				$host_array = explode('.', $fullhost);
				
				// resort so top level domain is first in array
				$host_array = array_reverse($host_array);
				
				// array of tlds. this should probably be in the config array not here.
				$tlds = array('com', 'net', 'org', 'gov', 'mil', 'edu');
				
				if (in_array($host_array[0], $tlds)) {
					$host = $host_array[1].".".$host_array[0];
				} else {
					$host = $host_array[2].".".$host_array[1].".".$host_array[0];
				}
					
			}
				
		} else {
			$host = $ip_address;
		}
		
		return $host;
	}
	
	/**
	 * Filter function Strips a URL of certain defined session or tracking params
	 *
	 * @return string
	 */
	function makeUrlCanonical($url, $site_id = '') {
		
		owa_coreAPI::debug('makeUrlCanonical using site_id: '.$site_id);
		//remove anchors
		$pos = strpos($url, '#');
		if($pos) {
			
			$url = substr($url, 0, $pos);
		}
		
		$filter_string = owa_coreAPI::getSiteSetting($site_id, 'query_string_filters');
		
		if ($filter_string) {
			$filters = str_replace(' ', '', $filter_string);
			$filters = explode(',', $filter_string);
		} else {
			$filters = array();
		}
		
		// merge global filters
		$global_filters = owa_coreAPI::getSetting('base', 'query_string_filters');
		if ($global_filters) {
			$global_filters = str_replace(' ', '', $global_filters);
			$global_filters = explode(',', $global_filters);
			$filters = array_merge($global_filters, $filters);
		}
			
		// OWA specific params to filter
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'source');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'medium');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'campaign');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'ad');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'ad_type');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'overlay');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').'state');
		array_push($filters, owa_coreAPI::getSetting('base', 'ns').owa_coreAPI::getSetting('base', 'feed_subscription_param'));
		
		//print_r($filters);
		
		foreach ($filters as $filter => $value) {
			
		  $url = preg_replace(
			'#\?' .
			$value .
			'=.*$|&' .
			$value .
			'=.*$|' .
			$value .
			'=.*&#msiU',
			'',
			$url
		  );
		  
		}
	        
	        
	    //check for dangling '?'. this might occure if all params are stripped.
	        
	    // returns last character of string
		$test = substr($url, -1);   		
		
		// if dangling '?' is found clean up the url by removing it.
		if ($test == '?') {
			$url = substr($url, 0, -1);
		}
		
		//check and remove default page
		$default_page = owa_coreAPI::getSiteSetting($site_id, 'default_page');
		
		if ($default_page) {
		
			$default_length = strlen($default_page);
			
			if ($default_length) {
				
				//test for string
				$default_test = substr($url, 0 - $default_length, $default_length);
				if ($default_test === $default_page) {
					$url = substr($url, 0, 0 - $default_length);
				}
			}
		}
				
		// check and remove trailing slash
		if (substr($url, -1) === '/') {
			
			$url = substr($url, 0, -1);
		}
			
     	return $url;
		
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
	function getResultSet($metrics, $dimensions = '', $siteId = '', $constraints = '', $sort = '', $resultsPerPage = '', $page = '', $offset = '', $period = '', $startDate = '', $endDate = '', $startTime = '', $endTime = '', $format = '') {
		
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
			$rsm->setConstraints($rsm->constraintsStringToArray('siteId=='.$siteId));
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
	
	function getVisitDetail($sessionId, $format = '') {
	
		if ($sessionId) {
		
			$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
			$db = owa_coreAPI::dbSingleton();
			
			$s = owa_coreAPI::entityFactory('base.session');
			$h = owa_coreAPI::entityFactory('base.host');
			$ua = owa_coreAPI::entityFactory('base.ua');
			$d = owa_coreAPI::entityFactory('base.document');
			$v = owa_coreAPI::entityFactory('base.visitor');
			$r = owa_coreAPI::entityFactory('base.referer');
			
			$db->selectFrom($s->getTableName());
			
			$db->selectColumn($s->getColumnsSql('session_'));
			$db->selectColumn($h->getColumnsSql('host_'));
			$db->selectColumn($ua->getColumnsSql('ua_'));
			$db->selectColumn($d->getColumnsSql('document_'));
			$db->selectColumn($v->getColumnsSql('visitor_'));
			$db->selectColumn($r->getColumnsSql('referer_'));
			
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $h->getTableName(), '', 'host_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $ua->getTableName(), '', 'ua_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), '', 'first_page_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $v->getTableName(), '', 'visitor_id');
			$db->join(OWA_SQL_JOIN_LEFT_OUTER, $r->getTableName(), '', 'referer_id');
			
			
			$db->where($s->getTableName().'.id', $sessionId);
			
			
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
		$ua = owa_coreAPI::entityFactory('base.ua');
		$d = owa_coreAPI::entityFactory('base.document');
		$v = owa_coreAPI::entityFactory('base.visitor');
		$r = owa_coreAPI::entityFactory('base.referer');
		
		$db->selectFrom($s->getTableName());
		
		$db->selectColumn($s->getColumnsSql('session_'));
		$db->selectColumn($h->getColumnsSql('host_'));
		$db->selectColumn($ua->getColumnsSql('ua_'));
		$db->selectColumn($d->getColumnsSql('document_'));
		$db->selectColumn($v->getColumnsSql('visitor_'));
		$db->selectColumn($r->getColumnsSql('referer_'));
		
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $h->getTableName(), '', 'host_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $ua->getTableName(), '', 'ua_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), '', 'first_page_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $v->getTableName(), '', 'visitor_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $r->getTableName(), '', 'referer_id');
			
		$db->orderBy('session_timestamp','DESC');
		
		if ($visitorId) {
			$db->where('visitor_id', $visitorId);
		}
		
		if ($siteId) {
			$db->where('site_id', $siteId);
		}
		
		if ($startDate && $endDate) {
			$db->where('owa_session.yyyymmdd', array('start' => $startDate, 'end' => $endDate), 'BETWEEN');
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
		foreach ($ret as $row) {
			$combined = $this->mergeStreamEvents( $row['events'], $combined );
		}
		
		$row['events'] = json_decode($combined);
		
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
    
    /*
    function eventProcessingDaemonJobs($jobs) {
    	
    	$source = owa_coreAPI::getSetting( 'base', 'event_queue_type' );
    	$dest = owa_coreAPI::getSetting( 'base', 'event_secondary_queue_type' );
    	
    	// check event file
    	$event_log_file = owa_coreAPI::getSetting( 'base', 'async_log_dir' ) . owa_coreAPI::getSetting( 'base', 'async_log_file' );
    	$event_log_rotate_size = owa_coreAPI::getSetting( 'base', 'async_log_rotate_size' );
    	if ( file_exists( $event_log_file ) && filesize( $event_log_file ) > $event_log_rotate_size ) {
    		$file_cmd = array('cmd=processEventQueue');
    		$file_cmd[] = 'source=file';
    		
    		if ( $dest ) {
    			$file_cmd[] = 'destination='.$dest;
    		}
    		$jobs['processEventQueue'] = array('cmd' => $file_cmd, 'max_workers' => 3, 'interval' => 100);
    		
    		$queue_file_exists = true;
    	}
    	
    	return $jobs;
    }
	*/
}


?>