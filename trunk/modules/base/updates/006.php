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
		
		$ret = $session->renameColumn('source', 'medium');	
		if (!$ret) {
			$this->e->notice('Failed to rename source column to medium in owa_session');
			return false;
		}
		
		$ret = $session->addColumn('source_id');
		if (!$ret) {
			$this->e->notice('Failed to add source_id column to owa_session');
			return false;
		}
		
		$ret = $session->addColumn('ad_id');
		if (!$ret) {
			$this->e->notice('Failed to add ad_id column to owa_session');
			return false;
		}
		
		$ret = $session->addColumn('campaign_id');
		if (!$ret) {
			$this->e->notice('Failed to add campaign_id column to owa_session');
			return false;
		}
		
		$ret = $session->addColumn('latest_attributions');
		if (!$ret) {
			$this->e->notice('Failed to add latest_attributions column to owa_session');
			return false;
		}
		
		$ad = owa_coreAPI::entityFactory('base.ad_dim');
		$ret = $ad->createTable();
		
		if ($ret === true) {
			$this->e->notice('Ad Dimension entity table created');
		} else {
			$this->e->notice('Ad Dimension entity table creation failed');
			return false;
		}
		
		$source = owa_coreAPI::entityFactory('base.source_dim');
		$ret = $source->createTable();
		
		if ($ret === true) {
			$this->e->notice('Source Dimension entity table created');
		} else {
			$this->e->notice('Source Dimension entity table creation failed');
			return false;
		}
		
		$campaign = owa_coreAPI::entityFactory('base.campaign_dim');
		$ret = $campaign->createTable();
		
		if ($ret === true) {
			$this->e->notice('Campaign Dimension entity table created');
		} else {
			$this->e->notice('Campaign Dimension entity table creation failed');
			return false;
		}
		
		// must return true
		return true;
	}
	
	function down() {
	
		$session = owa_coreAPI::entityFactory('base.session');
		$session->dropColumn('source_id');
		$session->renameColumn('medium', 'source', true);
		$session->dropColumn('ad_id');
		$session->dropColumn('campaign_id');
		$session->dropColumn('latest_attributions');
		$ad = owa_coreAPI::entityFactory('base.ad_dim');
		$ad->dropTable();
		$source = owa_coreAPI::entityFactory('base.source_dim');
		$source->dropTable();
		$campaign = owa_coreAPI::entityFactory('base.campaign_dim');
		$campaign->dropTable();
			
		return true;
	}
}

?>