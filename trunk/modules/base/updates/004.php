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
 * 004 Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.2.1
 */


class owa_base_004_update extends owa_update {

	function up() {
		
		$ds = owa_coreAPI::entityFactory('base.domstream');
		$ret = $ds->createTable();
		
		if ($ret == true) {
			$this->e->notice('Domstream entity table created');
			return true;
		} else {
			$this->e->notice('Domstream entity table creation failed');
			return false;
		}
		
	}
	
	function down() {
	
		return false;
	}

}

?>