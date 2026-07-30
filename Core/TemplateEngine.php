<?php
namespace OWA\Core;

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
 * Minimal PHP template engine — set vars, then include-and-buffer a template
 * file. OWA\Core\Template extends this with all the OWA-specific rendering
 * helpers. (Formerly the global-namespace `Template` class in
 * includes/template_class.php; relocated into Core/ during the Phase-6 PSR-4
 * migration. The old `CachedTemplate` subclass was dropped as dead code — it
 * had no instantiations or references anywhere in the tree.)
 *
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @since        owa 1.0.0
 */
// TODO: replace with explicit property declarations (deprecated in PHP 8.2).
#[\AllowDynamicProperties]
class TemplateEngine {

    /**
     * Template files directory
     *
     * @var string
     */
    var $template_dir;

    /**
     * Template Variables
     *
     * @var array
     */
    var $vars = array();

    /**
     * Template file
     *
     * @var string
     */
    var $file;

    /**
     * Constructor
     *
     * @access public
     */
    function __construct() {

    }

    /**
     * Set the template file
     *
     * @param string $file
     */
    function set_template($file = null) {
        $this->file = $this->template_dir.$file;
        return;
    }

    /**
     * Set a template variable
     *
     * @param string $name
     * @param unknown_value $value
     * @access public
     */
    function set($name, $value) {

        if (is_object($value)) {
            if ($value instanceof $this) {
                $value = $value->fetch();
            }
        }

        $this->vars[$name] =  $value;
        return;
    }

    /**
     * Open, parse, and return the template file.
     *
     * @param string $file
     * @return string $contents
     * @access public
     */
    function fetch($file = null) {
        if(!$file):
             $file = $this->file;
        else:
            $file = $this->template_dir.$file;
        endif;

        extract($this->vars);          // Extract the vars to local namespace
        ob_start();                    // Start output buffering
        include($file);                // Include the file
        $contents = ob_get_contents(); // Get the contents of the buffer
        ob_end_clean();                // End buffering and discard
        return $contents;              // Return the contents
    }

}
