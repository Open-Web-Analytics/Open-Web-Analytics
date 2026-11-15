<?php
namespace OWA\Module\FileCache\Classes;


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
 * File Based Cache Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 - 2011 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class FileCache extends \OWA\Core\CacheType {

    var $cache_dir;
    var $lock_file_name = 'cache.lock';
    var $cache_file_header = "<?php \n /*";
    var $cache_file_footer = "*/ \n ?>";
    /*
     * Group read and write, setgid on directories. The web server and the
     * CLI may run as different users sharing a group, and both read, write
     * and remove entries. 0750 dropped the group's write and the setgid the
     * cache root carries, so each user's files were closed to the other: an
     * edit saved over the web could not remove what a CLI process had cached.
     * Nothing here is executed, so nothing is executable.
     */
    var $file_perms = 0660;
    var $dir_perms = 02770;
    var $mutex;
  

    /**
     * Constructor
     *
     * Takes cache directory as param
     *
     * @param $cache_dir string
     */
    function __construct( $params ) {

        if ( array_key_exists( 'cache_dir', $params ) && $params['cache_dir'] ) {
	        
            $this->cache_dir = $params['cache_dir'];
            
        } else {
	        
            $this->cache_dir = \OWA\Core\Env::cacheDir();
        }
        
        if ( array_key_exists( 'cache_id', $params ) && $params['cache_id'] ) {
	        
	    	$this->cache_id = $params['cache_id'];
	    }
    }

    function get( $collection, $id ) {

        $cache_file = $this->makeCollectionDirPath($collection).$id.'.php';
        \OWA\Core\CoreAPI::debug("check cache file: ".$cache_file);

        // if no cache file then return false
        if (!file_exists($cache_file)) {
            \OWA\Core\CoreAPI::debug(sprintf('Cache File not found for Collection: %s, id: %s, file: %s', $collection, $id, $cache_file));
            return false;

        // cache object has expired
        } elseif ((filectime($cache_file) + $this->getCollectionExpirationPeriod($collection)) < time()) {
            \OWA\Core\CoreAPI::debug("time: ".time());
            \OWA\Core\CoreAPI::debug("ctime: ".filectime($cache_file));
            \OWA\Core\CoreAPI::debug("diff: ".(time() - filectime($cache_file)));
            \OWA\Core\CoreAPI::debug("exp period: ".$this->getCollectionExpirationPeriod($collection));
            $this->removeCacheFile($this->makeCollectionDirPath($collection).$id.'.php');
            \OWA\Core\CoreAPI::debug(sprintf('Cache Object has expired for Collection: %s, id: %s', $collection, $id));
           
        // load from cache file
        } else {

            /*
             * Plain data only. Entities cache a row's values and nothing else
             * stores objects, so a file that rebuilds into one is either from
             * before rows were cached as values or not this cache's -- and
             * unserialize() building whatever class a file names is how a
             * writable cache directory becomes code execution.
             */
            $value = unserialize(
                base64_decode(substr(@ file_get_contents($cache_file), strlen($this->cache_file_header), -strlen($this->cache_file_footer))),
                array( 'allowed_classes' => false ) );

            if ( is_object( $value ) || ( is_array( $value ) && self::holdsObject( $value ) ) ) {

                $this->removeCacheFile( $cache_file );

                return false;
            }

            return $value;
        }

    }

    /** Whether an unserialized array holds an object at any depth. */
    private static function holdsObject( array $value ) {

        foreach ( $value as $item ) {

            if ( is_object( $item ) || ( is_array( $item ) && self::holdsObject( $item ) ) ) {

                return true;
            }
        }

        return false;
    }

    function set( $collection, $id, $value ) {

        if ( ! $this->acquire_lock() ) {

            \OWA\Core\CoreAPI::debug("could not persist item to cache due to failure acquiring lock.");

            return false;
        }

        $written = false;

        if ( $this->makeCacheCollectionDir( $collection ) ) {

            $collection_dir = $this->makeCollectionDirPath( $collection );
            $cache_file     = $collection_dir . $id . '.php';
            $temp_file      = @ tempnam( $collection_dir, 'tmp_' . $id );

            /*
             * tempnam() falls back to the system temp directory when the one
             * it is given will not take a file, and the rename below then
             * fails -- leaving a copy of the entry in /tmp on every write.
             */
            if ( $temp_file !== false && realpath( dirname( $temp_file ) ) !== realpath( $collection_dir ) ) {

                @ unlink( $temp_file );
                $temp_file = false;
            }

            if ( $temp_file !== false ) {

                $data = $this->cache_file_header . base64_encode( serialize( $value ) ) . $this->cache_file_footer;

                if ( @ file_put_contents( $temp_file, $data ) !== false && @ rename( $temp_file, $cache_file ) ) {

                    @ chmod( $cache_file, $this->file_perms );
                    $written = true;

                } else {

                    @ unlink( $temp_file );
                    \OWA\Core\CoreAPI::debug( 'could not write cache file ' . $cache_file );
                }
            }
        }

        $this->release_lock();

        return $written;
    }

    function remove( $collection, $id ) {

        return $this->removeCacheFile($this->makeCollectionDirPath($collection).$id.'.php');
    }

    function makeCollectionDirPath($collection) {

        return $this->cache_dir.$this->cache_id.'/'.$collection.'/';
    }

    /**
     * Make the collection's directory if it is not there.
     *
     * @return bool whether the directory is there and takes files
     */
    function makeCacheCollectionDir($collection) {

        if ( ! is_writable( $this->cache_dir ) ) {

            return false;
        }

        foreach ( array( $this->cache_dir . $this->cache_id . '/', $this->makeCollectionDirPath( $collection ) ) as $dir ) {

            if ( ! is_dir( $dir ) ) {

                if ( ! @ mkdir( $dir ) && ! is_dir( $dir ) ) {

                    return false;
                }

                @ chmod( $dir, $this->dir_perms );

            // One made with the old 0750, by this user: brought up to date. Any
            // other mode is someone's choice and is left alone.
            } elseif ( ( fileperms( $dir ) & 07777 ) === 0750
                    && function_exists( 'posix_geteuid' ) && fileowner( $dir ) === posix_geteuid() ) {

                @ chmod( $dir, $this->dir_perms );
            }

            // An empty index.php, so a server that lists directories lists nothing.
            if ( ! file_exists( $dir . 'index.php' ) && @ touch( $dir . 'index.php' ) ) {

                @ chmod( $dir . 'index.php', $this->file_perms );
            }
        }

        return is_writable( $this->makeCollectionDirPath( $collection ) );
    }

    function removeCacheFile($cache_file) {

        // Remove the cache file
        if (file_exists($cache_file)) {
            @ unlink($cache_file);
            \OWA\Core\CoreAPI::debug('Cache File Removed: '.$cache_file);
            return true;
        } else {
            \OWA\Core\CoreAPI::debug('Cache File does not exist: '.$cache_file);
            return false;
        }
    }

    /**
     * Remove every entry, and any temp file a failed write left.
     *
     * The directories, their index.php and the lock file stay: they are the
     * cache's structure, not its entries.
     */
    function flush() {

        foreach ( (array) glob( $this->cache_dir . '*/*/*' ) as $file ) {

            $name = basename( $file );

            if ( is_file( $file ) && $name !== 'index.php'
                    && ( substr( $name, -4 ) === '.php' || strpos( $name, 'tmp_' ) === 0 ) ) {

                @ unlink( $file );
            }
        }

        return true;
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

}

?>