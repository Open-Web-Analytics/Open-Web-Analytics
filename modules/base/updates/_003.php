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
 * Test Update Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_003_update extends owa_update {


	function up() {
		
		$tt = owa_coreAPI::entityFactory('base.testtable');
		$ret = $tt->createTable();
		
		if ($ret == true):
			print 'table testtable2 created';
			return true;
		else:
			print 'table testtable2 creation failed';
			return false;
		endif;
		
		
	}
	
	function down() {
	
		return false;
	}

}

?>