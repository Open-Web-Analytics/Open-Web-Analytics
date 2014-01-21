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

/**
 * 009 Schema Update Class
 * 
 * @author     Daniel Pötzinger <poetzinger@googlemail.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.5.0
 */


class owa_base_009_update extends owa_update {
	
	var $schema_version = 9;
	
	
	function up($force = false) {
		
		//$handle = fopen(OWA_DIR . 'owa-config.php', 'r+');
		
		$c = file_get_contents(OWA_DIR . 'owa-config.php');
		
		$ret = copy(OWA_DIR . 'owa-config.php', OWA_DIR . 'owa-config.php.backup.' . time() );
		
		if ($ret === false ) {
			$this->e->notice('A backup of your owa-config.php could not be created. Check permissions to ensure your main OWA directory is writable.');
			return false;
		}
		
		
		if ($c) {
		
			$n0 = "
/**
 * AUTHENTICATION KEYS AND SALTS
 * 
 * Change these to different unique phrases.
 */" . PHP_EOL.PHP_EOL;
			$n1 = "define('OWA_NONCE_KEY', '" . owa_coreAPI::secureRandomString(64) . "');" . PHP_EOL;
			$n2 = "define('OWA_NONCE_SALT', '" . owa_coreAPI::secureRandomString(64) . "');" . PHP_EOL;
			$n3 = "define('OWA_AUTH_KEY', '" . owa_coreAPI::secureRandomString(64) . "');" . PHP_EOL;
			$n4 = "define('OWA_AUTH_SALT', '" . owa_coreAPI::secureRandomString(64) . "');" . PHP_EOL . PHP_EOL;
			$ne = "?>";
			
			$value = $n0. $n1 . $n2 . $n3 . $n4 . $ne;
			//fseek($handle, -1, SEEK_END);
			//$ret = fwrite($handle, $value);
			//fclose($handle);				
			$c = str_replace('?>', $value, $c);
			
			$ret = file_put_contents(OWA_DIR . 'owa-config.php', $c);	
			if ($ret === false ) {
				$this->e->notice('owa-config.php could not be written to. Check permissions to ensure this file is writable.');
				return false;
			}
			$this->e->notice('Auth keys added to owa-config.php.');
			return true;
			
		} else {
			$this->e->notice('owa-config.php could not be read. check permissions to ensure this file is readable.');
			return false;
		}
		
	}
	
	function down() {
	
		return true;
	}
}

?>