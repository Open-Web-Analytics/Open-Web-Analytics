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


class owa_logFile {
	
	var $name = 'generic_log';
	var $file_path = '';
	var $file_handle;
	
	function __construct( $conf = array() ) {
		
		if ( array_key_exists( 'name', $conf ) ) {
			
			$this->name = $conf['name'];
		}
		
		if ( array_key_exists( 'file_path', $conf ) ) {
			
			$this->file_path = $conf['file_path'];
			//$this->file_handle = fopen( $this->file_path , "a" );
		}
	}
	
	function append( $msg ) {
		
		if ( $this->file_path ) {
		
			$handle = fopen( $this->file_path , "a" );
			fwrite( $handle, $msg );
			fclose( $handle );	
		}
	}
	
	function __destruct() {
		
		//fclose( $this->file_handle );
	}

}

?>