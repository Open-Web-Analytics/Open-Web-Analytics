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

include_once('set_env.php');
require_once(OWA_BASE_DIR.'/owa_php.php');

/**
 * Javascript web bug request handler
 * 
 * This is usually invoked using:
 *
 * <SCRIPT language="JavaScript">
 * 	var owa_site_id = 1;
 * </SCRIPT>
 * <SCRIPT TYPE="text/javascript" SRC="http://site.domain.com/public/wb.php"></SCRIPT>
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

$owa = new owa_php;

header('Content-type: text/javascript');
header('P3P: CP="'.$owa->config['p3p_policy'].'"');
header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// This is the handler for javascript request for the logging web bug. 
$owa->placeAllBugs();	

?>
