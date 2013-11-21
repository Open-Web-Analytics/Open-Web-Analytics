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

require_once(OWA_BASE_CLASS_DIR.'cache.php');

/**
 * File Based Cache Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 - 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_fileCache extends owa_cache {

	var $cache_dir;
	var $lock_file_name = 'cache.lock';
	var $cache_file_header = '<?php\n/*';
	var $cache_file_footer = '*/\n?>';
	var $file_perms = 0750;
	var $dir_perms = 0750;
	var $mutex;

	/**
	 * Constructor
	 * 
	 * Takes cache directory as param
	 *
	 * @param $cache_dir string
	 */
	function __construct($cache_dir = '') {
		
		if ($cache_dir) {
			$this->cache_dir = $cache_dir;
		} else {
			$this->cache_dir = OWA_CACHE_DIR;
		}
		
		return parent::__construct();
	}
	
	function getItemFromCacheStore($collection, $id) {
		
		$cache_file = $this->makeCollectionDirPath($collection).$id.'.php'; 
		$this->debug("check cache file: ".$cache_file);

		// if no cache file then return false
		if (!file_exists($cache_file)) {
			$this->debug(sprintf('Cache File not found for Collection: %s, id: %s, file: %s', $collection, $id, $cache_file));
			return false;
		
		// cache object has expired
		} elseif ((filectime($cache_file) + $this->getCollectionExpirationPeriod($collection)) < time()) {
			$this->debug("time: ".time());
			$this->debug("ctime: ".filectime($cache_file));
			$this->debug("diff: ".(time() - filectime($cache_file)));
			$this->debug("exp period: ".$this->getCollectionExpirationPeriod($collection));
			$this->removeCacheFile($this->makeCollectionDirPath($collection).$id.'.php');
			$this->debug(sprintf('Cache Object has expired for Collection: %s, id: %s', $collection, $id));
			return false;
			
		// load from cache file	
		} else {
			return unserialize(base64_decode(substr(@ file_get_contents($cache_file), strlen($this->cache_file_header), -strlen($this->cache_file_footer))));
		}
	
	}
	
	function putItemToCacheStore($collection, $id) {
		
		if ( $this->acquire_lock() ) {
			$this->makeCacheCollectionDir($collection);
			$this->debug(' writing file for: '.$collection.$id);
			// create collection dir
			$collection_dir = $this->makeCollectionDirPath($collection);
			// asemble cache file name
			$cache_file = $collection_dir.$id.'.php';			
			
			$this->removeCacheFile($cache_file);
									
			$temp_cache_file = tempnam($collection_dir, 'tmp_'.$id);
			
			$data = $this->cache_file_header.base64_encode(serialize($this->cache[$collection][$id])).$this->cache_file_footer;
			
			
			// open the temp cache file for writing
			$tcf_handle = @fopen($temp_cache_file, 'w');
			
			if ( false === $tcf_handle ) {
				$this->debug('could not acquire temp file handler');
			} else {
				
				fputs($tcf_handle, $data);
				
				fclose($tcf_handle);
				
				if (!@ rename($temp_cache_file, $cache_file)) {
					
					if (!@ copy($temp_cache_file, $cache_file)) {
						$this->debug('could not rename or copy temp file to cache file');
					} else {
						@ unlink($temp_cache_file);
						$this->debug('removing temp cache file');
					}	
				}
				
				@ chmod($cache_file, $this->file_perms);
				$this->debug('changing file permissions on cache file');
			}
			
			$this->release_lock();
		} else {
			$this->debug("could not persist item to cache due to failure acquiring lock.");
		}
	}
		
	function removeItemFromCacheStore($collection, $id) {
		
		return $this->removeCacheFile($this->makeCollectionDirPath($collection).$id.'.php');
	}
	
	function makeCollectionDirPath($collection) {
	
		if (!in_array($collection, $this->global_collections)) {
			return $this->cache_dir.$this->cache_id.'/'.$collection.'/';
		} else {
			return $this->cache_dir.$collection.'/';	
		}
	}
	
	function makeCacheCollectionDir($collection) {
		
		// check to see if the caches directory is writable, return if not.
		if (!is_writable($this->cache_dir)) {
			return;
		}
		
		// localize the cache directory based on some id passed from caller
		
		if (!file_exists($this->cache_dir.$this->cache_id)) {
			
			mkdir($this->cache_dir.$this->cache_id);                 
	        chmod($this->cache_dir.$this->cache_id, $this->dir_perms);
	    }
		
		$collection_dir = $this->makeCollectionDirPath($collection);
		
		if (!file_exists($collection_dir)) {
			
			mkdir($collection_dir);
	        chmod($collection_dir, $this->dir_perms);
	    }
	
	    if (!file_exists($collection_dir."index.php")) {
	    
	        touch($collection_dir."index.php");    
	        chmod($collection_dir."index.php", $this->file_perms);
	    }
	}
	
	function removeCacheFile($cache_file) {
	
		// Remove the cache file
		if (file_exists($cache_file)) {
			@ unlink($cache_file);
			$this->debug('Cache File Removed: '.$cache_file);
			$this->statistics['removed']++;
			return true;
		} else {
			$this->debug('Cache File does not exist: '.$cache_file);
			return false;
		}
	}
	
	function flush() {
	
		$tld = $this->readDir($this->cache_dir);
		
		
		if ( array_key_exists( 'files', $tld ) ) {	

			//$this->deleteFiles($tld['files']);
		}	
		
		foreach ($tld['dirs'] as $k => $dir) {
			
			$sld = $this->readDir($dir);
			
			if ( array_key_exists( 'files', $sld ) ) {
			
				$this->deleteFiles( $sld['files'] );
			}
			
			foreach ( $sld['dirs'] as $sk => $sdir ) {
			
				$ssld = $this->readDir( $sdir );
			
				if ( array_key_exists( 'files', $ssld ) ) {
					
					$this->deleteFiles( $ssld['files'] );
				}
			}
		}			
	}
			
	function setCacheDir($dir) {
		
		$this->cache_dir = $dir;
	}
	
	function acquire_lock() {
		// Acquire a write lock.
		$this->mutex = @fopen($this->cache_dir.$this->lock_file_name, 'w');
	    if (false == $this->mutex) {
	    	return false;
	    } else {
		    flock($this->mutex, LOCK_EX);
	        return true;
	    }
	}
	
	function release_lock() {
        // Release write lock.
        flock($this->mutex, LOCK_UN);
	    fclose($this->mutex);
	}
	
	function readDir($dir) {
		
		$this->debug( "Reading cache file list from: ". $dir );
		
		$data = array();
		
		if ($handle = opendir($dir)) {
 			
 			while (($file = readdir($handle)) !== false) {
				
				if (is_dir($dir.$file)) {
				
					if (strpos($file, '.') === false) {
						$data['dirs'][] = $dir.$file.'/';
					} 
				} else {
					if (strpos($file, '.php') == true) { 
						$data['files'][] = $dir.$file; 
					}
					
					if (strpos($file, '.lock') == true) {
						$data['files'][] = $dir.$file; 
					}
				}			
			}
	
		}
		
 		closedir($handle);
		return $data;
	}
	
	function deleteFiles($files) {
		
		if (!empty($files)) {
		
			foreach ($files as $file) {
				$this->debug("About to unlink cache file: ".$file);
				unlink($file);
			}
			
		} else {
			owa_coreAPI::debug('No Cache Files to delete.');
		}
		
		return true;
	}

}

?>