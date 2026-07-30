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
             $__owa_template_file = $this->file;
        else:
            $__owa_template_file = $this->template_dir.$file;
        endif;

        // DEPRECATED bare-variable contract, kept for compatibility. Third-party
        // module templates, site-owner templates/local/ overrides and custom themes
        // are written against extracted locals ($foo) and $this, and OWA neither
        // ships nor can migrate them. They keep working; removed at v2.0, alongside
        // the owa_* class-name bridge. OWA's own templates use $view instead.
        //
        // The locals above/below are underscore-prefixed because extract() defaults
        // to EXTR_OVERWRITE: a template payload with a 'file' or 'contents' key
        // would otherwise clobber the include path or the captured output.
        extract($this->vars);

        // The modern, analysable scope: $view->foo for data, $view->out() for
        // helpers. Built AFTER extract() so a stray 'view' key cannot clobber it.
        $view = new ViewScope($this);

        // try/finally so the buffer is always discarded, even when the template
        // throws. ViewScope::__get raises on a view var that was never set, and
        // that exception unwinds straight out of include() -- without the finally
        // the ob_start() above would leak. A leaked buffer is nastier than it
        // sounds: renders nest, so each swallowed template error leaves output
        // captured in a buffer nobody closes, and a later ob_get_contents()
        // returns unrelated markup.
        ob_start();
        try {
            include($__owa_template_file);
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

}
