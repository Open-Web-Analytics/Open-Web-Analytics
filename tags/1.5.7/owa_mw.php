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
require_once('owa_env.php');
require_once(OWA_BASE_CLASS_DIR.'client.php');

/**
 * MediaWiki Caller Class
 * 
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_mw extends owa_client {

	function __construct($config = null) {
		
		return parent::__construct($config);
	}
	
	function owa_mw($config = null) {
		
		return owa_mw::__construct($config);
	}
	
	/**
	 * OWA Singleton Method
	 *
	 * Makes a singleton instance of OWA using the config array
	 */
	function singleton($config = null) {
		
		static $owa;
		
		if(!empty($owa)) {
			return $owa;
		} else {
			$owa = new owa_mw($config);
			return $owa;	
		}
	}


}

?>