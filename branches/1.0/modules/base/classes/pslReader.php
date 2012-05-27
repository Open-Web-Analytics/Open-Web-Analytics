<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2012 Peter Adams. All rights reserved.
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
 * Calculates the effective registered domain of a fully qualified domain name
 * by reading from the Public Suffix List maintained by Mozilla at:
 * http://publicsuffix.org/list/
 * 
 * Based on orginal function provided by
 * Florian Sager, sager@agitos.de
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2012 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.5.3
 */
class owa_pslReader {

	var $tldTree = array();
	
	public function __construct() {
		
		// Load browscap file into memory
		$tld_file = OWA_DATA_DIR.'public_suffix_list/effectiveTLDs.inc.php';
		// check to see if a user downloaded version of the file exists
		if ( ! file_exists( $tld_file ) ) {
			$tld_file = OWA_BASE_MODULE_DIR.'data/effectiveTLDs.inc.php';
		}
			
		include_once( $tld_file );
		
		$this->tldTree = $tldTree;
		unset($tldTree);
	}
	
	/*
	 * Remove subdomains from a signing domain to get the registered domain.
	 *
	 * dkim-reputation.org blocks signing domains on the level of registered domains
	 * to rate senders who use e.g. a.spamdomain.tld, b.spamdomain.tld, ... under
	 * the most common identifier - the registered domain - finally.
	 *
	 * This function returns NULL if $signingDomain is TLD itself
	 */
	public function getRegisteredDomain( $signingDomain ) {
	
		$signingDomainParts = explode( '.', $signingDomain );
	
		$result = $this->findRegisteredDomain( $signingDomainParts, $this->tldTree );
	
		if ($result===NULL || $result=="") {
			// this is an invalid domain name
			return NULL;
		}
	
		// assure there is at least 1 TLD in the stripped signing domain
		if (!strpos($result, '.')) {
			$cnt = count($signingDomainParts);
			if ($cnt==1 || $signingDomainParts[$cnt-2]=="") return NULL;
			return $signingDomainParts[$cnt-2].'.'.$signingDomainParts[$cnt-1];
		}
		return $result;
	}
	
	// recursive helper method
	private function findRegisteredDomain($remainingSigningDomainParts, &$treeNode) {
	
		$sub = array_pop($remainingSigningDomainParts);
	
		$result = NULL;
		if (isset($treeNode['!'])) {
			return '#';
		} else if (is_array($treeNode) && array_key_exists($sub, $treeNode)) {
			$result = $this->findRegisteredDomain($remainingSigningDomainParts, $treeNode[$sub]);
		} else if (is_array($treeNode) && array_key_exists('*', $treeNode)) {
			$result = $this->findRegisteredDomain($remainingSigningDomainParts, $treeNode['*']);
		} else {
			return $sub;
		}
	
		// this is a hack 'cause PHP interpretes '' as NULL
		if ($result == '#') {
			return $sub;
		} else if (strlen($result)>0) {
			return $result.'.'.$sub;
		}
		return NULL;
	}
}
?>