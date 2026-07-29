<?php
namespace OWA\Module\Base\View;

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
 * Goals Roster View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class OptionsGoalEntry extends \owa_view {

    function render() {

        $this->body->set_template( 'options_goal_entry.php' );
        $this->body->set( 'headline', 'Edit Goal');
        $this->body->set( 'goal', $this->get( 'goal' ) );
        $this->body->set( 'goal_groups', $this->get( 'goal_groups' ) );
        $this->body->set( 'goal_number', $this->get( 'goal_number' ) );
        $this->body->set( 'siteId', $this->get( 'siteId' ) );
    }
}
