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
 * Site Manager Class
 * 
 * handels the common tasks associated with creating and manipulating tracked sites
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.1
 */

class owa_siteManager extends owa_base {
	
	function __construct() {
		
		return parent::__construct();
	}
		
	function createNewSite( $domain, $name = '', $description = '', $site_family = '' ) {
	
		$site_id = md5( $domain );
		$site = owa_coreAPI::entityFactory( 'base.site' );
		$id = $site->generateId( $site_id );
		$site->load( $id );
		
		if ( ! $name ) {
			$name = $domain;
		}
		
		if ( ! $site->wasPersisted() ) {
	
			$site->set('id', $id );
			$site->set('site_id', $site_id );
			$site->set('name', $name );
			$site->set('domain', $domain );
			$site->set('description', $description);
			$site->set('site_family', $site_family);
			$ret = $site->create();
			
			if ($ret) {
				return $site_id;
			}
			
		} else {
			
			owa_coreAPI::debug("Cannot create new site. Site with id: $site_id already exists.");
		}
	}
}

?>