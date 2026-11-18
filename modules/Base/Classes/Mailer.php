<?php
namespace OWA\Module\Base\Classes;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * phpmailer wrapper class
 */

class Mailer {

    /**
     * Seconds PHPMailer waits on the SMTP server, per connect and per reply.
     * Its own default is 300, and a new-visit announcement is sent while the
     * beacon that raised it is being ingested.
     */
    const TIMEOUT = 10;

    /** @var PHPMailer */
    public $mailer;

    /**
     * Constructor
     *
     * @return \OWA\Module\Base\Classes\Mailer
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function __construct() {
        
        $this->mailer = new PHPMailer( true );
        $this->mailer->Timeout = self::TIMEOUT;

        $from = \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-from' );

        if ( $from ) {

            // Guard the configured From. PHPMailer runs in exception mode (the
            // `true` above), so setFrom() with an address it rejects THROWS --
            // and the shipped default is 'owa@' . SERVER_NAME, which on a
            // localhost (or any dotless-hostname) install is 'owa@localhost',
            // an address PHPMailer considers invalid (no dot in the domain).
            // An operator who turns on new-session announcements on such a box
            // would otherwise fatal on every tracked hit.
            //
            // We must REPAIR, not skip: skipping setFrom leaves From empty, and
            // an empty From builds a malformed 'From:' header that the local MTA
            // silently drops -- the mail would appear "sent" in the log but never
            // arrive. The invalid value is never operator-typed anyway (it's the
            // auto-computed default), so when the domain part simply lacks a dot
            // we append '.localdomain' (the classic MTA convention) to make it a
            // valid, deliverable address. Whole thing is wrapped in try/catch as
            // a backstop -- a mail misconfig must never take down tracking.
            try {

                if ( ! PHPMailer::validateAddress( $from ) ) {

                    $repaired = $this->repairFromAddress( $from );

                    if ( $repaired !== $from && PHPMailer::validateAddress( $repaired ) ) {

                        \OWA\Core\CoreAPI::debug( sprintf( "mailer-from '%s' is not a valid address; using '%s' instead. Set base.mailer-from to a valid address to silence this.", $from, $repaired ) );
                        $from = $repaired;
                    }
                }

                if ( PHPMailer::validateAddress( $from ) ) {

                    $this->mailer->setFrom( $from, \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-fromName' ) );

                } else {

                    \OWA\Core\CoreAPI::debug( sprintf( "mailer-from '%s' is not a valid address and could not be repaired; sending without an explicit From (mail may not be delivered). Set base.mailer-from to a valid address.", $from ) );
                }

            } catch ( Exception $e ) {

                \OWA\Core\CoreAPI::debug( sprintf( "Could not set mailer From address '%s': %s", $from, $e->getMessage() ) );
            }
        }

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-use-smtp' ) ) {
            
            if ( \OWA\Core\Lib::inDebug() ) {
                
                $this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
            }
            
            $this->mailer->IsSMTP(); // telling the class to use SMTP
            
            if ( \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-host' ) ) {
        
                $this->mailer->Host = \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-host' );
            }
            
            if ( \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-port' ) ) {
            
                $this->mailer->Port =  \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-port' );
            }
            
            if ( \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-smtpAuth' ) ) {
                
                $this->mailer->SMTPAuth = \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-smtpAuth' );
            }
            
            if ( \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-username') && \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-password') ) {
                
                $this->mailer->Username = \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-username');
                $this->mailer->Password = \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-password');
            }   
            
            // set mailer SMTP options if they exist
            if ( \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-options' ) ) {                
            
                $this->mailer->SMTPOptions = \OWA\Core\CoreAPI::getSetting( 'base', 'mailer-options' );
            }     
        }
    }
    
    /**
     * Best-effort repair of an auto-generated From address that PHPMailer
     * rejects. The only case we can safely fix is a domain with no dot (e.g.
     * 'owa@localhost'), which the shipped default produces on a localhost /
     * bare-hostname install: append '.localdomain' so it's a valid, deliverable
     * address. Anything else is returned unchanged for the caller to handle.
     *
     * @param  string $address
     * @return string
     */
    public function repairFromAddress( $address ) {

        $at = strrpos( $address, '@' );

        if ( $at === false ) {

            return $address;
        }

        $domain = substr( $address, $at + 1 );

        // Dotless, non-empty domain -> append a TLD-ish suffix to satisfy the
        // "must contain a dot" rule without changing the operator's intent.
        if ( $domain !== '' && strpos( $domain, '.' ) === false ) {

            return $address . '.localdomain';
        }

        return $address;
    }

    /**
     * Send the message. A failure -- the transport, no valid recipient -- is
     * logged as an error and returned, never thrown: mail is sent from
     * requests that must finish whatever happens to it.
     *
     * @return bool whether the transport accepted it
     */
    public function sendMail() {

        $error = null;

        try {

            if ( $this->mailer->send() ) {

                \OWA\Core\CoreAPI::notice( sprintf( "Mail sent with the subject '%s'.", $this->mailer->Subject ) );

                return true;
            }

            $error = $this->mailer->ErrorInfo;

        } catch ( Exception $e ) {

            $error = $e->getMessage();
        }

        \OWA\Core\CoreAPI::error( sprintf( "Mail with the subject '%s' was not sent: %s", $this->mailer->Subject, $error ) );

        return false;
    }

    /**
     * sendMail(), by its older name.
     *
     * @return bool
     */
    public function send() {

        return $this->sendMail();
    }

    /**
     * Add a recipient. An address PHPMailer refuses is logged and skipped.
     *
     * @param  string $address
     * @param  string $name
     * @return bool whether it was added
     */
    public function addAddress( $address, $name = '' ) {

        try {

            if ( $this->mailer->addAddress( (string) $address, (string) $name ) ) {

                return true;
            }

            $error = $this->mailer->ErrorInfo;

        } catch ( Exception $e ) {

            $error = $e->getMessage();
        }

        \OWA\Core\CoreAPI::error( sprintf( "Mail recipient '%s' skipped: %s", $address, $error ) );

        return false;
    }
    
    /**
     * @param  string $address
     * @param  string $name
     * @return void
     */
    public function setFrom( $address, $name ) {
        
        $this->mailer->setFrom( $address, $name );
    }
    
    /**
     * @param  string $html
     * @return void
     */
    public function setHtmlBody ( $html ) {
        
        $this->mailer->msgHTML( $html );
    }
    
    /**
     * @param  string $text
     * @return void
     */
    public function setAltBody ( $text ) {
        
        $this->mailer->AltBody =  $text;
    }
    
    /**
     * @param  string $subject
     * @return void
     */
    public function setSubject( $subject ) {
    
        $this->mailer->Subject = $subject;
    }
    
    /**
     * @param  string $address
     * @param  string $name
     * @return void
     */
    public function addReplyTo( $address, $name ) {
    
        $this->mailer->addReplyTo( $address, $name );
    }
    
    /**
     * @param  string $attachment  a file's path
     * @return void
     */
    public function addAttachment( $attachment ) {
    
        $this->mailer->addAttachment( $attachment );
    }
    
}
