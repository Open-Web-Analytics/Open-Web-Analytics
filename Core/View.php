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
 * Abstract View Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class View extends \OWA\Core\Base {

    /**
     * Main view template object
     *
     * @var object
     */
    var $t;

    /**
     * Body content template object
     *
     * @var object
     */
    var $body;

    /**
     * Sub View object
     *
     * @var object
     */
    var $subview;

    /**
     * Rednered subview
     *
     * @var string
     */
    var $subview_rendered;

    /**
     * CSS file for main template
     *
     * @var mixed
     */
    var $css_file;

    /**
     * The priviledge level required to access this view
     * @depricated
     * @var string
     */
    var $priviledge_level;

    /**
     * Type of page
     *
     * @var string
     */
    var $page_type;

    /**
     * Request Params
     *
     * @var array
     */
    var $params;

    /**
     * Authorization object
     *
     * @var object
     */
    var $auth;

    var $module; // set by factory.

    var $data;

    var $default_subview;

    var $is_subview;

    var $js = array();

    var $css = array();

    var $postProcessView = false;

    var $renderJsInline;

    /**
     * Constructor
     *
     */
    function __construct($params = null) {

        parent::__construct($params);

        $this->t = new \OWA\Core\Template();
        $this->body = new \OWA\Core\Template($this->module);
        $this->setTheme();
        $this->setCss("base/css/owa.css");
    }

    /**
     * Assembles the view using passed model objects
     *
     * @param mixed $data
     * @return unknown
     */
    function assembleView($data) {

        $this->e->debug('Assembling view: '.get_class($this));


        // set view name in template class. used for navigation.
        if (array_key_exists('view', $this->data)) {
            $this->body->caller_params['view'] = $this->data['view'];
        }

        if (array_key_exists('params', $this->data)):
            $this->body->set('params', $this->data['params']);
        endif;

        if (array_key_exists('subview', $this->data)):
            $this->body->caller_params['subview'] = $this->data['subview'];
        endif;

        // Assign status msg
        if (array_key_exists('status_msg', $this->data)):
            $this->t->set('status_msg', $this->data['status_msg']);
        endif;

        // get status msg from code passed on the query string from a redirect.
        if (array_key_exists('status_code', $this->data)):
            $this->t->set('status_msg', $this->getMsg($this->data['status_code']));
        endif;

        // set error msg directly if passed from constructor
        if (array_key_exists('error_msg', $this->data)):
            $this->t->set('error_msg', $this->data['error_msg']);
        endif;

        // authentication status
        if (array_key_exists('auth_status', $this->data)):
            $this->t->set('authStatus', $this->data['auth_status']);
        endif;

        // get error msg from error code passed on the query string from a redirect.
        if (array_key_exists('error_code', $this->data)):
            $this->t->set('error_msg', $this->getMsg($this->data['error_code']));
        endif;

        // load subview
        if (!empty($this->data['subview']) || !empty($this->default_subview)):
            // Load subview
            $this->loadSubView($this->data['subview']);
        endif;
		
		$this->pre();
		
        // construct main view.  This might set some properties of the subview.
        if (method_exists($this, 'render')) {
            $this->render($this->data);
        } else {
            // old style
            $this->construct($this->data);
        }
        // Array of errors, usually used for field validations. Set
        // UNCONDITIONALLY so a form template can always read it: a template that
        // renders both a fresh form and a re-submitted one with errors should not
        // have to care which branch of the controller it arrived through. The
        // subview equivalent below already does this. Empty array, not null, so
        // if()/empty() keep behaving exactly as they did when the key was absent.
        $this->body->set(
            'validation_errors',
            $this->data['validation_errors'] ?? []
        );

        // pagination
        if (array_key_exists('pagination', $this->data)) {
            $this->body->set('pagination', $this->data['pagination']);
        }

        //$this->_setLinkState();

        // assemble subview
        if (!empty($this->data['subview'])) {

            // set view name in template. used for navigation.
            $this->subview->body->caller_params['view'] = $this->data['subview'];

            // Set validation errors
            $this->subview->body->set('validation_errors', $this->get('validation_errors'));

            // pagination
            if (array_key_exists('pagination', $this->data)) {
                $this->subview->body->set('pagination', $this->data['pagination']);
            }

            if (array_key_exists('params', $this->data)) {
                $this->subview->body->set('params', $this->data['params']);

                // Not every request carries `do` -- a report reached by reportId
                // does not -- and reading it unconditionally warned on those.
                $this->subview->body->set('do', $this->data['params']['do'] ?? '');
            }

            // Load subview
            $this->renderSubView($this->data);

            // assign subview to body template
            $this->body->set('subview', $this->subview_rendered);


        }

        // assign validation errors
        if (!empty($this->data['validation_errors'])) {
	        
            $this->t->set('validation_errors', $this->data['validation_errors']);
        }


        // fire post method
        $this->post();

        // assign css and js ellements if the view is not a subview.
        // subview css/js have been merged/pulls from subview and assigned here.
        if ($this->is_subview != true) {
            if (!empty($this->css)) {
                $this->t->set('css', $this->css);
            }

            if (!empty($this->js)) {
                $this->t->set('js', $this->js);
            }
        }

        //Assign body to main template
        $this->t->set('config', $this->config);

        //Assign body to main template
        $this->t->set('body', $this->body);

        if ($this->postProcessView === true){
            return $this->postProcess();
        } else {
            // Return fully asembled View
            return $this->t->fetch();
        }
    }
    
	/**
     * Abstract pre render hook
     *
     */
	function pre() {
		
		return false;
	}
	
    /**
     * Abstract Alternative rendering method reuires the setting of $this->postProcessView to fire
     *
     */
    function postProcess() {

        return false;
    }

    /**
     * Post method fired right before view is rendered and returned
     * as output
     */
    function post() {

        return false;
    }


    /**
     * Sets the theme to be used by a view
     *
     */
    function setTheme() {

        // report_wrapper is a config-file / settings value; reduce it to a
        // safe basename before handing it to the template loader so that a
        // poisoned setting cannot inject exotic content into log output.
        $wrapper = \OWA\Core\Template::sanitizeTemplateName( $this->config['report_wrapper'] );

        if ( $wrapper === '' ) {
            $wrapper = 'wrapper_default.php';
        }

        $this->t->set_template( $wrapper );

        return;
    }

    /**
     * Abstract method for assembling a view
     * @depricated
     * @param array $data
     */
    function construct($data) {

        return;

    }

    /**
     * Assembles subview
     *
     * @param string $subview
     */
    function loadSubView($subview) {

        if (empty($subview)):
            if (!empty($this->default_subview)):
                $subview = $this->default_subview;
                $this->data['subview'] = $this->default_subview;
            else:
                return $this->e->debug("No Subview was specified by caller.");
            endif;
        endif;

        $this->subview = \OWA\Core\CoreAPI::subViewFactory($subview);
        //print_r($subview.'///');
        $this->subview->setData($this->data);
    }

    /**
     * Assembles subview
     *
     * @param array $data
     */
    function renderSubView($data) {

        // Stores subview as string into $this->subview
        $this->subview_rendered = $this->subview->assembleSubView($data);

        // pull css and js elements needed by subview
        $this->css = array_merge($this->css, $this->subview->css);
        $this->js = array_merge($this->js, $this->subview->js);
    }

    /**
     * Assembles the view using passed model objects
     *
     * @param mixed $data
     * @return unknown
     */
    function assembleSubView($data) {

        // construct main view.  This might set some properties of the subview.
        if (method_exists($this, 'render')) {
            $this->render($data);
        } else {
            // old style
            $this->construct($data);
        }

        $this->t->set_template('wrapper_subview.php');

        //Assign body to main template
        $this->t->set('body', $this->body);

        // Return fully asembled View
        $page =  $this->t->fetch();

        return $page;

    }

    function setCss($path, $version = null, $deps = array(), $ie_only = false) {

        if ( ! $version ) {
	        
            $version = OWA_VERSION;
        }

        $uid = $path;
        // Built stylesheets are served from the public/ asset tree, not the module
        // source tree -- see settings.php setupPaths() (assets_url).
        $url = sprintf('%s?version=%s', \OWA\Core\CoreAPI::getSetting('base', 'assets_url').$path, $version);
        $this->css[$uid]['url'] = $url;
        // build file system path just in case we need to concatenate the JS into a single file.
        $fs_path = OWA_MODULES_DIR.$path;
        $this->css[$uid]['path'] = $fs_path;
        $this->css[$uid]['deps'] = $deps;
        $this->css[$uid]['version'] = $version;
        $this->css[$uid]['ie_only'] = $ie_only;
    }

    function setJs($name, $path, $version ='', $deps = array(), $ie_only = false) {

        if (empty($version)) {
	        
            $version = OWA_VERSION;
        }

        $uid = $name.$version;

        // Built scripts are served from the public/ asset tree, not the module source
        // tree -- see settings.php setupPaths() (assets_url).
        $url = sprintf('%s?version=%s', \OWA\Core\CoreAPI::getSetting('base', 'assets_url').$path, $version);
        $this->js[$uid]['url'] = $url;

        // build file system path just in case we need to concatenate the JS into a single file.
        $fs_path = OWA_MODULES_DIR.$path;
        $this->js[$uid]['path'] = $fs_path;
        $this->js[$uid]['deps'] = $deps;
        $this->js[$uid]['version'] = $version;
        $this->js[$uid]['ie_only'] = $ie_only;
    }

    function concatinateJs() {

        $js_libs = '';

        foreach ($this->js as $lib) {

            $js_libs .= file_get_contents($lib['path']);
            $js_libs .= "\n\n";
        }

        $this->body->set('js_includes', $js_libs);
    }

    /**
     * Sets flag to tell view to render the JS inline as <SCRIPT> blocks
     * @todo not yet implemented
     */
    function renderJsInline() {

        $this->renderJsInLine = true;
    }


    /**
     * Sets the Priviledge Level required to access this view
     *
     * @param string $level
     */
    function _setPriviledgeLevel( $level ) {

        $this->priviledge_level = $level;

        return;
    }

    /**
     * Sets the page type of this view. Used for tracking.
     *
     * @param string $page_type
     */
    function _setPageType( $page_type ) {

        $this->page_type = $page_type;

        return;
    }


    /**
     * Sets properties that are needed to maintain state in links to
     * reports. This is used by many template functions.
     *
     */
    function _setLinkState( $p = array() ) {

        // if an array is not passed them just use params
        if ( ! $p ) {
	        
            $p = $this->get( 'params' );
        }
        
        // control array - will check for these params. If they exist it will return.
        $sp = [
	        
            'period' => null,
            'startDate' => null,
            'endDate' => null,
            'siteId' => null,
            'startTime' => null,
            'endTime' => null
        ];
                
        // merge in any stte keys passed from the controller.
        $state_keys = $this->get('state_keys') ?: [];
        
        foreach ( $state_keys as $k) {
	        
	        $sp[$k] = null;
        }

        // final result array
        $link_params = array();
		
		// load the state array with values
        if ( ! empty( $p ) ) {

            $link_params = array_intersect_key($p, $sp);
        }
        
        // needed for backward compatability with old use of site_id key name
        // @todo research if this is still required.
        if ( array_key_exists( 'site_id', $link_params ) && ! array_key_exists( 'siteId', $link_params ) ) {
	        
            $link_params['siteId'] = $link_params['site_id'];
        }
		
		// pass link stte to the various templates
        $this->t->caller_params['link_state'] =  $link_params;
        
        $this->body->caller_params['link_state'] =  $link_params;

        if( ! empty( $this->subview ) ) {
	        
            $this->subview->body->caller_params[ 'link_state' ] =  $link_params;
        }
    }

    function get( $name ) {

        if ( array_key_exists( $name, $this->data ) ) {
	        
            return $this->data[ $name ];
            
        } else {
	        
            return false;
        }
    }

    function set($name, $value) {

        $this->data[$name] = $value;
    }

    function setSubViewProperty($name, $value) {

        $this->subview->set($name, $value);
    }

    function getSubViewProperty($name) {
        return $this->subview->get($name);
    }

    function setData($data) {
        $this->data = $data;
    }

    function setTitle($title, $suffix = '') {

        $this->t->set('page_title', $title);
        $this->t->set('titleSuffix', $suffix);
    }

    function setContentTypeHeader($type = 'html') {

        \OWA\Core\Lib::setContentTypeHeader($type);
    }

}



















?>
