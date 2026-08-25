<?php
namespace OWA\Module\Base\Controller;


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
 * Reset Secret keys Command Line Interface (CLI) Controller
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 */
class ResetSecretsCli extends \OWA\Core\Controller\Cli {
    /**
     *  constructor.
     * @param $params
     */
    public function __construct( $params ) {
        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_settings' );
    }

    /**
     *
     */
    public function action() {
        
        // The same setting the loader and the installer use, so this rewrites
        // the file the install is actually running on.
        $config_file = \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' );
        $temp_file   = $config_file . '.tmp';
        
        // make this check a validator
        if ( file_exists( $config_file ) ) {
	        
	        // clear out a prior tmp file
	        if ( file_exists( $temp_file ) ) {
		        
		        unlink( $temp_file );
	        }
	        
	        // load current config into memory
	        $current_config = file( $config_file );
	        
	        // create new config file
	        $new_config = fopen( $temp_file, 'w');
	        
	      
	        $mod = false;
	        
	        $secrets = [
		        'OWA_NONCE_KEY',
		        'OWA_NONCE_SALT',
		        'OWA_AUTH_KEY',
		        'OWA_AUTH_SALT'
	        ];
	        
	        // loop throug hthe current config line by line
	        foreach ( $current_config as $line_num => $line ) {
	        	
	        	$test = substr( $line, 8, 12 );
	        	
	        	// replaced flag
				$replaced = false;
	        	
	        	// loop through secrets
	        	foreach ( $secrets as $secret ) {
		        	
		        	if ( $test === substr( $secret, 0, 12 ) ) {
			        	// write new line
			        	fwrite( $new_config, sprintf( "define('%s', '%s' ); \n", $secret, \OWA\Core\CoreAPI::secureRandomString(64) ) );	
	                    
	                    $replaced = true;
	                    $mod = true;
	                    //skip the restof the loop as we already found the match
	                    continue;
	                    
		        	}
		        	
	        	}
	        	
	        	if ( ! $replaced )	{
		        	
		        	fwrite( $new_config, $line);
		        	
	        	}        	
	        }
	        
	        fclose( $new_config );
	        // make sure the new file is read/exec able
			chmod( $temp_file, 0750 );
			
			// if there was a mod makde to the config then make it the new config file.
			if ( $mod ) {
				
			  rename( $temp_file, $config_file );
			  \OWA\Core\CoreAPI::notice( "Secrets updated successfully." );
			
			} else {
				// else blow away any tmp file created.
				unlink( $temp_file );
			
			}
	        
	    } else {
		    
		    \OWA\Core\CoreAPI::debug( "Config file doesn't exist." );
	    }
    }

    public function errorAction() {
	    
        $this->setView('base.resetSecretsCli');
        $this->set('msgs', $this->getParam('validation_errors'));
    }

}
