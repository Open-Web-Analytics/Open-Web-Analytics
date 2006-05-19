<?

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

// You will need to change this if you move OWA's public
// htdocs dir.
include_once('../owa_env.php');

require_once(OWA_BASE_DIR.'/owa_php.php');


/**
 * Special HTTP Requests Controler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

$l = new owa_php;

switch ($_GET['owa_action']) {
	
	case $l->config['first_hit_param']:
		$l->first_request_handler();		
		break;
	case $l->config['graph_param']:
		$l->graph_request_handler();
		break;
}



?>