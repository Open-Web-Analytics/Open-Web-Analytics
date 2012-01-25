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
 * 003 Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_base_003_update extends owa_update {

	function up() {
		
		$db = owa_coreAPI::dbSingleton();
		$s = owa_coreAPI::serviceSingleton();
		
		$entities = $s->modules[$this->module_name]->getEntities();
		
		foreach ($entities as $k => $v) {
		
			$ret = $db->alterTableType($this->c->get('base', 'ns').$v, 'InnoDB');
			
			if ($ret == true):
				$this->e->notice(sprintf('Changed Table %s to InnoDB', $v));
			else:
				$this->e->notice(sprintf('Change to Table %s failed', $v));
				return false;
			endif;
		
		}
		
		
		return true;
		
		
	}
	
	function down() {
	
		return false;
	}

}

?>