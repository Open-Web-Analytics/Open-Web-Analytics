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
 * INI Database 
 * 
 * Searches INI files for matches based on various lookup methods.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    wa
 * @package     wa
 * @version		$Revision$	      
 * @since		wa 1.0.0
 */
class ini_db extends owa_base {

	/**
	 * Data file
	 *
	 * @var unknown_type
	 */
	var $ini_file;
	
	/**
	 * Result Format
	 *
	 * @var string
	 */
	var $return_format;
	
	/**
	 * Cache flag
	 *
	 * @var boolean
	 */
	var $cache = true;
	
	
	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;

	/**
	 * Constructor
	 *
	 * @param string $ini_file
	 * @param string_type $sections
	 * @param string $return_format
	 * @access public
	 * @return ini_db
	 */
	function __construct($ini_file, $sections = null, $return_format = 'object') {
		
		parent::__construct();
		$this->ini_file = $ini_file;		
		$this->return_format = $return_format;
		
		if (!empty($sections)){
			$this->db = $this->readINIfile($this->ini_file, ';');	
		} else {
			$this->db = file($this->ini_file);	
		}
	}

	/**
	 * Returns a section from an ini file based on regex match rule 
	 * contained as keys in an ini file.
	 * 
	 * @param string
	 * @access public
	 */
	function fetch($haystack) {
	  	
		$record = null;
		
		foreach ($this->db as $key=>$value) {
			if (($key!='#*#')&&(!array_key_exists('parent',$value))) continue;
				
				$keyEreg = '#'.$key.'#';
				
  			if (preg_match($keyEreg, $haystack)) {
			   $record=array('regex'=>strtolower($keyEreg),'pattern'=>$key)+$value;
		
			   $maxDeep=8;
			   while (array_key_exists('parent',$value)&&(--$maxDeep>0))
			   
				$record+=($value = $this->db[strtolower($value['parent'])]);
			   break;
			}
 		}
		
		switch ($this->return_format) {
			case "array":
				return $record;
				break;
			case "object":
				return ((object)$record);
				break;
		}
		return $record;
	}
	
	/**
	 * Returns part of the passed string based on regex match rules 
	 * contained as keys in an ini file.
	 * 
	 * @param string
	 * @access public
	 * @return string
	 */
	function match($haystack) {
		
		$needle = '';
		
		if (!empty($haystack)):
		
			$tmp = '';
			
			foreach ($this->db as $key => $value) {
				
				if (!empty($value)):
		        	//$this->e->debug('ref db:'.print_r($this->db, true));
					preg_match(trim($value), $haystack, $tmp);
					if (!empty($tmp)):
		            	$needle = $tmp;
		            	//$this->e->debug('ref db:'.print_r($tmp, true));
					endif;
				endif;	   
			}
			
			return $needle;
		
		else:
			return;
		endif;
	}
	
	function contains($haystack = '') {
		
		$pos = false;
		
		if ($haystack) {
		
			foreach ($this->db as $k => $needle) {
				$needle = substr(strtolower(trim($needle)),1,-1);
				$pos = strpos(strtolower($haystack), $needle);
				
				if ($pos) {
					owa_coreAPI::debug(sprintf('Haystack contains "%s" at position %d', $needle, $pos));
					return true;
				}
			}
			
			return false;	
		}
	}
	
	/**
	 * Fetch a record set and perfrom a regex replace on the name
	 *
	 * @param 	string $haystack
	 * @return 	string
	 */
	function fetch_replace($haystack) {
		
		$record = $this->fetch($haystack);
		
		//print_r($record);
 		
 		$new_record = preg_replace($record->regex, $record->name, $haystack);
		
		return $new_record;
	}
	
	/**
	 * Reads INI file
	 *
	 * @param string $filename
	 * @param string $commentchar
	 * @return array
	 */
	function readINIfile ($filename, $commentchar) {
		$array1 = file($filename);
		$section = '';
		foreach ($array1 as $filedata) {
		$dataline = trim($filedata);
		$firstchar = substr($dataline, 0, 1);
		if ($firstchar!=$commentchar && $dataline!='') {
		//It's an entry (not a comment and not a blank line)
			if ($firstchar == '[' && substr($dataline, -1, 1) == ']') {
		    	//It's a section
		   		$section = strtolower(substr($dataline, 1, -1));
		 	} else {
		   		//It's a key...
		   		$delimiter = strpos($dataline, '=');
		   		if ($delimiter > 0) {
					//...with a value
					$key = strtolower(trim(substr($dataline, 0, $delimiter)));
					$value = trim(substr($dataline, $delimiter + 1));
				 	if (substr($value, 1, 1) == '"' && substr($value, -1, 1) == '"') { $value = substr($value, 1, -1); }
				 		$array2[$section][$key] = stripcslashes($value);
			   		} else {
				 		//...without a value
				 		$array2[$section][strtolower(trim($dataline))]='';
			   		}
			 	}
			} else {
			 //It's a comment or blank line.  Ignore.
			}
		}
		
		return $array2;
	}
}

?>