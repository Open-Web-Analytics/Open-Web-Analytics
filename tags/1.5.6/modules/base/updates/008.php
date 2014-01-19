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
 * 008 Schema Update Class
 * 
 * @author     Daniel Pötzinger <poetzinger@googlemail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.5.0
 */


class owa_base_008_update extends owa_update {
	
	var $schema_version = 8;
	
	
	function up($force = false) {
		$site = owa_coreAPI::entityFactory('base.site_user'); 
		$ret = $site->createTable('site_user');
		if ($ret === false ) {
			$this->e->notice('Create table site_user failed');
			return false;
		}
		
		return true;
	}
	
	function down() {
		$site = owa_coreAPI::entityFactory('base.site_user'); 
		$ret = $site->dropTable('site_user');
		return true;
	}
}

?>