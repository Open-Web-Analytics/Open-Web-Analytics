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
 * 005 Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3
 */


class owa_base_005_update extends owa_update {
	
	var $schema_version = 5;
	
	function up() {
		
		$tables = array('owa_session', 'owa_request', 'owa_click', 'owa_feed_request');
		
		foreach ($tables as $table) {
				
			// add yyyymmdd column to owa_session
			$db = owa_coreAPI::dbSingleton();
			$db->addColumn($table, 'yyyymmdd', 'INT');
			$db->addIndex($table, 'yyyymmdd');
			$ret = $db->query("update $table set yyyymmdd = 
						concat(cast(year as CHAR), lpad(CAST(month AS CHAR), 2, '0'), lpad(CAST(day AS CHAR), 2, '0')) ");
			
			if ($ret == true) {
				$this->e->notice('Added yyyymmdd column to '.$table);
			} else {
				$this->e->notice('Failed to add yyyymmdd column to '.$table);
				return false;
			}	
		}
		
		$visitor = owa_coreAPI::entityFactory('base.visitor');
		
		$ret = $visitor->addColumn('num_prior_sessions');
		
		if (!$ret) {
			$this->e->notice('Failed to add num_prior_sessions column to owa_visitor');
			return false;
		}
		
		
		$session = owa_coreAPI::entityFactory('base.session');
		
		$ret = $session->addColumn('is_bounce');
		
		if (!$ret) {
			$this->e->notice('Failed to add is_bounce column to owa_session');
			return false;
		}
		
		$ret = $db->query("update owa_session set is_bounce = true WHERE num_pageviews = 1");
		
		if (!$ret) {
			$this->e->notice('Failed to populate is_bounce column in owa_session');
			return false;
		}
		
		
		// add api column
		$u = owa_coreAPI::entityFactory('base.user');
		$ret = $u->addColumn('api_key');
		
		if (!$ret) {
			$this->e->notice('Failed to add api_key column to owa_user');
			return false;
		}
		
		$a = owa_coreAPI::entityFactory('base.action_fact');
		$ret = $a->createTable();
		
		if ($ret === true) {
			$this->e->notice('Action fact entity table created');
		} else {
			$this->e->notice('Action fact entity table creation failed');
			return false;
		}		
		
		// add apiKeys to each user
		$users = $db->get_results("select user_id from owa_user");
		
		foreach ($users as $user) {
			
			$u = owa_coreAPI::entityFactory('base.user');
			$u->load($user['user_id'],'user_id');
			$u->set('api_key', $u->generateTempPasskey($u->get('user_id')));
			$u->update();
		}
		
		// must return true
		return true;
	}
	
	function down() {
	
		$visitor = owa_coreAPI::entityFactory('base.visitor');
		$visitor->dropColumn('num_prior_sessions');
		$session = owa_coreAPI::entityFactory('base.session');
		$session->dropColumn('yyyymmdd');
		$session->dropColumn('is_bounce');
		$request = owa_coreAPI::entityFactory('base.request');
		$request->dropColumn('yyyymmdd');
		$request = owa_coreAPI::entityFactory('base.click');
		$request->dropColumn('yyyymmdd');
		$feed_request = owa_coreAPI::entityFactory('base.feed_request');
		$feed_request->dropColumn('yyyymmdd');
		$u = owa_coreAPI::entityFactory('base.user');
		$u->dropColumn('api_key');
		
		
		return true;
	}
}

?>