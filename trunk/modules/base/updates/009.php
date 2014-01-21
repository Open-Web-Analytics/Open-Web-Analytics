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
		
		
		if ($c) {
		
			$n0 = "
/**
 * AUTHENTICATION KEYS AND SALTS
 * 
 * Change these to different unique phrases.
 */" . PHP_EOL.PHP_EOL;
			$n1 = "define('OWA_NONCE_KEY', '" . owa_coreAPI::secureRandomString(40) . "');" . PHP_EOL;
			$n2 = "define('OWA_NONCE_SALT', '" . owa_coreAPI::secureRandomString(40) . "');" . PHP_EOL . PHP_EOL;
			$n3 = "?>";
			
			$value = $n0. $n1 . $n2 . $n3;
			//fseek($handle, -1, SEEK_END);
			//$ret = fwrite($handle, $value);
			//fclose($handle);				
			$c = str_replace('?>', $value, $c);
			print $c;
			$ret = file_put_contents(OWA_DIR . 'owa-config.php', $c);	
			if ($ret === false ) {
				$this->e->notice('config file not updated.');
				return false;
			}
			$this->e->notice('config file updated.');
			return true;
			
		} else {
			$this->e->notice('config file could not be read.');
			return false;
		}
		
	}
	
	function down() {
	
		return true;
	}
}

?>