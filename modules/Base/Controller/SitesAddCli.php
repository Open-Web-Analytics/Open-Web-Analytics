<?php
namespace OWA\Module\Base\Controller;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2011 Peter Adams. All rights reserved.
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
 * Add Site Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.1
 */

class SitesAddCli extends \OWA\Module\Base\Controller\SitesAdd {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Adds a tracked website or app as a Profile, under an existing Property or a new one.',
            'arguments'   => array(
                'domain=<url>'         => 'Required for a website. Its domain.',
                'name=<text>'          => 'Required unless propertyId= is given. Names the new Property.',
                'propertyId=<id>'      => 'Add the Profile to this existing Property instead of creating one.',
                'streamType=<web|app>' => 'What the Profile observes. Defaults to web.',
                'appId=<id>'           => 'Required for an app. Its bundle ID or package name.',
                'description=<text>'   => 'A description.',
                'site_family=<text>'   => 'A family name to group it under.',
            ),
        );
    }
    
	function errorAction() {
	
        $this->setView('base.cli');
    }
    
    function success() {
	   
	    $this->setView('base.sitesAddCli');
    }
}





?>
