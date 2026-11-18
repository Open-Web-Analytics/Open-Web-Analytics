<?php
namespace OWA\Module\Base\Handler;

/**
 * OWA user management Event handlers
 */

class UserHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     * @return int an OWA_EHS_ handler status
     */
    public function notify($event) {

        $this->m = $event;

        $actions = [
            'base.reset_password'   => 'base.usersResetPassword',
            'base.set_password'     => 'base.usersSetPassword',
            'base.new_user_account' => 'base.usersNewAccount',
        ];

        $action = $actions[ $event->getEventType() ] ?? null;

        if ( $action ) {

            /*
             * Each action renders a mail view, whose result is the mailer's
             * (Mailer::sendMail()): true sent, false not sent, and anything
             * else when the action sent nothing. mail_sent carries it back to
             * whoever raised the event.
             */
            $sent = \OWA\Core\CoreAPI::performAction( $action, [ 'event' => $event ] );

            $event->set( 'mail_sent', is_bool( $sent ) ? $sent : null );
        }

        return OWA_EHS_EVENT_HANDLED;
    }
    
}
