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

require (OWA_INCLUDE_DIR.'jsmin-1.1.1.php');
require_once(OWA_BASE_CLASS_DIR.'cliController.php');

/**
 * Build Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_buildController extends owa_cliController {

    function __construct($params) {

        parent::__construct($params);

        $this->setRequiredCapability('edit_modules');
    }

    function action() {

        $packages = owa_coreAPI::getBuildPackages();

        if ( $this->getParam('package') ) {

            $packages = array($packages[$this->getParam('package')]);
        }

        if ($packages) {
            foreach ($packages as $package) {

                owa_coreAPI::debug(sprintf("Building %s Package.", $package['name'] ) );
                $con = sprintf("/* OWA %s package file created %s */ \n\n", $package['name'] ,date( DATE_RFC822 ) );
                $isMin = false;
                foreach ($package['files'] as $name => $file_info) {

                    owa_coreAPI::debug("Reading file from: " . $file_info['path'] );
                    $con .= "/* Start of $name */ \n\n";
                    $content = file_get_contents( $file_info['path'] );
                    if (isset($file_info['compression']) && $file_info['compression'] === 'minify') {
                        owa_coreAPI::debug("Minimizing Javascript in: " . $file_info['path'] );
                        $content = JSMin::minify($content);
                        $isMin = true;
                    }
                    $con .= $content . "\n\n";
                    $con .= "/* End of $name */ \n\n";
                }
                $file_name = $package['output_dir'].$package['name']."-combined";
                if ($isMin) {
                    $file_name .= '-min';
                }

                $file_name .= '.' . $package['type'];

                owa_coreAPI::debug('Writing package to file: '.$file_name);
                $handle = fopen($file_name, "w");
                fwrite($handle, $con);
                fclose($handle);
            }
        } else {
            owa_coreAPI::debug( "No packages registered to build." );
        }
    }
}


?>