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

class owa_mailer {
        
    var $mailer;

    /**
     * Constructor
     *
     * @return owa_mailer
     * @throws \PHPMailer\PHPMailer\Exception
     */
    function __construct() {
        
        $this->mailer = new PHPMailer( true );

        $from = owa_coreAPI::getSetting( 'base', 'mailer-from' );

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

                        owa_coreAPI::debug( sprintf( "mailer-from '%s' is not a valid address; using '%s' instead. Set base.mailer-from to a valid address to silence this.", $from, $repaired ) );
                        $from = $repaired;
                    }
                }

                if ( PHPMailer::validateAddress( $from ) ) {

                    $this->mailer->setFrom( $from, owa_coreAPI::getSetting( 'base', 'mailer-fromName' ) );

                } else {

                    owa_coreAPI::debug( sprintf( "mailer-from '%s' is not a valid address and could not be repaired; sending without an explicit From (mail may not be delivered). Set base.mailer-from to a valid address.", $from ) );
                }

            } catch ( Exception $e ) {

                owa_coreAPI::debug( sprintf( "Could not set mailer From address '%s': %s", $from, $e->getMessage() ) );
            }
        }

        if ( owa_coreAPI::getSetting( 'base', 'mailer-use-smtp' ) ) {
        	
        	if ( owa_lib::inDebug() ) {
	        	
	        	$this->mailer->SMTPDebug = SMTP::DEBUG_SERVER;
        	}
        	
            $this->mailer->IsSMTP(); // telling the class to use SMTP
            
            if ( owa_coreAPI::getSetting( 'base', 'mailer-host' ) ) {
        
                $this->mailer->Host = owa_coreAPI::getSetting( 'base', 'mailer-host' );
            }
            
            if ( owa_coreAPI::getSetting( 'base', 'mailer-port' ) ) {
            
                $this->mailer->Port =  owa_coreAPI::getSetting( 'base', 'mailer-port' );
            }
            
            if ( owa_coreAPI::getSetting( 'base', 'mailer-smtpAuth' ) ) {
                
                $this->mailer->SMTPAuth = owa_coreAPI::getSetting( 'base', 'mailer-smtpAuth' );
            }
            
            if ( owa_coreAPI::getSetting( 'base', 'mailer-username') && owa_coreAPI::getSetting( 'base', 'mailer-password') ) {
                
                $this->mailer->Username = owa_coreAPI::getSetting( 'base', 'mailer-username');
                $this->mailer->Password = owa_coreAPI::getSetting( 'base', 'mailer-password');
            }   
            
            // set mailer SMTP options if they exist
            if ( owa_coreAPI::getSetting( 'base', 'mailer-options' ) ) {                
            
                $this->mailer->SMTPOptions = owa_coreAPI::getSetting( 'base', 'mailer-options' );
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

    function sendMail() {

        // send() throws in exception mode (see constructor). Catch it so a
        // transport failure or a bad recipient logs and returns like any other
        // mailer failure instead of fataling the request that triggered the mail.
        try {

            if( ! $this->mailer->send() ) {
                return owa_coreAPI::debug(sprintf("Mailer Failure. Was not able to send with subject of '%s'. Error Msgs: '%s'", $this->mailer->Subject, $this->mailer->ErrorInfo));

            } else {
                return owa_coreAPI::debug( sprintf ("Mail sent with the subject of '%s'.", $this->mailer->Subject ) );
            }

        } catch ( Exception $e ) {

            return owa_coreAPI::debug( sprintf( "Mailer Failure. Was not able to send with subject of '%s'. Error Msgs: '%s'", $this->mailer->Subject, $e->getMessage() ) );
        }
    }
    
    function send() {
        
        return $this->sendMail();
    }
    
    function addAddress( $address, $name ) {
        
        $this->mailer->addAddress( $address, $name );
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