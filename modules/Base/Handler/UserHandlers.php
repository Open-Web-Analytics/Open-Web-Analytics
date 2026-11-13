<?php
namespace OWA\Module\Base\Handler;


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
 * OWA user management Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class UserHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {

        $this->m = $event;

        $actions = array(
            'base.reset_password'   => 'base.usersResetPassword',
            'base.set_password'     => 'base.usersSetPassword',
            'base.new_user_account' => 'base.usersNewAccount',
        );

        $action = $actions[ $event->getEventType() ] ?? null;

        if ( $action ) {

            /*
             * Each action renders a mail view, whose result is the mailer's
             * (Mailer::sendMail()): true sent, false not sent, and anything
             * else when the action sent nothing. mail_sent carries it back to
             * whoever raised the event.
             */
            $sent = \OWA\Core\CoreAPI::performAction( $action, array( 'event' => $event ) );

            $event->set( 'mail_sent', is_bool( $sent ) ? $sent : null );
        }

        return OWA_EHS_EVENT_HANDLED;
    }
    
}

?>