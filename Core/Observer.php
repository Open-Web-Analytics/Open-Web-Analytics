<?php
namespace OWA\Core;

/**
 * Base class for event handlers.
 */

class Observer extends \OWA\Core\Base {

    /**
     * The event being handled
     *
     * @var \OWA\Module\Base\Classes\Event|null null until a handler's notify() sets it
     */
    public $m;

    /**
     * Run an action with this event as its `event` parameter.
     *
     * @param string $action
     * @return mixed
     */
    public function handleEvent( $action ) {

        \OWA\Core\CoreAPI::performAction( $action, [ 'event' => $this->m ] );

        return \OWA\Core\CoreAPI::debug( sprintf( 'Handled Event. Action: %s', $action ) );
    }

    /**
     * Send a plain-text message through the configured mailer: its transport,
     * From address and error logging.
     *
     * @param string $email_address
     * @param string $subject
     * @param string $msg
     * @return bool whether the transport accepted it
     */
    public function sendMail( $email_address, $subject, $msg ) {

        $mailer = $this->mailer();
        $mailer->setSubject( (string) $subject );
        $mailer->setHtmlBody( nl2br( htmlspecialchars( (string) $msg, ENT_QUOTES, 'UTF-8' ) ) );
        $mailer->setAltBody( (string) $msg );

        if ( ! $mailer->addAddress( (string) $email_address ) ) {

            return false;
        }

        return $mailer->sendMail();
    }

    /**
     * The mailer sendMail() uses.
     *
     * @return \OWA\Module\Base\Classes\Mailer
     */
    protected function mailer() {

        return new \OWA\Module\Base\Classes\Mailer();
    }
}
