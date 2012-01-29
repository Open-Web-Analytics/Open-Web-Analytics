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
 * Goal N Compeletions
 *
 * This metric produces a count of goal completions for a specific goal number
 * Goal number is passed into the object dynamicaly when the metric is created.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_goalNCompletions extends owa_metric {

	function __construct( $params ) {
		
		if ( array_key_exists( 'goal_number' , $params ) ) {
			$goal_number = $params['goal_number'];
		}
		
		$name = 'goal'.$goal_number.'Completions';
		$this->setName( $name );
		
		$siteId = owa_coreAPI::getRequestParam('siteId');
		
		if ( $siteId ) {
			$gm = owa_coreAPI::getGoalManager( $siteId );
			$goal = $gm->getGoal($goal_number);
			$this->setLabel( sprintf('G%d: %s', $goal_number,$goal['goal_name'] ) );
		} else {
			$this->setLabel( sprintf('Goal %d Completions', $goal_number ) );
		}
		
		$this->setEntity( 'base.session' );
		$column = 'goal_'.$goal_number;
		$this->setColumn( $column );
		$this->setSelect( sprintf( "SUM(%s)", $this->getColumn() ) );
		$this->setDataType( 'integer' );
		return parent::__construct();
	}
}

?>