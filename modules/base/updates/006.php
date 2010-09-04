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
 * 006 Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */


class owa_base_006_update extends owa_update {
	
	var $schema_version = 6;
	var $is_cli_mode_required = true;
	
	function up() {
		
		$session = owa_coreAPI::entityFactory('base.session');
		$session_columns = array(
				'num_goals', 
				'num_goal_starts',
				'goals_value', 
				'location_id', 
				'language', 
				'source_id', 
				'ad_id', 
				'campaign_id', 
				'latest_attributions',
				'commerce_trans_count',
				'commerce_trans_revenue',
				'commerce_items_revenue',
				'commerce_items_count',
				'commerce_items_quantity',
				'commerce_shipping_revenue',
				'commerce_tax_revenue');
				
		// create goal related columns
		$goals = owa_coreAPI::getSetting('base', 'goals');
		
		foreach ( $goals as $num => $g ) {
			$session_columns[] = 'goal_'.$num;
			$session_columns[] = 'goal_'.$num.'_start';
			$session_columns[] = 'goal_'.$num.'_value';
		}
		// add columns to owa_session
		foreach ( $session_columns as $session_col_name ) {
			$ret = $session->addColumn( $session_col_name );
			if ( $ret === true ) {
				$this->e->notice( "$session_col_name added to owa_session" );
			} else {
				$this->e->notice( "Adding $session_col_name to owa_session failed." );
				return false;
			}
		}
		//rename col
		$ret = $session->renameColumn('source', 'medium');	
		if (!$ret) {
			$this->e->notice('Failed to rename source column to medium in owa_session');
			return false;
		}
		
		$request = owa_coreAPI::entityFactory('base.request');
		$request_columns = array( 
				'location_id',
				'language');
		
		// add columns to owa_session
		foreach ( $request_columns as $request_col_name ) {
			$ret = $request->addColumn( $request_col_name );
			if ( $ret === true ) {
				$this->e->notice( "$request_col_name added to owa_request" );
			} else {
				$this->e->notice( "Adding $request_col_name to owa_request failed." );
				return false;
			}
		}
		
		//create new entitiy tables
		$new_entities = array(
				'base.ad_dim', 
				'base.source_dim', 
				'base.campaign_dim',
				'base.location_dim',
				'base.commerce_transaction_fact',
				'base.commerce_line_item_fact');
		foreach ($new_entities as $entity_name) {
			$entity = owa_coreAPI::entityFactory($entity_name);
			$ret = $entity->createTable();
				
			if ($ret === true) {
				$this->e->notice("$entity_name table created.");
			} else {
				$this->e->notice("$entity_name table failed.");
				return false;
			}
		}
		
		// must return true
		return true;
	}
	
	function down() {
	
		$session = owa_coreAPI::entityFactory('base.session');
		// owa_session columns to drop
		$session_columns = array(
				'num_goals',
				'num_goal_starts',
				'goals_value', 
				'location_id', 
				'language', 
				'source_id', 
				'ad_id', 
				'campaign_id', 
				'latest_attributions',
				'commerce_trans_count',
				'commerce_trans_revenue',
				'commerce_items_revenue',
				'commerce_items_count',
				'commerce_items_quantity',
				'commerce_shipping_revenue',
				'commerce_tax_revenue');
				
		// add in goal related columns
		$goals = owa_coreAPI::getSetting('base', 'goals');
		foreach ($goals as $num => $g) {
			$session_columns[] = 'goal_'.$num;
			$session_columns[] = 'goal_'.$num.'_start';
			$session_columns[] = 'goal_'.$num.'_value';
		}
		//drop columns from owa_session
		foreach ($session_columns as $session_col_name) {
			$session->dropColumn($session_col_name);
		}
		//rename col back to original
		$session->renameColumn('medium', 'source', true);
		
		//drop request columns
		$request = owa_coreAPI::entityFactory('base.request');
		$request_columns = array( 
				'location_id',
				'language');
		
		// add columns to owa_session
		foreach ( $request_columns as $request_col_name ) {
			$ret = $request->dropColumn( $request_col_name );
		}
		
		//drop tables
		$new_entities = array(
				'base.ad_dim', 
				'base.source_dim', 
				'base.campaign_dim',
				'base.location_dim',
				'base.commerce_transaction_fact',
				'base.commerce_line_item_fact');
		
		foreach ($new_entities as $entity_name) {
			$entity = owa_coreAPI::entityFactory($entity_name);
			$ret = $entity->dropTable();
		}
				
		return true;
	}
}

?>