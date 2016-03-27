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

require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Overlay Launcher Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_overlayLauncherController extends owa_controller {
	
	function action() {
		
		// setup overlay cookiestate
		//owa_coreAPI::setState('overlay', '', urldecode($this->getParam('overlay_params')), 'cookie');
		
		
				
		// load entity for document id to get URL
		$d = owa_coreAPI::entityFactory('base.document');
		$d->load($this->getParam('document_id'));
		
		$url = trim( $d->get( 'url' ) );
		
		if ( strpos( $url, '#' ) ) {
			$parts = explode( '#', $url );
			$url = $parts[0];
		}
		
		$url = $url.'#owa_overlay.' . trim( $this->getParam( 'overlay_params' ), '\u0000' );
	//$url = $url.'#owa_overlay.' . trim( urlencode( $this->getParam( 'overlay_params' ) ) );
		// redirect browser
		$this->redirectBrowserToUrl($url);	
	}
}

?>