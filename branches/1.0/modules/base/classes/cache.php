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
 * Cache Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_cache {

	var $cache_dir;
	var $cache;
	var $lock_file_name = 'cache.lock';
	var $statistics;
	var $cache_id = 1; // default cache id
	var $cache_file_header = '<?php\n/*';
	var $cache_file_footer = '*/\n?>';
	var $collections;
	var $dirty_collections;
	var $dirty_objs = array();
	var $file_perms = 0775;
	var $dir_perms = 0775;
	var $global_collections = array();
	var $non_persistant_collections = array();
	var $mutex;
	var $e;

	/**
	 * Constructor
	 * 
	 * Takes cache directory as param
	 *
	 * @param $cache_dir string
	 */
	function __construct($cache_dir = '') {
		
		$this->e = &owa_coreAPI::errorSingleton();
		return $this->cache_dir = $cache_dir;
	
	}

	function owa_cache($cache_dir = '') {
		
		register_shutdown_function(array(&$this, "__destruct"));
		return $this->__construct($cache_dir);
	
	}
	
	function setGlobalCollection($collection) {
	
		return $this->global_collections[] = $collection;
	
	}
	
	function setNonPersistantCollection($collection) {
	
		return $this->non_persistant_collections[] = $collection;
	
	}
	
	function set($collection, $key, $value) {
	
		$hkey = $this->hash($key);
		$this->cache[$collection][$hkey] = $value;
		$this->debug(sprintf('Added Object to Cache - Collection: %s, id: %s', $collection, $hkey));
		$this->statistics['added']++;
		
		if (!in_array($collection, $this->non_persistant_collections)):
			$this->dirty_objs[$collection][] = $hkey;
			//$this->debug(print_r($this->dirty_objs, true));
			$this->dirty_collections[$collection] = true; 
			$this->debug(sprintf('Added Object to Dirty List - Collection: %s, id: %s', $collection, $hkey));
			$this->statistics['dirty']++;
		// check to see if cache file exists and remove it just in case the collection
		// was recently added to the non persistant list.
		else:
			$this->removeCacheFile($this->makeCollectionDirPath($collection).$hkey.'.php');
		endif;
	
		return;
			
	}
	
	function replace($collection, $key, $value) {
	
		$hkey = $this->hash($key);
		$this->cache[$collection][$hkey] = $value;
		$this->debug(sprintf('Replacing Object in Cache - Collection: %s, id: %s', $collection, $hkey));
		$this->statistics['replaced']++;
		
		if (!in_array($collection, $this->non_persistant_collections)):
			// check to make sure the dirty collection exists and object is not already in there.
			if (!empty($this->dirty_objs[$collection])):
				if(!in_array($hkey, $this->dirty_objs[$collection])):
					$this->dirty_objs[$collection][] = $hkey;
					//$this->debug(print_r($this->dirty_objs, true));
					$this->dirty_collections[$collection] = true; 
					$this->debug(sprintf('Added Object to Dirty List - Collection: %s, id: %s', $collection, $hkey));
					$this->statistics['dirty']++;
				endif;
			else:
				$this->dirty_objs[$collection][] = $hkey;
				//$this->debug(print_r($this->dirty_objs, true));
				$this->dirty_collections[$collection] = true; 
				$this->debug(sprintf('Added Object to Dirty List - Collection: %s, id: %s', $collection, $hkey));
				$this->statistics['dirty']++;
			endif;
			
		// check to see if cache file exists and remove it just in case the collection
		// was recently added to the non persistant list.
		else:
			$this->removeCacheFile($this->makeCollectionDirPath($collection).$hkey.'.php');
		endif;

		
		return;
	}
	
	function get($collection, $key) {
		
		$this->debug("getting: ".$collection.$key);
		$id = $this->hash($key);
		
		// check warm cache and return
		if (isset($this->cache[$collection][$id])):
			$this->debug(sprintf('CACHE HIT (Warm) - Retrieved Object from Cache - Collection: %s, id: %s', $collection, $id));	
		$this->statistics['warm']++;
		//load from cache file	
		else:
		
			$cache_file = $this->makeCollectionDirPath($collection).$id.'.php'; 
			$this->debug("check cache file: ".$cache_file);
	
			// if no cache file then return false
			if (!file_exists($cache_file)):
				$this->debug(sprintf('CACHE MISS - Object Not Found in Cache or Cache File - Collection: %s, id: %s', $collection, $id));
				$this->statistics['miss']++;
				return false;
			
			// load from cache file	
			else:
		
				$this->cache[$collection][$id] = unserialize(base64_decode(substr(@ file_get_contents($cache_file), strlen($this->cache_file_header), -strlen($this->cache_file_footer))));
				$this->debug(sprintf('CACHE HIT (Cold) - Retrieved Object from Cache File - Collection: %s, id: %s', $collection, $id));
				$this->statistics['cold']++;
			endif;
			
		endif;
		
		return $this->cache[$collection][$id];
	
	}
	
	function flush() {
	
		$tld = $this->readDir($this->cache_dir);
		$this->debug(print_r($tld, true));
		$this->deleteFiles($tld['files']);
		
		foreach ($tld['dirs'] as $k => $dir) {
			
			$sld = $this->readDir($dir);
			$this->debug(print_r($sld, true));	
			$this->deleteFiles($sld['files']);
		
			foreach ($sld['dirs'] as $sk => $sdir) {
				$this->debug($sdir);
				$ssld = $this->readDir($sdir);
				$this->debug(print_r($ssld, true));	
				$this->deleteFiles($ssld['files']);	
				
				rmdir($sdir);
			}
			
			rmdir($dir);		
		}
		
		
				
		return;
			
	}
	
	function remove($collection, $key) {
	
		$id = $this->hash($key);
		unset($this->cache[$collection][$id]);
		
		return $this->removeCacheFile($this->makeCollectionDirPath($collection).$id.'.php');
		
	}
	
	function getStats() {
		return sprintf("Cache Statistics: 
						  Total Hits: %s (Warm/Cold: %s/%s)
						  Total Miss: %s
						  Total Added to Cache: %s
						  Total Replaced: %s
						  Total Persisted: %s
						  Total Removed: %s",
						  $this->statistics['warm'] + $this->statistics['cold'],
						  $this->statistics['warm'],
						  $this->statistics['cold'],
						  $this->statistics['miss'],
						  $this->statistics['added'],
						  $this->statistics['replaced'],
						  $this->statistics['dirty'],
						  $this->statistics['removed']);
	}

	function connect() {
		return false;
	}
	
	function makeCollectionDirPath($collection) {
	
		
		if (!in_array($collection, $this->global_collections)):
	
			return $this->cache_dir.$this->cache_id.DIRECTORY_SEPARATOR.$collection.DIRECTORY_SEPARATOR;
		
		else:
		
			return $this->cache_dir.$collection.DIRECTORY_SEPARATOR;
			
		endif;
	}
	
	function makeCacheCollectionDir($collection) {
		
		// localize the cache directory based on some id passed from caller
		
		if (!file_exists($this->cache_dir.$this->cache_id)):
			
			mkdir($this->cache_dir.$this->cache_id);
	                 
	        chmod($this->cache_dir.$this->cache_id, $this->dir_perms);
	        
	    endif;
		
		
		
		$collection_dir = $this->makeCollectionDirPath($collection);
		
		if (!file_exists($collection_dir)):
			
			mkdir($collection_dir);
	                 
	        chmod($collection_dir, $this->dir_perms);
	        
	    endif;
	
	    if (!file_exists($collection_dir."index.php")):
	    
	        
	        touch($collection_dir."index.php");
	        
	        chmod($collection_dir."index.php", $this->file_perms);
	        
	    endif;
		
		return;
	}
	
	function prepare($obj) {
	
		return;
	
	}
	
	function __destruct() {
		
		$this->persistCache();
		$this->debug($this->getStats());
		$this->persistStats();
		
		return;
	}
	
	function persistCache() {
		
		$this->debug("starting to persist cache...");
		
		// check for dirty objects
		if (!empty($this->dirty_objs)):
			
			$this->debug('Dirty Objects: '.print_r($this->dirty_objs, true));
				
			if ( ! $this->acquire_lock() ):
				$this->debug("could not persist cache due to not acquiring lock.");
	            return false;
	        else:
				$this->debug("starting to persist cache...");
				// make directories for collections
				foreach ($this->dirty_collections as $k => $v) {
					
					$this->makeCacheCollectionDir($k);
						
				}
				
				// persist dirty objects
				foreach ($this->dirty_objs as $collection => $ids) {
					
					foreach ($ids as $id) {
						
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
						
						if ( false === $tcf_handle ):
							$this->debug('could not acquire temp file handler');
						else:
							
							fputs($tcf_handle, $data);
							
							fclose($tcf_handle);
							
							if (!@ rename($temp_cache_file, $cache_file)):
								
								if (!@ copy($temp_cache_file, $cache_file)):
									$this->debug('could not rename or copy temp file to cache file');
								endif;
								
							endif;
							
							@ unlink($temp_cache_file);
							$this->debug('removing temp cache file');
							@ chmod($cache_file, $this->file_perms);
							$this->debug('changing file permissions on cache file');
						
						endif;
					}
				}
				
				
				
				
			endif;
			$this->release_lock();
		
		else:
			$this->debug("There seem to be no dirty objects in the cache to persist.");
		endif;
	
		return;
		
	}
	
	function removeCacheFile($cache_file) {
	
		// Remove the cache file
		if (file_exists($cache_file)):
			@ unlink($cache_file);
			$this->debug('Cache File Removed: '.$cache_file);
			$this->statistics['removed']++;
			return true;
		else:
			return false;
		endif;

	
	}
	
	function persistStats() {
	
		return;
	
	}
	
	function hash($id) {
	
		return md5($id);
	
	}
	
	function debug($msg) {
		
		$this->e->debug($msg);
		return;
	}
	
	function error($msg) {
	
		return false;
	}
	
	function setCacheDir($dir) {
		
		$this->cache_dir = $dir;
		return ;
	}
	
	function acquire_lock() {
		// Acquire a write lock.
		$this->mutex = @fopen($this->cache_dir.$this->lock_file_name, 'w');
	    if (false == $this->mutex):
	    	return false;
	    else:
		    flock($this->mutex, LOCK_EX);
	        return true;
	    endif;
	}
	
	function release_lock() {
        // Release write lock.
        flock($this->mutex, LOCK_UN);
	    fclose($this->mutex);
	    return;
	}
	
	function readDir($dir) {
	
		if ($handle = opendir($dir)):
 	
 			while (($file = readdir($handle)) !== false) {
				
				if (is_dir($dir.$file)):
				
					if (strpos($file, '.') === false): 
						$data['dirs'][] = $dir.$file.DIRECTORY_SEPARATOR;
					endif; 
				else:
					if (strpos($file, '.php') == true): 
						$data['files'][] = $dir.$file; 
					endif;
					
					if (strpos($file, '.lock') == true): 
						$data['files'][] = $dir.$file; 
					endif;
				endif;			
			}
	
		endif;
		
 		closedir($handle);
		
		return $data;
	}
	
	function deleteFiles($files) {
	
		foreach ($files as $file) {
			$this->debug("About to unlink: ".$file);
			unlink($file);
		}
		
		return;
		
	}
	
}

?>