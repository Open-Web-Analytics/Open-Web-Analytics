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
 * Visitor Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_visitor extends owa_entity {
	
	function __construct() {
	
		$this->setTableName('visitor');
		$this->setCachable();
		
		// properties
		$this->properties['id'] = new owa_dbColumn;
		$this->properties['id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['id']->setPrimaryKey();
		
		//drop
		$this->properties['user_name'] = new owa_dbColumn;
		$this->properties['user_name']->setDataType(OWA_DTD_VARCHAR255);
		
		//drop
		$this->properties['user_email'] = new owa_dbColumn;
		$this->properties['user_email']->setDataType(OWA_DTD_VARCHAR255);
		
		$this->properties['first_session_id'] = new owa_dbColumn;
		$this->properties['first_session_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['first_session_year'] = new owa_dbColumn;
		$this->properties['first_session_year']->setDataType(OWA_DTD_INT);
		$this->properties['first_session_month'] = new owa_dbColumn;
		$this->properties['first_session_month']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['first_session_day'] = new owa_dbColumn;
		$this->properties['first_session_day']->setDataType(OWA_DTD_INT);
		$this->properties['first_session_dayofyear'] = new owa_dbColumn;
		$this->properties['first_session_dayofyear']->setDataType(OWA_DTD_INT);
		$this->properties['first_session_timestamp'] = new owa_dbColumn;
		$this->properties['first_session_timestamp']->setDataType(OWA_DTD_BIGINT);
		$this->properties['first_session_yyyymmdd'] = new owa_dbColumn;
		$this->properties['first_session_yyyymmdd']->setDataType(OWA_DTD_BIGINT);
		$this->properties['last_session_id'] = new owa_dbColumn;
		$this->properties['last_session_id']->setDataType(OWA_DTD_BIGINT);
		$this->properties['last_session_year'] = new owa_dbColumn;
		$this->properties['last_session_year']->setDataType(OWA_DTD_INT);
		$this->properties['last_session_month'] = new owa_dbColumn;
		$this->properties['last_session_month']->setDataType(OWA_DTD_VARCHAR255);
		$this->properties['last_session_day'] = new owa_dbColumn;
		$this->properties['last_session_day']->setDataType(OWA_DTD_INT);
		$this->properties['last_session_dayofyear'] = new owa_dbColumn;
		$this->properties['last_session_dayofyear']->setDataType(OWA_DTD_INT);
		
		//drop
		$num_prior_sessions =  new owa_dbColumn;
		$num_prior_sessions->setName('num_prior_sessions');
		$num_prior_sessions->setDataType(OWA_DTD_INT);
		$this->setProperty($num_prior_sessions);
	}
	
	function getVisitorName() {
		
		if ($this->get('user_name')) {
			return $this->get('user_name');
		} elseif ($this->get('user_email')) {
			return $this->get('user_email');		
		} else {
			return $this->get('id');
		}
	}
	
	function getAvatarId() {
		
		$eq = owa_coreAPI::getEventDispatch();
		
		return $eq->filter( 'visitor_avatar_id', $this->get( 'user_email' ) ); 
	}
}



?>