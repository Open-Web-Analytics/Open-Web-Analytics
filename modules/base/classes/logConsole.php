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


class owa_logConsole {
    
    var $name = 'generic_console_log';
    
    function __construct( $conf = array() ) {
        
        if ( array_key_exists( 'name', $conf ) ) {
            
            $this->name = $conf['name'];
        }
        
    }
    
    function append( $msg ) {
        
        if ( ! defined('STDOUT') ) {
        
            define('STDOUT', fopen("php://stdout", "w") );
        }
        
        fwrite( STDOUT, $msg );    
        
    }

}

?>