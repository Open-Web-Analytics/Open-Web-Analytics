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
 * OWA Wrapper for template class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Template extends TemplateEngine {

    /**
     * Configuration
     *
     * @var array
     */
    var $config;

    var $theme_template_dir;

    var $module_local_template_dir;

    var $module_template_dir;

    var $e;

    var $period;

    var $time_now;

    /**
     * Params passed by calling caller
     *
     * @var array
     */
    var $caller_params;

    function __construct( $module = null, $caller_params = array() ) {

        $this->caller_params = $caller_params;

        $c = \OWA\Core\CoreAPI::configSingleton();
        $this->config = $c->fetch('base');

        $this->e = \OWA\Core\CoreAPI::errorSingleton();

        // set template dirs
        if(!empty($caller_params['module'])):
            $this->_setTemplateDir($module);
        else:
            $this->_setTemplateDir('base');
        endif;

        $this->time_now = \OWA\Core\Lib::time_now();
    }

    function _setTemplateDir($module) {

        // set module template dir (on-disk module dir is PascalCase; PSR-4)
        $this->module_template_dir = OWA_DIR.'modules'.'/' . \OWA\Core\Lib::moduleDirName( $module ) . '/'.'templates'.'/';

        // set module local template override dir
        $this->module_local_template_dir = $this->module_template_dir.'local'.'/';

        // set theme template dir
        $this->theme_template_dir = OWA_THEMES_DIR.$this->config['theme'].'/';

        return;
    }

    function getTemplatePath($module, $file) {

        $this->_setTemplateDir($module);

        if ($file == null) {
            \OWA\Core\CoreAPI::error('No template file was specified.');
            return false;
        } else {
            // check module's local modification template Directory
            if (file_exists($this->module_local_template_dir.$file)) {
                $fullfile = $this->module_local_template_dir.$file;

            // check theme's template Directory
            } elseif(file_exists($this->theme_template_dir.$file)) {
                $fullfile = $this->theme_template_dir.$file;

            // check module's template directory
            } elseif(file_exists($this->module_template_dir.$file)) {
                $fullfile = $this->module_template_dir.$file;

            // throw error
            } else {
                $this->e->err(sprintf('%s was not found in any template directory.', $file));
                return false;
            }
            return $fullfile;
        }



    }

    /**
     * Set the template file
     * @depricated
     * @param string $file
     */
    function set_template($file = null) {

        if (!$file):
            \OWA\Core\CoreAPI::error('No template file was specified.');
            return false;
        else:
            // Normalize the requested filename before it is used in any
            // filesystem lookup or logged in an error message. Template
            // names may only contain a safe basename character set; a
            // path-containing or exotic value would be attacker-controlled
            // config (see report_wrapper RCE chain).
            $file = self::sanitizeTemplateName( $file );

            if ( $file === '' ) {
                \OWA\Core\CoreAPI::error('Invalid template file name.');
                return false;
            }

            // check module's local modification template Directory
            if (file_exists($this->module_local_template_dir.$file)):
                $this->file = $this->module_local_template_dir.$file;

            // check theme's template Directory
            elseif(file_exists($this->theme_template_dir.$file)):
                $this->file = $this->theme_template_dir.$file;

            // check module's template directory
            elseif(file_exists($this->module_template_dir.$file)):
                $this->file = $this->module_template_dir.$file;

            // throw error
            else:
                $this->e->err(sprintf('%s was not found in any template directory.', $file));
                return false;
            endif;

            return true;
        endif;
    }

    /**
     * Reduce a template name to a safe basename. Strips any path component
     * and any character outside [A-Za-z0-9._-]. This neutralizes payloads
     * that rely on quoted-printable escapes, angle brackets, question marks,
     * or backticks surviving into an error-log line.
     */
    public static function sanitizeTemplateName( $file ) {

        if ( ! is_string( $file ) ) {
            return '';
        }

        $file = basename( $file );
        $file = preg_replace( '/[^A-Za-z0-9._\-]/', '', $file );

        return (string) $file;
    }
    
    function setTemplateFile($module, $file) {

        //choose file
        $filepath = $this->getTemplatePath($module, $file);
        //set template
        if ($filepath) {
            $this->file = $filepath;
        }
    }

    /**
     * Truncate string
     *
     * @param string $str
     * @param integer $length
     * @param string $trailing
     * @return string
     */
    function truncate ($str, $length=10, $trailing='...')  {

      return \OWA\Core\Lib::truncate ($str, $length, $trailing);
    }

    function get_month_label($month) {

        return \OWA\Core\Lib::get_month_label($month);
    }

    /**
     * Chooses the right icon based on browser type
     *
     * @param mixed $browser_type
     * @return unknown
     */
    function choose_browser_icon($browser_type) {
	    if(is_null($browser_type)){
		    $browser_type = 'null';
	    }
		
		$bicons = [
			
			'chrome'				=> 'fab fa-chrome',
			'safari'				=> 'fab fa-safari',
			'firefox'				=> 'fab fa-firefox-browser',
			'internet explorer'		=> 'fab fa-internet-explorer',
			'ie'					=> 'fab fa-internet-explorer',
			'opera'					=> 'fab fa-opera',
			'edge'					=> 'fab fa-edge'
		];
		
		foreach ( $bicons as $k => $v ) {
			
			if ( strpos(strtolower($browser_type), $k) !== false ) {
				
				return $bicons[ $k ];
			}
		}
		
		return 'fas fa-window-maximize';
		
    }

    function getBrowserIcon($browser_family, $size = '128x128', $module = 'base') {

        if ($browser_family) {
            $browser_family = strtolower($browser_family);
        }


        // FS existence check uses the PascalCase on-disk dir; the makeImageLink
        // args stay lowercase 'base/...' (they resolve against public/, not modules/).
        if (file_exists(OWA_MODULES_DIR.\OWA\Core\Lib::moduleDirName($module).'/i/browsers/'.$size.'/'.$browser_family.'.png')) {
            return $this->makeImageLink('base/i/browsers/'.$size.'/'.$browser_family.'.png');
        } else {
            return $this->makeImageLink('base/i/browsers/'.$size.'/default.png');
        }
    }


    function makeLinkQueryString($query_params) {

        $new_query_params = array();

        //Load params passed by caller
        if (!empty($this->caller_params)):
            foreach ($this->caller_params as $name => $value) {
                if (!empty($value)):
                    $new_query_params[$name] = $value;
                endif;
            }
        endif;

        // Load overrides
        if (!empty($query_params)):
            foreach ($query_params as $name => $value) {
                if (!empty($value)):
                    $new_query_params[$name] = $value;
                endif;
            }
        endif;

        // Construct GET request
        if (!empty($new_query_params)):
            foreach ($new_query_params as $name => $value) {
                if (!empty($value)):
                    $get .= $name . "=" . $value . "&";
                endif;
            }
        endif;

        return $get;

    }
    
    function displayNavigationMenu( $menu_name, $addState = true, $options = [] ) {
        
        if ( $menu_name ) {
            
            $defaults = [
                
                'class'                 => 'navigation',
                'container_element'     => 'nav'
            ];
            
            $options = \OWA\Core\Lib::setDefaultParams( $defaults, $options );
            
            $nav = \OWA\Core\CoreAPI::getGroupNavigation( $menu_name );
            
            if ( $nav ) {
                
                $items = $this->makeNavigation( $nav, $menu_name . '_menu', $class );
                
                $menu = sprintf( '<%s class="%s">%s</%s>', $options['container_element'], $options['class'], $items, $options['container_element'] );
                
                $this->out( $menu, false );
                
            } else {
                
                $this->out('There is no menu by that name.');
            }
            
            $this->out( $menu );
        }
    }

    /**
     * Makes navigation links by checking whether or not the view
     * that is rendering the template is not the view being refered to in the link.
     *
     * @param array navigation array
     */
    /**
     * The link parameters a navigation entry points at.
     *
     * A `ref` was always a bare action name, which stopped being enough when
     * every report started sharing one: `base.report` identifies the
     * dispatcher, not the report. A ref may now be the whole parameter map
     * instead -- array('do' => 'base.report', 'reportId' => 'pages') -- and a
     * plain string still means what it always meant.
     *
     * @param array $link a nav link struct
     * @return array parameters for makeLink()
     */
    public function navLinkParams( $link ) {

        $ref = isset( $link['ref'] ) ? $link['ref'] : '';

        return is_array( $ref ) ? $ref : array( 'do' => $ref );
    }

    /**
     * Whether a navigation entry is the page being looked at.
     *
     * Comparing `ref` against params['do'] used to be enough. It is not any
     * more: every report answers to do=base.report, so that test now says yes
     * to EVERY report link at once and the whole Reports menu highlights.
     *
     * Every parameter the ref names has to match, which for a report means the
     * reportId as well as the action.
     *
     * @param array $link a nav link struct
     * @param array $params the current request parameters
     * @return bool
     */
    public function navLinkIsCurrent( $link, $params ) {

        $wanted = $this->navLinkParams( $link );

        if ( empty( $wanted['do'] ) ) {

            return false;
        }

        foreach ( $wanted as $key => $value ) {

            if ( ! isset( $params[ $key ] ) || (string) $params[ $key ] !== (string) $value ) {

                return false;
            }
        }

        return true;
    }

    function makeNavigation($nav, $id = '', $class = '', $li_template = '<LI class="%s"><a href="%s">%s</a></LI>', $li_class = '') {

        $ul = sprintf('<UL id="%s" class="%s">', $id, $class);

        if ( ! empty( $nav ) ) {

            $navigation = $ul;

            foreach($nav as $k => $v) {

                $navigation .= sprintf($li_template, $li_class, $this->makeLink($this->navLinkParams($v), true), $v['anchortext']);
            }

            $navigation .= '</UL>';

            return $navigation;
        }
    }

    function makeTwoLevelNav($links) {
        
        $navigation = '<UL id="report_top_level_nav_ul">';

        foreach($links as $k => $v) {

            if (!empty($v['subgroup'])):
                $sub_nav = $this->makeNavigation($v['subgroup']);

                $navigation .= sprintf('<LI class="drawer"><H2 class="nav_header"><a href="%s">%s</a></H2>%s</LI>',
                                                $this->makeLink($this->navLinkParams($v), true),
                                                $v['anchortext'], $sub_nav);
            else:

                $navigation .= sprintf('<LI class="drawer"><H2 class="nav_header"><a href="%s">%s</a></H2></LI>',
                                                $this->makeLink($this->navLinkParams($v), true),
                                                $v['anchortext']);

            endif;

        }

        $navigation .= '</UL>';

        return $navigation;

    }

    function daysAgo($time) {

        $now = mktime(23, 59, 59, $this->time_now['month'], $this->time_now['day'], $this->time_now['year']);

        $days = round(($now - $time) / (3600*24));

        switch ($days) {

            case 1:
                return $days . " day ago";

            default:
                return $days . " days ago";
        }

    }

    /**
     * @depricated
     * @todo remove
     */
    function getAuthStatus() {


        $auth = &\OWA\Core\Auth::get_instance();
        return $auth->auth_status;
    }

    function makeWikiLink( $page ) {

        return sprintf( '%s/%s', $this->config['wiki_url'], $page );
    }

    /**
     * Returns Namespace value to template
     *
     * @return string
     */
    /**
     * The config-file constant governing a setting, or '' if none is.
     *
     * A settings field whose value comes from owa-config.php must render
     * read-only and say which constant is responsible -- a constant beats the
     * stored value (see Settings::stripSettingsSuppliedByConstants), so an
     * editable field would accept a change that never takes effect. Naming the
     * constant is what makes that actionable: "set in owa-config.php" leaves the
     * operator hunting.
     *
     * @param string $module
     * @param string $key
     * @return string
     */
    function configFileConstantFor( $module, $key ) {

        return \OWA\Core\CoreAPI::configSingleton()->configFileConstantFor( $module, $key );
    }

    function getNs() {

        return \OWA\Core\CoreAPI::appNs();
    }

    function makeParamString($params = array(), $add_state = false, $format = 'query', $namespace = true) {

        $all_params = array();

        // merge in state params
        if ($add_state) {
            $all_params = array_merge($all_params, $this->getAllStateParams());
        }
        //merge in params
        $all_params = array_merge($all_params, $params);

        switch($format) {

            case 'query':

                $get = '';

                $count = count($all_params);

                $i = 0;

                foreach ($all_params as $n => $v) {

                    $get .= $this->getNs().$n.'='.$v;

                    $i++;

                    if ($i < $count):
                        $get .= "&";
                    endif;
                }

                $string= $get;

                break;

            case 'cookie':

                $string = \OWA\Core\Lib::implode_assoc('=>', '|||', $all_params);
                break;

            case 'json':

                $string = json_encode( $all_params );

                break;
        }


        return $string;

    }

    function getAllStateParams() {

        $all_params = array();

        if (!empty($this->caller_params['link_state'])) {
            $all_params = array_merge($all_params, $this->caller_params['link_state']);
        }

        // add in period properties if available
        $period = $this->get('timePeriod');

        if (!empty($period)) {
            $all_params = array_merge($all_params, $period->getPeriodProperties());
            //print_r($all_params);
        }

        return $all_params;
    }
    
    function getLinkStateParam( $key ) {
	 
	    $params = $this->getAllStateParams();
	    
	    if (array_key_exists($key, $params)) {
		    
		   return $params[ $key ];		
	    }

    }


    /**
     * Makes Links, adds state to links optionaly.
     *
     * @param array $params
     * @param boolean $add_state
     * @return string
     */
    function makeLink($params = array(), $add_state = false, $url = '', $xml = false, $add_nonce = false) {

        $all_params = array();

        //Loads link state passed by caller
        if ($add_state == true) {
            if (!empty($this->caller_params['link_state'])) {
                $all_params = array_merge($all_params, $this->caller_params['link_state']);
            }

            // add in period properties if available
            $period = $this->get('timePeriod');

            if (!empty($period)) {
                $all_params = array_merge($all_params, $period->getPeriodProperties());

            }
        }

        // Load overrides
        if (!empty($params)) {
            $params = array_filter($params);
            $all_params = array_merge($all_params, $params);
        }

        // add nonce if called for
        if ($add_nonce) {
            if ( array_key_exists('do', $all_params) ) {
                $action = $all_params['do'];
            } elseif ( array_key_exists('action', $all_params) ) {
                $action = $all_params['action'];
            }

            $all_params['nonce'] = \OWA\Core\CoreAPI::createNonce($action);
        }

        $get = '';

        if (!empty($all_params)):

            $count = count($all_params);

            $i = 0;

            foreach ($all_params as $n => $v) {

                $get .= $this->getNs().\OWA\Module\Base\Classes\Sanitize::escapeForDisplay($n).'='.\OWA\Module\Base\Classes\Sanitize::escapeForDisplay($v);

                $i++;

                if ($i < $count):
                    $get .= "&";
                endif;
            }
        endif;

        if (empty($url)):
            $url = $this->config['main_url'];
        endif;

        $link = sprintf($this->config['link_template'], $url, $get);

        if ($xml == true):
            $link = $this->escapeForXml($link);
        endif;

        return $link;

    }

    function escapeForXml($string) {

        $string = str_replace(array('&', '"', "'", '<', '>' ), array('&amp;' , '&quot;', '&apos;' , '&lt;' , '&gt;'), $string);
        // removes non-ascii chars
        $string = \OWA\Core\Lib::escapeNonAsciiChars($string);
        return $string;
    }

    function makeAbsoluteLink($params = array(), $add_state = false, $url = '', $xml = false) {

        if (empty($url)):
            $url = $this->config['main_absolute_url'];
        endif;

        return $this->makeLink($params, $add_state, $url, $xml);

    }
    
    function getApiKey() {
	    
		return \OWA\Core\CoreAPI::getCurrentUserApiKey();
    }

    /**
     * An API link for the heatmap overlay or the domstream player.
     *
     * These two are the only cross-origin API consumers: they run on the
     * *tracked* site and call back to the OWA origin. They used to be built
     * with makeApiLink( ..., $add_apiKey = true ), which put the signed-in
     * user's long-lived apiKey into a URL that the tracker then wrote to a
     * cookie on the tracked site's own domain -- readable by every other script
     * on that page, and re-sent to that site on every subsequent request.
     *
     * They now carry a token scoped to one endpoint and one resource that
     * expires in minutes, so what leaks is worth almost nothing. No signature:
     * request signing exists to make a long-lived key survivable in a URL, and
     * there is no longer a long-lived key here.
     *
     * **The full API URL must be built here and handed to the overlay. Do not
     * be tempted to send only the token and let the tracker derive the URL.**
     *
     * It looks redundant -- the token already names its action and resource,
     * and the tracker has a base URL -- but the tracker's base URL is where it
     * *logs*, which need not be where reporting lives. OWA supports split
     * deployment deliberately: setEndpoint(), setLoggerEndpoint() and
     * setApiEndpoint() are independently settable, the RemoteQueue module
     * exists so a node can receive events somewhere other than the reporting
     * install, and OWA_USE_STATIC_CONFIG_ONLY exists for a logging-only node
     * that never touches the reporting database. The admin interface can be on
     * an entirely different domain from the collector.
     *
     * The reporting origin is the only party that knows where reporting lives,
     * and it is already the party minting this link, so the API URL and the
     * token both travel from here. Deriving it client-side works on a
     * single-box install and fails silently on exactly the deployments that
     * most need it right, by fetching from a host that never answers.
     *
     * @param    array    $params        the API request params
     * @param    string    $resourceKey    which param names the resource
     * @return    string
     */
    function makeOverlayApiLink( $params, $resourceKey ) {

        $cu = \OWA\Core\CoreAPI::getCurrentUser();

        $params['overlayToken'] = \OWA\Core\OverlayToken::mint(
            $cu->getUserData('user_id'),
            $params['do'],
            $resourceKey,
            $params[ $resourceKey ]
        );

        // add_state MUST be true. It is what carries siteId and the reporting
        // period onto the link, and both overlay call sites used
        // makeApiLink( $params, true, true ) before this method existed.
        //
        // Dropping it fails asymmetrically, which is why it is worth a comment:
        // the player's controller declares siteId required and answers a clean
        // 422, but the heatmap's reports route does not, so it returns 200 for a
        // query with no site filter and a defaulted period -- the wrong clicks,
        // reported as success. Only the cross-origin e2e caught that half.
        return $this->makeLink( $params, true, $this->config['rest_api_url'] );
    }

    function makeApiLink($params = array(), $add_state = false, $add_apiKey = false) {

        $url = $this->config['rest_api_url'];
        
        if ( $add_apiKey ) {
	        
	        $params['apiKey'] = $this->getApiKey();
            
        } else {
            
            $params['nonce'] = \OWA\Core\CoreAPI::createRestApiNonce( $params['version'], $params['module'], $params['do'] );
        }
      
        $link = $this->makeLink($params, $add_state, $url);
        
        if ( $add_apiKey ) {
	     	
	    	return $this->signRequestUrl( $link, $this->getApiKey() );
	    	
	    } else {
        
        	return $link;
        }
    }
    
    function signRequestUrl( $url, $apiKey ) {
	    
	    return \OWA\Core\CoreAPI::signRequestUrl( $url, $apiKey );
    }


    function makeImageLink($path, $absolute = false) {

        // Server-rendered images live in the public/ asset tree (the build copies
        // base/i/ -> public/base/i/), NOT the module source tree -- modules_url is
        // denied by the deny-all .htaccess. images_url/images_absolute_url both
        // resolve to public/ (settings.php setupPaths()).
        if ($absolute === true) {
            $url = \OWA\Core\CoreAPI::getSetting('base', 'images_absolute_url');
        } else {
            $url = \OWA\Core\CoreAPI::getSetting('base', 'images_url');
        }

        return $url.$path;

    }

    function includeTemplate($file) {

        $this->set_template($file);
        include($this->file);
        return;

    }

    function setTemplate($file) {

        $this->set_template($file);
        return $this->file;

    }

    function getWidget($do, $params = array(), $wrapper = true, $add_state = true) {

        $final_params = array();

        if (empty($params)):
            $params = array();
        endif;

        $params['do'] = $do;

        if ($wrapper === true):
            $params['initial_view'] = true;
            $params['wrapper'] = true;
        elseif ($wrapper === 'inpage'):
            $params['initial_view'] = true;
            $params['wrapper'] = 'inpage';
        else:
            $params['wrapper'] = false;
        endif;

        // add state params into request params
        //
        // The key is checked, not just $add_state. A caller asking for state on
        // a template that was never given any read an undefined key -- and
        // array_merge()'s second argument being null is a TypeError on PHP 8,
        // so this was a fatal waiting for the first such caller, not a notice.
        if ($add_state === true && ! empty($this->caller_params['link_state'])):
            $final_params = array_merge($final_params, $this->caller_params['link_state']);
        endif;

        // apply overides made via the template
        $final_params = array_merge($final_params, array_filter($params));

        return \OWA\Core\CoreAPI::performAction($do, $final_params);
    }

    function makeJson($array) {

        $reserved_words = \OWA\Core\CoreAPI::getSetting('base', 'reserved_words');

        $json = '{';

        foreach ($array as $k => $v) {

            if (is_object($v)) {
                if (method_exists($v, 'toString')) {
                    $v = $v->toString();
                } else {
                    $v = '';
                }

            }

            if (in_array($k, array_keys($reserved_words))) {
                $k = $reserved_words[$k];
            }
            
            $json .= sprintf('%s: "%s", ', $k, \OWA\Module\Base\Classes\Sanitize::escapeForDisplay( $v ) ) ;

        }


        $json = substr($json, 0, -2);

        $json .= '}';

        return $json;

    }

    function headerActions() {

        return;
    }

    function footerActions() {

        return;
    }

    function makePagination($pagination, $map = array(), $add_state = true, $template = '') {

        $pages = '';
        //print_r($pagination);
        if ($pagination['max_page_num'] > 1) {

            $pages = '<div class="owa_pagination"><UL>';

            for ($i = 1; $i <= $pagination['max_page_num'];$i++) {

                if ($pagination['page_num'] != $i) {
                    $new_map = array();
                    $new_map = $map;
                    $new_map['page'] = $i;
                    $link = sprintf('<LI class="owa_reportPaginationControl"><a href="%s">%s</a></LI>',
                                                        $this->makeLink($new_map, $add_state),
                                                        $i);

                } else {

                    $link = sprintf('<LI class="owa_reportPaginationControl">%s</LI>', $i);
                }

                $pages .= $link;
            }

            $pages .= '</UL></div>';
            $pages .= '<div style="clear:both;"></div>';
        }

        return $pages;
    }

    function makePaginationFromResultSet($pagination, $map = array(), $add_state = true, $template = '') {

        $pages = '';
        //print_r($pagination);
        //print $pagination->total_pages;

        if ($pagination->total_pages > 1) {

            $pages = '<div class="owa_pagination"><UL>';

            for ($i = 1; $i <= $pagination->total_pages;$i++) {

                if ($pagination->page != $i) {

                    $new_map = array();

                    if (is_array($map)) {
                        $new_map = array_merge($map, $new_map);
                    }

                    $new_map['page'] = $i;

                    $link = sprintf('<LI class="owa_reportPaginationControl"><a href="%s">%s</a></LI>',
                                                        $this->makeLink($new_map, $add_state),
                                                        $i);

                } else {

                    $link = sprintf('<LI class="owa_reportPaginationControl">%s</LI>', $i);
                }

                $pages .= $link;
            }

            $pages .= '</UL></div>';



        }

        return $pages;
    }

    function get($name) {

        if (array_key_exists($name, $this->vars)) {
            return $this->vars[$name];
        } else {
            return false;
        }

    }

    function getValue( $key, $var) {

        if ( isset( $var ) && is_array( $var ) ) {
            if ( array_key_exists( $key, $var) ) {
                return $var[$key];
            }
        }
    }

    function substituteValue($string, $var_name) {

        $value = $this->get($var_name);

        if ($value) {

            return sprintf($string,$value);
        } else {
            
            return '';
        }
    }

    /**
     * @param array        $links
     * @param string       $currentSiteId
     * @param array|string $current  the request's params, or just its action
     */
    function makeNavigationMenu($links, $currentSiteId, $current = '') {

        if (!empty($links) && !empty($currentSiteId)) {

            /*
             * The WHOLE request, not just its action.
             *
             * Every report answers to do=base.report now, so the action alone no
             * longer says which report is being looked at -- navLinkIsCurrent
             * compares a link's full ref, and a report link carries a reportId
             * the action cannot supply. Passing only the action meant no report
             * link was ever current, which read as two separate bugs: nothing
             * highlighted, and the left nav collapsing on every page load
             * (.owa_admin_nav_subgroup is display:none until the script opens
             * the group holding .owa_current, and there was never one).
             *
             * A string is still accepted, because that is what this took for
             * fifteen years and a third-party template may still pass one.
             */
            $params = is_array( $current ) ? $current : array( 'do' => $current );

            $t = new \OWA\Core\Template;
            $t->set('links', $links);
            $t->set('currentSiteId', $currentSiteId);
			$t->set('params', $params);
            // Only when there is some: the nav is built on screens that carry no
            // link state, and propagating an absent key is an undefined-key
            // warning -- which CI treats as a failure.
            if ( isset( $this->caller_params['link_state'] ) ) {

                $t->caller_params['link_state'] = $this->caller_params['link_state'];
            }

            $t->set_template('report_nav.php');
            return $t->fetch();
        } else {

            return false;
        }

    }

    function displaySparkline($id, $data, $width = '100px', $height = '35px') {

        if (!empty($data)) {

            $data_string = implode(',', $data);

            $t = new \OWA\Core\Template;
            $t->set('dom_id', $id.'Sparkline');
            $t->set('data', $data_string);
            $t->set('width', $width);
            $t->set('height', $height);
            $t->set_template('sparkline_dom.php');
            return $t->fetch();

        } else {

            return false;
        }
    }

    function displaySeriesAsSparkline($name, $result_set_obj, $id = '') {

        if (!$id) {
            $id = rand();
        }

        $series = $result_set_obj->getSeries($name);

        if ($series) {
            echo $this->displaySparkline($id, $series);
        }
    }

    function makeTable($labels, $data, $table_class = '', $table_id = '', $is_sortable = true) {

        $t = new \OWA\Core\Template;

        if (!empty($table_id)) {
            $id = rand();
        }

        $t->set('table_id', $id.'Table');
        $t->set('data', $data);
        $t->set('labels', $labels);
        $t->set('class', $table_class);
        if ($is_sortable === true) {
            $t->set('sort_table_class', 'tablesorter');
        }

        $t->set_template('generic_table.php');

        return $t->fetch();

    }

    function subTemplate($template_name = '', $map = array(), $linkstate = array()) {

        $t = new \OWA\Core\Template;

        $t->set_template($template_name);

        foreach ($map as $k => $v) {

            $t->set($k, $v);
        }

        return $t->fetch();

    }

    function formatNumber($num, $decimal_places) {

        return number_format($num, $decimal_places,'.',',');
    }

    function getAvatarImage($email) {

        if (false != $email && $email !== '(not set)') {
            return sprintf("https://www.gravatar.com/avatar/%s?s=30", md5($email));
        }
    }

    function displayMetricInfobox($params = array()) {

        $t = new \OWA\Core\Template;

        if (!empty($dom_id)) {
            $dom_id = rand();
        }
        $params['do'] = 'getResultSet';
        $count = \OWA\Core\CoreAPI::executeApiCommand($params);
        $params['period'] = 'last_thirty_days';
        $params['dimensions'] = 'date';
        $trend = \OWA\Core\CoreAPI::executeApiCommand($params);
        $t->set('metric_name', $params['metrics']);
        $t->set('dom_id', $dom_id);
        $t->set('count', $count);
        $t->set('trend', $trend);
        $t->set_template('metricInfobox.php');

        return $t->fetch();

    }

    public function renderKpiInfobox($number, $label, $link = '', $class = '') {

        $t = new \OWA\Core\Template;
        $t->set_template( 'kpiInfobox.php' );
        $t->set( 'number', $number );
        $t->set( 'label', $label );

        if ($link) {
            $t->set( 'link', $link );
        }

        if ($class) {
            $t->set( 'class', $class );
        }

        echo $t->fetch();

    }

    function renderDimension($template, $properties) {

        $t = new \OWA\Core\Template;
        $t->set('properties', $properties);
        $t->set_template($template);
        return $t->fetch();
    }

    /**
     * Creates a hidden nonce form field
     *
     * @param     string    $action the action that the nonce should be tied to.
     * @return    string The html fragment
     */
    function createNonceFormField($action) {

        return sprintf(
                '<input type="hidden" name="%snonce" value="%s">',
                $this->getNs(),
                \OWA\Core\CoreAPI::createNonce($action));
    }

    function makeNonceLink() {

    }

    /**
     * Outputs data into the template
     *
     * @param    string    $output        The String to be output into the template
     * @param    bool    $sanitize    Flag that will sanitize the output for display
     */
    function out($output, $sanitize = true, $decode_special_entities = false) {

        if ( $sanitize ) {
            $output = \OWA\Module\Base\Classes\Sanitize::escapeForDisplay($output);

            if ( $decode_special_entities ) {
                $output = strtr($output, array('&amp;'  => '&'));
            }

        }

        echo $output;
    }

    /**
     * Safely emit a tracker-sourced URL into an href/src attribute.
     *
     * escapeForDisplay() (what out() uses) neutralizes attribute breakout
     * ("><script) but does NOT neutralize a scheme-based payload: a stored
     * value of  javascript:alert(1)  or  data:text/html,...  survives
     * htmlentities() unchanged and stays a live scheme in an href. Stored
     * URLs are attacker-controllable (set by the tracker) and are NOT
     * re-escaped by makeUrlCanonical(), so any template that drops a stored
     * URL straight into href="" needs a scheme check on top of escaping.
     *
     * This permits only http/https/mailto/ftp (and scheme-relative "//" and
     * root-relative "/" URLs); anything else is replaced with '#'. The
     * returned value is then escaped exactly like out() for the breakout case.
     *
     * @param string $url  the URL to emit
     * @param bool   $echo when true (default) echo the value, else return it
     */
    function safeHref( $url, $echo = true ) {

        $safe = \OWA\Module\Base\Classes\Sanitize::sanitizeHref( $url );
        $safe = \OWA\Module\Base\Classes\Sanitize::escapeForDisplay( $safe );

        if ( $echo ) {
            echo $safe;
        }

        return $safe;
    }

    function formatCurrency($value) {
        return \OWA\Core\Lib::formatCurrency( $value, \OWA\Core\CoreAPI::getSetting( 'base', 'currencyLocal' ), \OWA\Core\CoreAPI::getSetting( 'base', 'currencyISO3' ) );
    }

    function getCurrentUser() {

        return \OWA\Core\CoreAPI::getCurrentUser();
    }

    public function getSiteThumbnail( $domain, $width = '200' ) {

        echo sprintf('<img src="https://s.wordpress.com/mshots/v1/%s?w=%s" width="%s">', urlencode($domain .'/'), $width, $width );
    }

    /**
     * Checks is a display value is set.
     */
    public function isValueSet( $string ) {

        if ($string === '(not set)' || empty( $string ) ) {

            return false;

        } else {

            return true;
        }
    }
}


?>
