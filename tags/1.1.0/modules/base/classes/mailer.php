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

require_once(OWA_PHPMAILER_DIR.'class.phpmailer.php');

/**
 * phpmailer wrapper class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_mailer extends PHPMailer {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Error Logger
	 *
	 * @var Object
	 */
	var $e;
	
	/**
	 * Constructor
	 *
	 * @return owa_mailer
	 */
	function owa_mailer() {
		
		$c = &owa_coreAPI::configSingleton();
		$this->config = $c->fetch('base');
		
		$this->e = &owa_coreApi::errorSingleton();
		
		if (!empty($this->config['mailer-from'])):
			$this->From = $this->config['mailer-from'];
		endif;
		
		if (!empty($this->config['mailer-fromName'])):
			$this->FromName = $this->config['mailer-fromName'];
		endif;
		
		if (!empty($this->config['mailer-host'])):
			$this->Host = $this->config['mailer-host'];
		endif;
		
		if (!empty($this->config['mailer-port'])):
			$this->Port = $this->config['mailer-port'];
		endif;
		
		if (!empty($this->config['mailer-smtpAuth'])):
			$this->SMTPAuth = $this->config['mailer-smtpAuth'];
		endif;
		
		if (!empty($this->config['mailer-username'])):
			$this->Username = $this->config['mailer-username'];
		endif;
		
		if (!empty($this->config['mailer-password'])):
			$this->Password = $this->config['mailer-password'];
		endif;
		
		return;
		
	}
	
	function sendMail() {
	
		if(!$this->Send()):
			
			return $this->e->debug(sprintf("Mailer Failure. Was not able to send to %s with subject of '%s'. Error Msgs: '%s'", $this->to, $this->Subject, $this->ErrorInfo));
			
		else:
			return $this->e->debug(sprintf("Mail sent to %s with the subject of '%s'.", $this->to, $this->Subject));
		endif;
		
		
	}
}

?>