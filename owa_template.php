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

if (!class_exists('Template')) {
    require_once(OWA_INCLUDE_DIR.'template_class.php');
}

if (!class_exists('owa_lib')) {
    require_once(OWA_BASE_DIR.'/owa_lib.php');
}

if (!class_exists('owa_sanitize')) {
    require_once(OWA_BASE_CLASS_DIR.'sanitize.php');
}

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

class owa_template extends Template {

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

    /**
     * Params passed by calling caller
     *
     * @var array
     */
    var $caller_params;

    function __construct( $module = null, $caller_params = array() ) {

        $this->caller_params = $caller_params;

        $c = owa_coreAPI::configSingleton();
        $this->config = $c->fetch('base');

        $this->e = owa_coreAPI::errorSingleton();

        // set template dirs
        if(!empty($caller_params['module'])):
            $this->_setTemplateDir($module);
        else:
            $this->_setTemplateDir('base');
        endif;

        $this->time_now = owa_lib::time_now();
    }

    function _setTemplateDir($module) {

        // set module template dir
        $this->module_template_dir = OWA_DIR.'modules'.'/' . $module . '/'.'templates'.'/';

        // set module local template override dir
        $this->module_local_template_dir = $this->module_template_dir.'local'.'/';

        // set theme template dir
        $this->theme_template_dir = OWA_THEMES_DIR.$this->config['theme'].'/';

        return;
    }

    function getTemplatePath($module, $file) {

        $this->_setTemplateDir($module);

        if ($file == null) {
            owa_coreAPI::error('No template file was specified.');
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
            owa_coreAPI::error('No template file was specified.');
            return false;
        else:
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

      return owa_lib::truncate ($str, $length, $trailing); 
    }

    function get_month_label($month) {

        return owa_lib::get_month_label($month);
    }

    /**
     * Chooses the right icon based on browser type
     *
     * @param unknown_type $browser_type
     * @return unknown
     */
    function choose_browser_icon($browser_type) {
		
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


        if (file_exists(OWA_MODULES_DIR.$module.'/i/browsers/'.$size.'/'.$browser_family.'.png')) {
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

    /**
     * Makes navigation links by checking whether or not the view
     * that is rendering the template is not the view being refered to in the link.
     *
     * @param array navigation array
     */
    function makeNavigation($nav, $id = '', $class = '', $li_template = '<LI class="%s"><a href="%s">%s</a></LI>', $li_class = '') {

        $ul = sprintf('<UL id="%s" class="%s">', $id, $class);

        if (!empty($nav)):

            $navigation = $ul;

            foreach($nav as $k => $v) {

                $navigation .= sprintf($li_template, $li_class,    $this->makeLink(array('do' => $v['ref']), true), $v['anchortext']);

            }

            $navigation .= '</UL>';

            return $navigation;
        else:
            return false;
        endif;

    }

    function makeTwoLevelNav($links) {
        print_r($links);
        $navigation = '<UL id="report_top_level_nav_ul">';

        foreach($links as $k => $v) {

            if (!empty($v['subgroup'])):
                $sub_nav = $this->makeNavigation($v['subgroup']);

                $navigation .= sprintf('<LI class="drawer"><H2 class="nav_header"><a href="%s">%s</a></H2>%s</LI>',
                                                $this->makeLink(array('do' => $v['ref']), true),
                                                $v['anchortext'], $sub_nav);
            else:

                $navigation .= sprintf('<LI class="drawer"><H2 class="nav_header"><a href="%s">%s</a></H2></LI>',
                                                $this->makeLink(array('do' => $v['ref']), true),
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

        if (!class_exists('owa_auth')) {
            require_once(OWA_BASE_DIR.'/owa_auth.php');
        }

        $auth = &owa_auth::get_instance();
        return $auth->auth_status;
    }

    function makeWikiLink($page) {

        return sprintf($this->config['owa_wiki_link_template'], $page);
    }

    /**
     * Returns Namespace value to template
     *
     * @return string
     */
    function getNs() {

        return $this->config['ns'];
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

                    $get .= owa_coreAPI::getSetting('base','ns').$n.'='.$v;

                    $i++;

                    if ($i < $count):
                        $get .= "&";
                    endif;
                }

                $string= $get;

                break;

            case 'cookie':

                $string = owa_lib::implode_assoc('=>', '|||', $all_params);
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

            $all_params['nonce'] = owa_coreAPI::createNonce($action);
        }

        $get = '';

        if (!empty($all_params)):

            $count = count($all_params);

            $i = 0;

            foreach ($all_params as $n => $v) {

                $get .= $this->config['ns'].owa_sanitize::escapeForDisplay($n).'='.owa_sanitize::escapeForDisplay($v);

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
        $string = owa_lib::escapeNonAsciiChars($string);
        return $string;
    }

    function makeAbsoluteLink($params = array(), $add_state = false, $url = '', $xml = false) {

        if (empty($url)):
            $url = $this->config['main_absolute_url'];
        endif;

        return $this->makeLink($params, $add_state, $url, $xml);

    }
    
    function getApiKey() {
	    
		return owa_coreAPI::getCurrentUserApiKey();
    }

    function makeApiLink($params = array(), $add_state = false, $add_apiKey = false) {

        $url = $this->config['rest_api_url'];
        
        if ( $add_apiKey ) {
	        
	        $params['apiKey'] = $this->getApiKey();
        }
      
        $link = $this->makeLink($params, $add_state, $url);
        
        if ( $add_apiKey ) {
	     	
	    	return $this->signRequestUrl( $link, $this->getApiKey() );
	    	
	    } else {
        
        	return $link;
        }
    }
    
    function signRequestUrl( $url, $apiKey ) {
	    
	    return owa_coreAPI::signRequestUrl( $url, $apiKey );
    }


    function makeImageLink($path, $absolute = false) {

        if ($absolute === true) {
            $url = owa_coreAPI::getSetting('base', 'modules_url');
        } else {
            $url = owa_coreAPI::getSetting('base', 'modules_url');
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
        if ($add_state === true):
            $final_params = array_merge($final_params, $this->caller_params['link_state']);
        endif;

        // apply overides made via the template
        $final_params = array_merge($final_params, array_filter($params));

        return owa_coreAPI::performAction($do, $final_params);
    }

    function makeJson($array) {

        $reserved_words = owa_coreAPI::getSetting('base', 'reserved_words');

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

            $json .= sprintf('%s: "%s", ', $k, $v);

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
        }
    }

    function makeNavigationMenu($links, $currentSiteId, $current_action = '') {

        if (!empty($links) && !empty($currentSiteId)) {

            $t = new owa_template;
            $t->set('links', $links);
            $t->set('currentSiteId', $currentSiteId);
			$t->set('params', array('do' => $current_action ));
            $t->caller_params['link_state'] = $this->caller_params['link_state'];
            $t->set_template('report_nav.tpl');
            return $t->fetch();
        } else {

            return false;
        }

    }

    function displayChart($id, $data, $width = '100%', $height = '200px') {

        if (!empty($data)) {

            $t = new owa_template;
            $t->set('dom_id', $id.'Chart');
            $t->set('data', $data);
            $t->set('width', $width);
            $t->set('height', $height);
            $t->set_template('chart_dom.tpl');
            return $t->fetch();
        } else {

            return false;
        }
    }

    function displaySparkline($id, $data, $width = '100px', $height = '35px') {

        if (!empty($data)) {

            $data_string = implode(',', $data);

            $t = new owa_template;
            $t->set('dom_id', $id.'Sparkline');
            $t->set('data', $data_string);
            $t->set('width', $width);
            $t->set('height', $height);
            $t->set_template('sparkline_dom.tpl');
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

        $t = new owa_template;

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

        $t->set_template('generic_table.tpl');

        return $t->fetch();

    }

    function subTemplate($template_name = '', $map = array(), $linkstate = array()) {

        $t = new owa_template;

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

        $t = new owa_template;

        if (!empty($dom_id)) {
            $dom_id = rand();
        }
        $params['do'] = 'getResultSet';
        $count = owa_coreAPI::executeApiCommand($params);
        $params['period'] = 'last_thirty_days';
        $params['dimensions'] = 'date';
        $trend = owa_coreAPI::executeApiCommand($params);
        $t->set('metric_name', $params['metrics']);
        $t->set('dom_id', $dom_id);
        $t->set('count', $count);
        $t->set('trend', $trend);
        $t->set_template('metricInfobox.php');

        return $t->fetch();

    }

    public function renderKpiInfobox($number, $label, $link = '', $class = '') {

        $t = new owa_template;
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

        $t = new owa_template;
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
                owa_coreAPI::getSetting('base', 'ns'),
                owa_coreAPI::createNonce($action));
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
            $output = owa_sanitize::escapeForDisplay($output);

            if ( $decode_special_entities ) {
                $output = strtr($output, array('&amp;'  => '&'));
            }

        }

        echo $output;
    }

    function formatCurrency($value) {
        return owa_lib::formatCurrency( $value, owa_coreAPI::getSetting( 'base', 'currencyLocal' ), owa_coreAPI::getSetting( 'base', 'currencyISO3' ) );
    }

    function getCurrentUser() {

        return owa_coreAPI::getCurrentUser();
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