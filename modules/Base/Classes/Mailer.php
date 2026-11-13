<?php
namespace OWA\Module\Base\Classes;


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

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * phpmailer wrapper class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Mailer {

    /**
     * Seconds PHPMailer waits on the SMTP server, per connect and per reply.
     * Its own default is 300, and a new-visit announcement is sent while the
     * beacon that raised it is being ingested.
     */
    const TIMEOUT = 10;

    var $mailer;

    /**
     * Constructor
     *
     * @return \OWA\Module\Base\Classes\Mailer
     * @throws \PHPMailer\PHPMailer\Exception
     */
    function __construct() {
        
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
    function repairFromAddress( $address ) {

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
    function sendMail() {

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

    function send() {

        return $this->sendMail();
    }

    /**
     * Add a recipient. An address PHPMailer refuses is logged and skipped.
     *
     * @return bool whether it was added
     */
    function addAddress( $address, $name = '' ) {

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
    
    function setFrom( $address, $name ) {
        
        $this->mailer->setFrom( $address, $name );
    }
    
    function setHtmlBody ( $html ) {
        
        $this->mailer->msgHTML( $html );
    }
    
    function setAltBody ( $text ) {
        
        $this->mailer->AltBody =  $text;
    }
    
    function setSubject( $subject ) {
    
        $this->mailer->Subject = $subject;
    }
    
    function addReplyTo( $address, $name ) {
    
        $this->mailer->addReplyTo( $address, $name );
    }
    
    function addAttachment( $attachment ) {
    
        $this->mailer->addAttachment( $attachment );
    }
    
}

?>