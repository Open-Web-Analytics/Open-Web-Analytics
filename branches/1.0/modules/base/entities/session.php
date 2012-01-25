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

require_once( OWA_BASE_CLASS_DIR . 'factTable.php');

/**
 * Session Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_session extends owa_factTable {
	
	function __construct() {
	
		// table name
		$this->setTableName('session');
		$this->setSummaryLevel(1);
		
		// set common fact table columns
		$parent_columns = parent::__construct();
		
		// remove the session id from parent col list
		// as it's a duplicate to the id column for this entity.
		if (isset( $parent_columns['session_id'] ) ) {
			unset( $parent_columns['session_id'] );
		}
		
		foreach ($parent_columns as $pcolumn) {
				
			$this->setProperty($pcolumn);
		}
		
		// properties
		
		// move to abstract
		//$this->properties['id'] = new owa_dbColumn;
		//$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		//$this->properties['id']->setPrimaryKey();
		
		// move to abstract
		//$visitor_id = new owa_dbColumn('visitor_id', OWA_DTD_BIGINT);
		//$visitor_id->setForeignKey('base.visitor');
		//$this->setProperty($visitor_id);
		
		// move to abstract
		//$ts =  new owa_dbColumn;
		//$ts->setName('timestamp');
		//$ts->setDataType(OWA_DTD_BIGINT);
		//$ts->setIndex();
		//$this->setProperty($ts);
		
		// move to abstract
		//$yyyymmdd =  new owa_dbColumn;
		//$yyyymmdd->setName('yyyymmdd');
		//$yyyymmdd->setDataType(OWA_DTD_INT);
		//$yyyymmdd->setIndex();
		//$this->setProperty($yyyymmdd);
		
		// move to abstract
		//$this->properties['user_name'] = new owa_dbColumn;
		//$this->properties['user_name']->setDataType(OWA_DTD_VARCHAR255);
		
		// drop
		$this->properties['user_email'] = new owa_dbColumn;
		$this->properties['user_email']->setDataType(OWA_DTD_VARCHAR255);
		
		// move to abstract
		/*
		$this->properties['year'] = new owa_dbColumn;
		$this->properties['year']->setDataType(OWA_DTD_INT);
		$this->properties['month'] = new owa_dbColumn;
		$this->properties['month']->setDataType(OWA_DTD_INT);
		$this->properties['day'] = new owa_dbColumn;
		$this->properties['day']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['dayofweek'] = new owa_dbColumn;
		$this->properties['dayofweek']->setDataType(OWA_DTD_VARCHAR10);
		$this->properties['dayofyear'] = new owa_dbColumn;
		$this->properties['dayofyear']->setDataType(OWA_DTD_INT);
		$this->properties['weekofyear'] = new owa_dbColumn;
		$this->properties['weekofyear']->setDataType(OWA_DTD_INT);
		*/
		// drop these soon
		$this->properties['hour'] = new owa_dbColumn;
		$this->properties['hour']->setDataType(OWA_DTD_TINYINT2);
		$this->properties['minute'] = new owa_dbColumn;
		$this->properties['minute']->setDataType(OWA_DTD_TINYINT2);
		
		// move to abstract
		$this->properties['last_req'] = new owa_dbColumn;
		$this->properties['last_req']->setDataType(OWA_DTD_BIGINT);
		
		$this->properties['num_pageviews'] = new owa_dbColumn;
		$this->properties['num_pageviews']->setDataType(OWA_DTD_INT);
		
		// drop
		$this->properties['num_comments'] = new owa_dbColumn;
		$this->properties['num_comments']->setDataType(OWA_DTD_INT);
		
		// move to abstract
		$this->properties['is_repeat_visitor'] = new owa_dbColumn;
		$this->properties['is_repeat_visitor']->setDataType(OWA_DTD_TINYINT);
		
		// how to denormalize into other fact tables?
		$is_bounce =  new owa_dbColumn;
		$is_bounce->setName('is_bounce');
		$is_bounce->setDataType(OWA_DTD_TINYINT);
		$this->setProperty($is_bounce);
		
		// move to abstract
		$this->properties['is_new_visitor'] = new owa_dbColumn;
		$this->properties['is_new_visitor']->setDataType(OWA_DTD_TINYINT);
		
		// needed?
		$this->properties['prior_session_lastreq'] = new owa_dbColumn;
		$this->properties['prior_session_lastreq']->setDataType(OWA_DTD_BIGINT);
		
		// needed?
		$prior_session_id = new owa_dbColumn('prior_session_id', OWA_DTD_BIGINT);
		$this->setProperty($prior_session_id);
		
		// drop
		$this->properties['time_sinse_priorsession'] = new owa_dbColumn;
		$this->properties['time_sinse_priorsession']->setDataType(OWA_DTD_INT);
		// drop
		$this->properties['prior_session_year'] = new owa_dbColumn;
		$this->properties['prior_session_year']->setDataType(OWA_DTD_TINYINT4);
		// drop
		$this->properties['prior_session_month'] = new owa_dbColumn;
		$this->properties['prior_session_month']->setDataType(OWA_DTD_VARCHAR255);
		// drop
		$this->properties['prior_session_day'] = new owa_dbColumn;
		$this->properties['prior_session_day']->setDataType(OWA_DTD_TINYINT2);
		// drop
		$this->properties['prior_session_dayofweek'] = new owa_dbColumn;
		$this->properties['prior_session_dayofweek']->setDataType(OWA_DTD_INT);
		// drop
		$this->properties['prior_session_hour'] = new owa_dbColumn;
		$this->properties['prior_session_hour']->setDataType(OWA_DTD_TINYINT2);
		// drop
		$this->properties['prior_session_minute'] = new owa_dbColumn;
		$this->properties['prior_session_minute']->setDataType(OWA_DTD_TINYINT2);
		
		// move to abstract
		//$this->properties['days_since_prior_session'] = new owa_dbColumn;
		//$this->properties['days_since_prior_session']->setDataType(OWA_DTD_INT);
		
		// move to abstract
		//$this->properties['days_since_first_session'] = new owa_dbColumn;
		//$this->properties['days_since_first_session']->setDataType(OWA_DTD_INT);
		
		// drop
		$this->properties['os'] = new owa_dbColumn;
		$this->properties['os']->setDataType(OWA_DTD_VARCHAR255);
		
		// wrong data type
		// move to abstract
		// $os_id = new owa_dbColumn('os_id', OWA_DTD_VARCHAR255);
		// $os_id->setForeignKey('base.os');
		// $this->setProperty($os_id);
		
		// wrong data type
		// move to abstract
		//$ua_id = new owa_dbColumn('ua_id', OWA_DTD_VARCHAR255);
		//$ua_id->setForeignKey('base.ua');
		//$this->setProperty($ua_id);
		
		$first_page_id = new owa_dbColumn('first_page_id', OWA_DTD_BIGINT);
		$first_page_id->setForeignKey('base.document');
		$this->setProperty($first_page_id);
		
		$last_page_id = new owa_dbColumn('last_page_id', OWA_DTD_BIGINT);
		$last_page_id->setForeignKey('base.document');
		$this->setProperty($last_page_id);
		
		// move to abstract
		//$referer_id = new owa_dbColumn('referer_id', OWA_DTD_BIGINT);
		//$referer_id->setForeignKey('base.referer');
		//$this->setProperty($referer_id);
		
		// move to abstract
		//$referring_search_term_id = new owa_dbColumn('referring_search_term_id', OWA_DTD_BIGINT);
		//$referring_search_term_id->setForeignKey('base.search_term_dim');
		//$this->setProperty($referring_search_term_id);
		
		// move to abstract
		//$ip_address = new owa_dbColumn('ip_address', OWA_DTD_VARCHAR255);
		//$this->setProperty($ip_address);
		
		// drop
		$this->properties['host'] = new owa_dbColumn;
		$this->properties['host']->setDataType(OWA_DTD_VARCHAR255);
		
		// move to abstract
		// wrong data type
		//$host_id = new owa_dbColumn('host_id', OWA_DTD_VARCHAR255);
		//$host_id->setForeignKey('base.host');
		//$this->setProperty($host_id);
		
		//drop
		$this->properties['city'] = new owa_dbColumn;
		$this->properties['city']->setDataType(OWA_DTD_VARCHAR255);
		//drop
		$this->properties['country'] = new owa_dbColumn;
		$this->properties['country']->setDataType(OWA_DTD_VARCHAR255);
		// drop
		$this->properties['site'] = new owa_dbColumn;
		$this->properties['site']->setDataType(OWA_DTD_VARCHAR255);
		
		// move to abstract
		//$site_id = new owa_dbColumn('site_id', OWA_DTD_VARCHAR255);
		//$site_id->setForeignKey('base.site', 'site_id');
		//$this->setProperty($site_id);
		
		// move to abstract
		//$nps = new owa_dbColumn('num_prior_sessions', OWA_DTD_INT);
		//$this->setProperty($nps);
		
		//drop
		$this->properties['is_robot'] = new owa_dbColumn;
		$this->properties['is_robot']->setDataType(OWA_DTD_TINYINT);
		//drop
		$this->properties['is_browser'] = new owa_dbColumn;
		$this->properties['is_browser']->setDataType(OWA_DTD_TINYINT);
		//drop
		$this->properties['is_feedreader'] = new owa_dbColumn;
		$this->properties['is_feedreader']->setDataType(OWA_DTD_TINYINT);
		
		// move to abstract
		//$medium = new owa_dbColumn('medium',OWA_DTD_VARCHAR255);
		//$this->setProperty($medium);
		
		// move to abstract
		//$source_id = new owa_dbColumn('source_id', OWA_DTD_BIGINT);
		//$source_id->setForeignKey('base.source_dim');
		//$this->setProperty($source_id);
		
		// move to abstract
		//$ad_id = new owa_dbColumn('ad_id', OWA_DTD_BIGINT);
		//$ad_id->setForeignKey('base.ad_dim');
		//$this->setProperty($ad_id);
		
		// move to abstract
		//$campaign_id = new owa_dbColumn('campaign_id', OWA_DTD_BIGINT);
		//$campaign_id->setForeignKey('base.campaign_dim');
		//$this->setProperty($campaign_id);
		
		$this->properties['latest_attributions'] = new owa_dbColumn;
		$this->properties['latest_attributions']->setDataType(OWA_DTD_BLOB);
		
		// create goal related columns
		$gcount = owa_coreAPI::getSetting('base', 'numGoals');
		for ($num = 1; $num <= $gcount;$num++) {
			$col_name = 'goal_'.$num;
			$goal_col = new owa_dbColumn($col_name, OWA_DTD_TINYINT);
			$this->setProperty($goal_col);
			$col_name = 'goal_'.$num.'_start';
			$goal_col = new owa_dbColumn($col_name, OWA_DTD_TINYINT);
			$this->setProperty($goal_col);
			$col_name = 'goal_'.$num.'_value';
			$goal_col = new owa_dbColumn($col_name, OWA_DTD_BIGINT);
			$this->setProperty($goal_col);
		}
	
		$num_goals = new owa_dbColumn('num_goals', OWA_DTD_TINYINT);
		$this->setProperty($num_goals);
		
		$num_goal_starts = new owa_dbColumn('num_goal_starts', OWA_DTD_TINYINT);
		$this->setProperty($num_goal_starts);
	
		$goals_value = new owa_dbColumn('goals_value', OWA_DTD_BIGINT);
		$this->setProperty($goals_value);	
		
		// move to abstract
		//location
		//$location_id = new owa_dbColumn('location_id', OWA_DTD_BIGINT);
		//$location_id->setForeignKey('base.location_dim');
		//$this->setProperty($location_id);
		
		// move to abstract
		// language
		//$language = new owa_dbColumn('language', OWA_DTD_VARCHAR255);
		//$this->setProperty($language);
		
		// transaction count
		$commerce_trans_count = new owa_dbColumn('commerce_trans_count', OWA_DTD_INT);
		$this->setProperty($commerce_trans_count);
		// revenue including tax and shipping
		$commerce_trans_revenue = new owa_dbColumn('commerce_trans_revenue', OWA_DTD_BIGINT);
		$this->setProperty($commerce_trans_revenue);
		// revenue excluding tax and shipping
		$commerce_items_revenue = new owa_dbColumn('commerce_items_revenue', OWA_DTD_BIGINT);
		$this->setProperty($commerce_items_revenue);
		// distinct number of items
		$commerce_items_count = new owa_dbColumn('commerce_items_count', OWA_DTD_INT);
		$this->setProperty($commerce_items_count);
		// total quantity of all items
		$commerce_items_quantity = new owa_dbColumn('commerce_items_quantity', OWA_DTD_INT);
		$this->setProperty($commerce_items_quantity);
		// shipping revenue
		$commerce_shipping_revenue = new owa_dbColumn('commerce_shipping_revenue', OWA_DTD_BIGINT);
		$this->setProperty($commerce_shipping_revenue);
		// tax revenue
		$commerce_tax_revenue = new owa_dbColumn('commerce_tax_revenue', OWA_DTD_BIGINT);
		$this->setProperty($commerce_tax_revenue);
		
	}
}

?>