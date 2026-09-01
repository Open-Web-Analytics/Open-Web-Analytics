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
 * Options Modules Roster Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class OptionsModules extends \OWA\Core\AdminController {

    function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        return parent::__construct($params);
    }

    function action() {

        $path = OWA_BASE_CLASSES_DIR.'modules/';
        $dirs = array();

        if ($handle = opendir($path)):
             while (($file = readdir($handle)) !== false) {

                 // test for '.' in dir name
                if (strpos($file, '.') === false):

                    // test for whether file is a dir
                    if (is_dir($path.$file)):

                         $mod = \OWA\Core\CoreAPI::moduleClassFactory($file);
                         $dirs[$file]['name'] = $mod->name;
                         $dirs[$file]['display_name'] = $mod->display_name;
                         $dirs[$file]['author'] = $mod->author;
                         $dirs[$file]['group'] = $mod->group;
                         $dirs[$file]['version'] = $mod->version;
                         $dirs[$file]['description'] = $mod->description;
                         $dirs[$file]['config_required'] = $mod->config_required;
                         $dirs[$file]['current_schema_version'] = $mod->getSchemaVersion();
                         $dirs[$file]['required_schema_version'] = $mod->getRequiredSchemaVersion();
                         $dirs[$file]['schema_uptodate'] = $mod->isSchemaCurrent();
                         //$dirs['stats'] = lstat($path.$file);

                     endif;

                   endif;
             }
         endif;

         closedir($handle);

        ksort($dirs);

        // remove base module so it can't be deactivated
        // unset($dirs['base']);

        // getActiveModules() returns runtime module names (the lowercase config
        // keys, e.g. 'hello'), but $dirs is keyed by on-disk directory name,
        // which is PascalCase post-PSR-4 (e.g. 'Hello'). Match on the stored
        // runtime name ($mod->name) rather than the directory key so the roster
        // renders Deactivate for active modules.
        $active_modules = array_flip( \OWA\Core\CoreAPI::getActiveModules() );

        foreach ($dirs as $dir => $info) {

            if (isset($info['name']) && isset($active_modules[$info['name']])):
                $dirs[$dir]['status'] = 'active';
            endif;
        }

        // add data to container
        /*
         * The hierarchy wrapper. Install-wide options live at the top of the same
         * nav now -- one settings menu rather than two, which is only possible
         * because every session lands on a Profile and the tile is always
         * populated.
         */
        $owa_site_id = $this->resolveCurrentSiteId();
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $owa_site_id ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $owa_site_id ) );
        /* Tier 0: an install-wide screen, so the context line names nothing below it. */
        $this->set( 'hierarchy_tier', 0 );
        $this->setView('base.optionsHierarchy');
        $this->setSubview('base.optionsModules');
        $this->set('modules', $dirs);

        return;

    }

}



?>
