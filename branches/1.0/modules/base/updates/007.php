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

/**
 * 007 Schema Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.5.0
 */


class owa_base_007_update extends owa_update {
	
	var $schema_version = 7;
	var $is_cli_mode_required = true;
	var $entities = array();
	
	function __construct() {
		
		$this->entities['base.action_fact']['addColumn'] = array(
				'last_req',
				'ip_address',
				'num_prior_sessions',
				'is_new_visitor',
				'is_repeat_visitor',
				'location_id',
				'language',
				'referer_id',
				'referring_search_term_id',
				'days_since_prior_session',
				'days_since_first_session',
				'medium',
				'source_id',
				'ad_id',
				'campaign_id',
				'day',
				'month',
				'year',
				'dayofweek',
				'dayofyear',
				'weekofyear',
				'user_name'
		);
		
		$this->entities['base.domstream']['addColumn'] = array(
				'last_req',
				'ip_address',
				'num_prior_sessions',
				'is_new_visitor',
				'is_repeat_visitor',
				'location_id',
				'language',
				'referer_id',
				'referring_search_term_id',
				'days_since_prior_session',
				'days_since_first_session',
				'medium',
				'source_id',
				'ad_id',
				'campaign_id',
				'ua_id',
				'host_id',
				'os_id',
				'day',
				'month',
				'year',
				'dayofweek',
				'dayofyear',
				'weekofyear',
				'user_name'
		);
		
		$this->entities['base.click']['addColumn'] = array(
				
				'referring_search_term_id',
				'days_since_prior_session',
				'days_since_first_session',
				'medium',
				'source_id',
				'os_id',
				'last_req',
				'num_prior_sessions',
				'is_new_visitor',
				'is_repeat_visitor',
				'location_id',
				'language',
				'referer_id',
				'user_name',
				'dayofweek'
				
		);
		

		$this->entities['base.request']['addColumn'] = array(
				
				'referring_search_term_id',
				'days_since_prior_session',
				'days_since_first_session',
				'medium',
				'source_id',
				'ad_id',
				'campaign_id'
		);
		
		$this->entities['base.commerce_transaction_fact']['addColumn'] = array(
				
				'days_since_prior_session',
				'last_req',
				'language',
				'ip_address',
				'is_new_visitor',
				'is_repeat_visitor',
				'day',
				'month',
				'year',
				'dayofweek',
				'dayofyear',
				'weekofyear',
				'user_name'
				
		);
		
		$this->entities['base.commerce_line_item_fact']['addColumn'] = array(
				
				'days_since_prior_session',
				'days_since_first_session',
				'num_prior_sessions',
				'last_req',
				'language',
				'ip_address',
				'is_new_visitor',
				'is_repeat_visitor',
				'referer_id',
				'day',
				'month',
				'year',
				'dayofweek',
				'dayofyear',
				'weekofyear',
				'user_name'
				
		);
		
		// custom variable columns
		$cv_max = owa_coreAPI::getSetting( 'base', 'maxCustomVars' );
		$fact_table_entities = array(
				'base.action_fact',
				'base.request',
				'base.session',
				'base.domstream',
				'base.click',
				'base.commerce_transaction_fact',
				'base.commerce_line_item_fact',
		
		);
		
		for ($i = 1; $i <= $cv_max;$i++) {
		
			foreach( $fact_table_entities as $fact_table_entity ) {
			
				$this->entities[$fact_table_entity]['addColumn'][] = 'cv'.$i.'_name';
				$this->entities[$fact_table_entity]['addColumn'][] = 'cv'.$i.'_value';
			}

		}
		
		return parent::__construct();
	}
	
	function up($force = false) {
		
		foreach ( $this->entities as $entity => $operations) {
			$e = owa_coreAPI::entityFactory($entity); 
			foreach ( $operations as $operation => $items ) {
				foreach ($items as $item) {
					$ret = $e->$operation( $item );
					if ( $ret === true ) {
						$this->e->notice( "Applied $operation on $entity for $item" );
					} else {
					
						if  ( ! $force ) {
							$this->e->notice( "Applying $operation on $entity for $item failed." );
							return false;
						} else {
							$this->e->notice( "Forced $operation on $entity for $item failed." );
						}
					}
				}				
			}
		}
		
		// convert text cols to blobs for storing serialized data
		
		$db = owa_coreAPI::dbSingleton();
		
		$ret = $db->query('ALTER TABLE owa_queue_item MODIFY event BLOB');
		if ( $ret === true ) {
			$this->e->notice( "event column modified in owa_queue_item" );
		} else {
			$this->e->notice( "modify of event column in owa_queue_item failed." );
			return false;
		}
		
		$ret = $db->query('ALTER TABLE owa_click MODIFY target_url VARCHAR(255)');
		if ( $ret === true ) {
			$this->e->notice( "target_url column modified in owa_click" );
		} else {
			$this->e->notice( "modify of target_url column in owa_click failed." );
			return false;
		}
		

		$ret = $db->query('ALTER TABLE owa_session MODIFY latest_attributions BLOB');
		if ( $ret === true ) {
			$this->e->notice( "latest_attributions column modified in owa_session" );
		} else {
			$this->e->notice( "modify of latest_attributions column in owa_session failed." );
			return false;
		}
		
		$ret = $db->query('ALTER TABLE owa_site MODIFY settings BLOB');
		if ( $ret === true ) {
			$this->e->notice( "settings column modified in owa_site" );
		} else {
			$this->e->notice( "modify of settings column in owa_site failed." );
			return false;
		}
		
		$ret = $db->query('ALTER TABLE owa_configuration MODIFY settings BLOB');
		if ( $ret === true ) {
			$this->e->notice( "settings column modified in owa_configuration" );
		} else {
			$this->e->notice( "modify of settings column in owa_configuration failed." );
			return false;
		}
		
		$ret = $db->query('ALTER TABLE owa_domstream MODIFY events BLOB');
		if ( $ret === true ) {
			$this->e->notice( "events column modified in owa_domstream" );
		} else {
			$this->e->notice( "modify of events column in owa_domstream failed." );
			return false;
		}
		
		// migrate month column
		$fact_table = array(
			'owa_request',
			'owa_session',
			'owa_action_fact',
			'owa_domstream',
			'owa_commerce_line_item_fact',
			'owa_commerce_transaction_fact',
			'owa_click'
		);
		
		foreach ($fact_table as $table) {
		
			$ret = $db->query(
				"update $table set month = concat(cast(year as CHAR), lpad(CAST(month AS CHAR), 2, '0'))"
			);
				
			if ($ret == true) {
				$this->e->notice('Updated month column in '.$table);
			} else {
				$this->e->notice('Failed to update month column in '.$table);
				return false;
			}
			
			// add site_id index
			$db->addIndex( $table, 'site_id' );
			
			if ( $table != 'owa_session' ) {
				$db->addIndex( $table, 'session_id' );	
			}
		}
				
		// add indexes
		$db->addIndex( 'owa_action_fact', 'yyyymmdd' );
		
		$db->addIndex( 'owa_action_fact', 'action_group, action_name' );
		$db->addIndex( 'owa_commerce_transaction_fact', 'yyyymmdd' );
		$db->addIndex( 'owa_commerce_line_item_fact', 'yyyymmdd' );
		$db->addIndex( 'owa_queue_item', 'status' );
		$db->addIndex( 'owa_queue_item', 'event_type' );
		$db->addIndex( 'owa_queue_item', 'not_before_timestamp' );
		$db->addIndex( 'owa_domstream', 'yyyymmdd' );
		$db->addIndex( 'owa_domstream', 'domstream_guid' );
		$db->addIndex( 'owa_domstream', 'document_id' );
		
		
		// must return true
		return true;
	}
	
	function down() {
	
		foreach ( $this->entities as $entity => $operations) {
			
			$e = owa_coreAPI::entityFactory($entity); 
			
			foreach ( $operations as $operation => $items ) {
				
				foreach ($items as $item) {
				
					if ($operation === 'addColumn') {
						$operation = 'dropColumn';
					}	
					$ret = $e->$operation( $item );
					if ( $ret === true ) {
						$this->e->notice( "Applied $operation on $entity for $item" );
					} else {
						$this->e->notice( "Applying $operation on $entity for $item failed." );
						
					}
				}
			}
		}
		
		// drop indexes
		$db = owa_coreAPI::dbSingleton();
		$db->dropIndex( 'owa_action_fact', 'yyyymmdd' );
		$db->dropIndex( 'owa_action_fact', 'action_group' );
		$db->dropIndex( 'owa_commerce_transaction_fact', 'yyyymmdd' );
		$db->dropIndex( 'owa_commerce_line_item_fact', 'yyyymmdd' );
		$db->dropIndex( 'owa_queue_item', 'status' );
		$db->dropIndex( 'owa_queue_item', 'event_type' );
		$db->dropIndex( 'owa_queue_item', 'not_before_timestamp' );
		$db->dropIndex( 'owa_domstream', 'yyyymmdd' );
		$db->dropIndex( 'owa_domstream', 'domstream_guid' );
		$db->dropIndex( 'owa_domstream', 'document_id' );
						
		return true;
	}
}

?>