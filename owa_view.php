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

require_once(OWA_BASE_CLASSES_DIR.'owa_template.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_requestContainer.php'); // ??

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

class owa_view extends owa_base {

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
     * @var unknown_type
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
     * @var unknown_type
     */
    var $page_type;

    /**
     * Request Params
     *
     * @var unknown_type
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

        $this->t = new owa_template();
        $this->body = new owa_template($this->module);
        $this->setTheme();
        $this->setCss("base/css/owa.css");
    }

    /**
     * Assembles the view using passed model objects
     *
     * @param unknown_type $data
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
        //array of errors usually used for field validations
        if (array_key_exists('validation_errors', $this->data)) {
            $this->body->set('validation_errors', $this->data['validation_errors']);
       }

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
                $this->subview->body->set('do', $this->data['params']['do']);
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

        $this->t->set_template($this->config['report_wrapper']);

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
     * @param array $data
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

        $this->subview = owa_coreAPI::subViewFactory($subview);
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
     * @param unknown_type $data
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

        $this->t->set_template('wrapper_subview.tpl');

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
        $url = sprintf('%s?version=%s', owa_coreAPI::getSetting('base', 'modules_url').$path, $version);
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

        $url = sprintf('%s?version=%s', owa_coreAPI::getSetting('base', 'modules_url').$path, $version);
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

        owa_lib::setContentTypeHeader($type);
    }

}

/**
 * Generic HTMl Table View
 *
 * Will produce a generic html table
 *
 */
class owa_genericTableView extends owa_view {

    function __construct() {

        return parent::__construct();

    }

    function render($data) {

        $this->t->set_template('wrapper_blank.tpl');
        $this->body->set_template('generic_table.tpl');

        if (!empty($data['labels'])):
            $this->body->set('labels', $data['labels']);
            $this->body->set('col_count', count($data['labels']));
        else:
            $this->body->set('labels', '');
            $this->body->set('col_count', count($data['rows'][0]));
        endif;

        if (!empty($data['rows'])):
            $this->body->set('rows', $data['rows']);
            $this->body->set('row_count', count($data['rows']));
        else:
            $this->body->set('rows', '');
            $this->body->set('row_count', 0);
        endif;

        if (array_key_exists('table_class', $data)):
            $this->body->set('table_class', $data['table_class']);
        else:
            $this->body->set('table_class', 'data');
        endif;

        if (array_key_exists('header_orientation', $data)):
            $this->body->set('header_orientation', $data['header_orientation']);
        else:
            $this->body->set('header_orientation', 'col');
        endif;

        if (array_key_exists('table_footer', $data)):
            $this->body->set('table_footer', $data['table_footer']);
        else:
            $this->body->set('table_footer', '');
        endif;

        if (array_key_exists('table_caption', $data)):
            $this->body->set('table_caption', $data['table_caption']);
        else:
            $this->body->set('table_caption', '');
        endif;

        if (array_key_exists('is_sortable', $data)) {
            if ($data['is_sortable'] != true) {
                $this->body->set('sort_table_class', '');
            }
        } else {
            $this->body->set('sort_table_class', 'tablesorter');
        }

        if (array_key_exists('table_row_template', $data)):
            $this->body->set('table_row_template', $data['table_row_template']);
        else:
            ;
        endif;

        // show the no data error msg
        if (array_key_exists('show_error', $data)):
            $this->body->set('show_error', $data['show_error']);
        else:
            $this->body->set('show_error', true);
        endif;

        $this->body->set('table_id', str_replace('.', '-', $data['params']['do']).'-table');

    }
}

/**
 * @depricated
 */
class owa_sparklineJsView extends owa_view {

    function __construct() {

        return parent::__construct();

    }

    function render($data) {

        // load template
        $this->t->set_template('wrapper_blank.tpl');
        $this->body->set_template('sparklineJs.tpl');
        // set
        $this->body->set('widget', $data['widget']);
        $this->body->set('type', $data['type']);
        $this->body->set('height', $data['height']);
        $this->body->set('width', $data['width']);
        $this->body->set('values', $data['series']['values']);
        $this->body->set('dom_id', $data['dom_id'].rand());
        //$this->setJs("includes/jquery/jquery.sparkline.js");
        return;
    }
}

class owa_mailView extends owa_view {

    // post office
    var $po;
    var $postProcessView = true;

    function __construct() {

        // make this a service
        require_once(OWA_BASE_CLASS_DIR.'mailer.php');
        $this->po = new owa_mailer;
        return parent::__construct();
    }

    function postProcess() {

        $this->po->setHtmlBody( $this->t->fetch() );

        if ( $this->get( 'plainTextView' ) ) {
            $this->po->setAltBody( owa_coreAPI::displayView( $this->get( 'plain_text_view' ) ) );
        }

        return $this->po->sendMail();
    }

    function setMailSubject($sbj) {

        $this->po->setSubject( $sbj );
    }

    function addMailToAddress($email, $name = '') {

        if (empty($name)) {
            $name = $email;
        }

        $this->po->addAddress($email, $name);
    }
}

class owa_adminView extends owa_view {

    var $postProcessView = true;

    function __construct() {

        return parent::__construct();
    }

    function post() {
        $this->setJs('owa.css');
        $this->setJs('owa.admin.css');
    }
}

/**
 * Rest API view
 *
 * This view assembles the response to REST API requests
 */
class owa_restApiView extends owa_view {
	
	function __construct() {
	 	
	 	parent::__construct();
	 	
	 	// load templates
        $this->t->set_template('wrapper_blank.tpl');
        
        $this->body->set_template('restApiResponse.php');
    }
    
    /**
	 * Used to set values of the response that we do not want the
	 * abstract view to worry about or have ot deal with.
	 *
	 */
    function pre() {
	   
	   // look for jsonp callback
        $callback = $this->get('jsonpCallback');

        // if not found look on the request scope.
        if ( ! $callback ) {
            $callback = owa_coreAPI::getRequestParam('jsonpCallback');
        }

        if ( $callback ) {
            $this->body->set('callback', $callback);
            $type = 'jsonp';
        } else {
            
            $type = 'json';
        }

	   // set header if the request is from the API endpoint. Could be an internal request.
	   
	   if ( owa_coreAPI::getSetting('base', 'request_mode') === 'rest_api') {
		   
			owa_lib::setContentTypeHeader( $type );
			
			// set cahce-control header to avid downstream caching.
			header("Cache-Control: max-age=0");		  
			
			// add CORS request headers
			$this->addCorsHeaders(); 
	   }

	   
		// Generate GUID for response   
	    $request = owa_coreAPI::getRequest();

        $this->body->set('request_id', $request->guid );
	    	    
	    $error = array();
	    
	    // set error msgs
        if ( array_key_exists( 'error_msg', $this->data ) ) {
	        
            $error[] = $this->data['error_msg'];
        }
        
        if ( array_key_exists( 'validation_errors', $this->data ) ) {
	        
            $error[] = $this->data['validation_errors'];
        }
        
        $http_response = array(
	        
	        'status_code'	=> http_response_code()
        );
        
        $this->body->set('http_response', $http_response);
        $this->body->set('data', '');
        $this->body->set('error', $error);
    }
    
    /**
	 * Sets the data payload of the response
	 */
    function setResponseData( $data ) {
	    
	    $this->body->set( 'response_data', $data );
    }
    
    function addCorsHeaders() {
	    
	    $s = owa_coreAPI::serviceSingleton();
	    $HTTP_ORIGIN = $s->request->getServerParam('HTTP_ORIGIN');
	    
	    // check for ORGIN header and bail if not found.
        if ( ! isset( $HTTP_ORIGIN ) || $HTTP_ORIGIN == '') {
	       
            return;
        }

        // Loop through sites list and add cors headers if the ORGIN header is present on the request
        foreach ( owa_coreAPI::getSitesList() as $allowedOrigin ) {
        	
        	if ( $allowedOrigin !== $HTTP_ORIGIN ) {
	        	
            	continue;
            }
			
			// send back the allowed orgin
            header( 'Access-Control-Allow-Origin: ' . $HTTP_ORIGIN );
            
            // needed to allow cookie content to become available to the DOM.
            header( "Access-Control-Allow-Credentials: true" );
            
            // stop the loop
            break;
        }
    }
}

class owa_jsonView extends owa_view {

    function __construct() {

        return parent::__construct();
    }

    function render() {

        // load template
        $this->t->set_template('wrapper_blank.tpl');
        $this->body->set_template('json.php');

        // look for jsonp callback
        $callback = $this->get('jsonpCallback');

        // if not found look on the request scope.
        if ( ! $callback ) {
            $callback = owa_coreAPI::getRequestParam('jsonpCallback');
        }

        if ( $callback ) {
            $body = sprintf("%s(%s);", $callback, json_encode( $this->get( 'json' ) ) );
            $type = 'jsonp';
        } else {
            $body = json_encode( $this->get( 'json' ) );
            $type = 'json';
        }

        $this->body->set('json', $body);

        owa_lib::setContentTypeHeader( $type );
    }
}

class owa_jsonResultsView extends owa_view {

    function __construct() {

        if (!class_exists('Services_JSON')) {
            require_once(OWA_INCLUDE_DIR.'JSON.php');
        }

        return parent::__construct();
    }

    function render() {

        // load template
        $this->t->set_template('wrapper_blank.tpl');
        $this->body->set_template('json.php');

        $json = new Services_JSON();
        // set
        $this->body->set('json', $json->encode($this->get('data')));
    }
}

class owa_adminPageView extends owa_view {

    function render() {

        // Set Page title
        $this->t->set('page_title', $this->get('title'));

        // Set Page headline
        $this->body->set('title', $this->get('title'));
        $this->body->set('titleSuffix', $this->get('titleSuffix'));
        $this->body->set_template('genericAdminPage.php');
        $this->setJs('owa.reporting', 'base/js/owa.reporting-combined-min.js');
        $this->setCss("base/css/owa.reporting-css-combined.css");
    }
}

class owa_cliView extends owa_view {

    function __construct( $params ) {
	   	
		parent::__construct($params);
    }
    
    function pre() {
	    
	    $this->t->set_template('wrapper_blank.tpl');
        $this->body->set_template('msgsCli.php');
	    
	    $error = array();
	    
	    // set error msgs
        if ( array_key_exists( 'error_msg', $this->data ) ) {
	        
            $error[] = $this->data['error_msg'];
        }
        
        if ( array_key_exists( 'validation_errors', $this->data ) ) {
	        
            $error[] = $this->data['validation_errors'];
        }
        
		$this->body->set('response_data', '');
		$this->body->set('error', $error);
    }
    
    /**
	 * Sets the data payload of the response
	 */
    function setResponseData( $data ) {
	    
	    $this->body->set( 'response_data', $data );
    }
}

?>