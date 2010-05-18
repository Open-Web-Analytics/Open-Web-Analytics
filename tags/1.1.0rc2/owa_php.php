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
require_once(OWA_BASE_CLASSES_DIR.'owa_caller.php');

/**
 * Generic Caller Class
 * 
 * Implements a PHP API for logging requests to OWA
 * Typical usage is:
 * 
 * 	$app_params['page_title'] = "This is the title of this page view"
 * 	$l = new owa_php;
 * 	$l->log($app_params);
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_php extends owa_caller {
	
	function owa_php($config = null) {
		
		return $this->owa_caller($config);
		
	}

}

?>