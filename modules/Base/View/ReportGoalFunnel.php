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
 * Goal Funnel Report View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class ReportGoalFunnel extends \OWA\Core\View {

    function render() {

        $this->body->set_template('report_goal_funnel.php');
        $this->body->set('funnel', $this->get('funnel'));
        $this->body->set('funnel_json', json_encode($this->get('funnel')));
        $this->body->set('goal_conversion_rate', $this->get('goal_conversion_rate'));
        $this->body->set('numGoals', \OWA\Core\CoreAPI::getSetting('base', 'numGoals') );
        $this->body->set('goal_number',  $this->get('goal_number') );

        /*
         * The scope toggle and any segment complaint.
         *
         * Listed here because this view hands the body each variable by name --
         * a template reading one this method does not copy sees nothing at all,
         * which is silent.
         */
        $this->body->set('funnel_scope',         $this->get('funnel_scope') );
        $this->body->set('funnel_scope_other',   $this->get('funnel_scope_other') );
        $this->body->set('funnel_scope_label',   $this->get('funnel_scope_label') );
        $this->body->set('funnel_segment_error', $this->get('funnel_segment_error') );
    }
}
