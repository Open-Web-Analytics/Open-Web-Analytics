<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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

require_once('owa_env.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_php.php');

/**
 * OWA Singleton.
 *
 * Creates instance of OWA that can be called from within Gallery.
 * All configuration taken from Gallery directly.
 */
function owa_factory($params = array()) {
	
	static $owa;

	if(!empty($owa)):
		return $owa;
	else:
	
		// globals
		global $gallery;
		
		// init the configuration array for this caller
		$owa_config = $params;
		
		// OWA DATABASE CONFIGURATION 
		// Will use Gallery config unless there is a config file present.
		// OWA uses this to setup it's own DB connection seperate from the one
		// that Gallery uses.
			
		//$gallery_base_url = $gallery->getConfig('galleryBaseUrl');
		$urlgenerator = $gallery->getUrlGenerator();
		$gallery_base_url = $urlgenerator->getCurrentUrlDir();
		
		// Gallery specific config overrides array
		
		$owa_config['report_wrapper'] = 'wrapper_gallery2.tpl';
		$owa_config['images_url'] = OWA_PUBLIC_URL.'i/';
		$owa_config['images_absolute_url'] = $owa_config['images_url'];
		$owa_config['main_url'] = $gallery_base_url.'main.php?g2_view=core.SiteAdmin&g2_subView=owa.owaGeneric';
		$owa_config['main_absolute_url'] = $owa_config['main_url'];
		$owa_config['action_url'] = $gallery_base_url.'main.php?g2_view=owa.owaAction&owa_specialAction';
		$owa_config['log_url'] = $gallery_base_url.'main.php?g2_view=owa.owaAction&owa_logAction=1';
		$owa_config['link_template'] = '%s&%s';
		//$owa_config['authentication'] = 'gallery';
		$owa_config['site_id'] = md5($gallery_base_url);
		$owa_config['query_string_filters'] = 'g2_fromNavId';
		$owa_config['is_embedded'] = 'true';
		
		$gallery->debug('hello from gallery owa plugin');

		// create owa instance
		$owa = new owa_php($owa_config);
		$gallery->debug('new owa instance created');
		
		return $owa;
		
	endif;

}

/**
 * Sets OWA priviledge info for current gallery user 
 */
function owa_set_priviledges() {

	global $gallery;
	
	// get Gallery's active user
	$u = $gallery->getActiveUser();
	
	// create instance of OWA
	$owa = owa_factory();
	
	//set user level. Needed for OWA's auth module. 
	
	// check to see if user is a guest or not
	list ($ret, $user) = GalleryCoreApi::isAnonymousUser();
	
	if ($user == true):
	
		$level = 'everyone';
		
	else:
		// check to see if the user is a site admin. important becasue we might not want
		// to log such users activities.
		list ($ret, $admin) = GalleryCoreApi::isUserInSiteAdminGroup();
	
		if ($admin = true):
			$level = 'admin';
		else:
			$level = 'viewer'; 
		endif;
		
	endif;		

	// preemptively set the current user info and mark as authenticated so that
	// downstream controllers don't have to authenticate
	$cu =&owa_coreAPI::getCurrentUser();
	
	// gallery gives all users a username of guest if there are not named users...
	if ($u->userName != 'guest'):
		$cu->setUserData('user_id', $u->userName);
		$cu->setUserData('email_address', $u->email);
		$cu->setUserData('real_name', $u->fullName);
	endif;
	
	$cu->setRole($level);
	$cu->setAuthStatus(true);
		
	return;
}

/**
 * OWA Gallery Module
 *
 * Integrates OWA with Gallery 2.2 or later
 *
 * @package owa
 * @author Peter Adams <peter@openwebanalytics.com>
 * @version $Revision$ $Date: $
 */
class owaModule extends GalleryModule {

	function owaModule() {
        global $gallery;

        $this->setId('owa');
        $this->setName($gallery->i18n('Open Web Analytics for Gallery'));
        $this->setDescription($gallery->i18n('Adds web analytics capabilities to Gallery.'));
        $this->setVersion('1.0.0');
        $this->setGroup('OWA', $gallery->i18n('Open Web Analytics'));
        $this->setRequiredCoreApi(array(7, 18));
        $this->setRequiredModuleApi(array(3, 4));
        $this->setCallbacks('getSiteAdminViews|getSystemLinks');
        
        return;
    }
    
    
	/**
	 * Main OWA logging method
	 * 
	 * Using getSystemLinks as a callback because it is called on every request.
	 */
	function getSystemLinks() {
		
		global $gallery;
		
		
    	if (GalleryUtilities::hasRequestVariable('view')):
    		$viewName = GalleryUtilities::getRequestVariables('view');
    		
    		
    		// ensure this is not a Gallery admin screen
    		if ($viewName == "core.SiteAdmin" || $viewName == "core.ItemAdmin"):
    			return;
    		else:
    			
    			// get instance of owa
				$owa = owa_factory();
				
				// set user priviledges of the request for OWA to log
				owa_set_priviledges();
		
				// Setup OWA request params
				$params = array();
					
				// get information on current view	
				list ($ret, $view) = GalleryView::loadView($viewName);
				list ($ret, $page_type) = $view->getViewDescription();
				$params['page_type'] = $page_type;
		
				//Log request is for an item, get item details
				if (GalleryUtilities::hasRequestVariable('itemId')):
					//Lookup item from view
					list ($rest, $item) = $view->getItem();
					$params['page_title'] = $item->title;
				else:
					$params['page_title'] = $page_type;   			
				endif;
				
				// is RSS page type
				
				if (($viewName == "rss.Render") || ($viewName == "rss.SimpleRender")):
					$params['page_type'] = 'feed';
					$params['is_feedreader'] = true;
					$params['feed_format'] = $_GET['feed'];
				endif;
						
				// log request
				
				//print_r($owa->config);
				
				$owa->log($params);
			endif;	
		endif;
				
		return;
	
	}
	
	
	/**
	 * Check to see if OWA is installed and activated
	 *
	 */
	function owa_isActivated() {
	
		list ($ret, $params) = GalleryCoreApi::fetchAllPluginParameters('module', 'owa');
		
		if (!empty($params)):
			return true;
		else:
			return false;
		endif;
	}
	
	
	/**
     * @see GalleryModule::getSiteAdminViews
     */
    function getSiteAdminViews() {
    	
    	global $gallery;
    	
    	// this is needed becasue on the plugins page this callback is triggered
    	// whether then plugin is active or not for some reason.
    	//if ($this->owa_isActivated()):
			// get OWA instance
		//	$owa = owa_factory();
			// set user priviledges of the request for OWA
		//	owa_set_priviledges();
		//endif;
					
		$data = array(array('name' => $this->translate('Dashboard'), 'view' => 'owa.owaDashboard'),
					  array('name' => $this->translate('Admin Settings'), 'view' => 'owa.owaOptions'));		    
		return array(null, $data);
    }
    
    /**
     * Module specific logic for install
     *
     * @see GalleryModule::install
     */
    function upgrade($currentVersion, $statusMonitor) {
		
		global $gallery;
		
		$owa_config = array();
		$owa_config['do_not_fetch_config_from_db'] = true;		
		$owa = owa_factory($owa_config);
				// set user priviledges of the request for OWA to log
		owa_set_priviledges();
		
		//get the base gallery url 
		$urlgenerator = $gallery->getUrlGenerator();
		$site_url = $urlgenerator->getCurrentUrlDir();
		
		//Config('galleryBaseUrl');
                	
        $params = array('site_id' => md5($site_url), 
    					'name' => 'Gallery',
    					'domain' => $site_url, 
    					'description' => '',
    					'do' => 'base.installEmbedded');
    					
    	$page = $owa->handleRequest($params);
    	
		return null;
    }
    
    /*

    // register event handlers
	function performFactoryRegistrations() {
    	
    	owa_coreAPI::debug("g2 factory regs");
    	$ret = GalleryCoreApi::registerFactoryImplementation('GalleryEventListener', 'owaLoginEventHandler ', 'owa', __FILE__, 'owa', array('Gallery::Login'), null);
    	
    	$ret = GalleryCoreApi::registerFactoryImplementation('GalleryEventListener', 'owaLoginEventHandler ', 'owa', __FILE__, 'owa', array('Gallery::Logout'), null);
    	
		//$listener = new owaLoginEventHandler();
		//$ret = GalleryCoreApi::registerEventListener('Gallery::Login', $listener, true);
    	//$ret = GalleryCoreApi::registerEventListener('Gallery::Logout', $listener, true);
	
    	if ($ret) {
	    	return $ret;
		}
	
		return null;
		
 	}

*/
}

/**
 * OWA Gallery Views
 * 
 * Enables OWA to be embedded as a Gallery's site admin screen
 */
class owaOptionsView extends GalleryView {

	/**
     * @see GalleryView::loadTemplate
     */
    function loadTemplate(&$template, &$form) {

		$owa = owa_factory();
		
		owa_set_priviledges();
		
		$params = array();
		
		 if (empty($owa->params['do'])):	
				$params['do'] = 'base.optionsGeneral';
		endif;
		       
		$page = $owa->handleRequest($params);
		$template->setVariable('owa', array('content' => $page));
		return array(null, array('body' => 'modules/owa/modules/base/templates/gallery.tpl'));
    }
    
    /**
     * Does this view change any data? Only controllers should change data, but AJAX and some
     * immediate views are handled in views in Gallery.
     * @return bool true if the view changes data
     */
    function isControllerLike() {
		return true;
    }

		
}

/**
 * OWA Gallery Views
 * 
 * 
 */
class owaDashboardView extends GalleryView {

	/**
     * @see GalleryView::loadTemplate
     */
    function loadTemplate(&$template, &$form) {

		$owa = owa_factory();
		
		owa_set_priviledges();
		
		$params = array();
		//$params['view'] = 'base.report';
		$params['action'] = 'base.reportDashboard'; 
		$params['period'] = 'today';      
		$page = $owa->handleRequest($params);
		$template->setVariable('owa', array('content' => $page));
		return array(null, array('body' => 'modules/owa/modules/base/templates/gallery.tpl'));
    }
		
}

class owaGenericView extends GalleryView {

	/**
     * @see GalleryView::loadTemplate
     */
    function loadTemplate(&$template, &$form) {

		$owa = owa_factory();
		
		owa_set_priviledges();
		
		$page = $owa->handleRequest();
		$template->setVariable('owa', array('content' => $page));
		return array(null, array('body' => 'modules/owa/modules/base/templates/gallery.tpl'));
    }
	
	/**
     * Does this view change any data? Only controllers should change data, but AJAX and some
     * immediate views are handled in views in Gallery.
     * @return bool true if the view changes data
     */
    function isControllerLike() {
		return true;
    }
		
}


GalleryCoreApi::requireOnce('modules/core/classes/GalleryController.class');
class owaControlController extends GalleryController {

	/**
     * @see GalleryController::handleRequest
     */
    function handleRequest($form) {
    
    	$result = array('delegate' => array('view' => 'owa.owaGeneric'),
                        'status' => 1, 'error' => '');
        return array(null, $result);
    
    }

}


/**
 * Handles OWA's special action requests
 *
 */
class owaActionView extends GalleryView {


	/**
     * @see GalleryView::isImmediate
     */
    function isImmediate() {
		return true;
    }

	/**
	 * Method called when view is set to render immeadiately.
	 * This will bypass Gallery's global templating allowing
	 * the view to send output directly to the browser.
	 */
	function renderImmediate($status, $error) {
	
		global $gallery;
		$owa = owa_factory();
		
		$gallery->debug('hello from owaAction');
		owa_set_priviledges();
		
		$owa->handleSpecialActionRequest();
		
		return null;
    }

	
}

/**
 * Gallery Template Callback for OWA footer elements
 *
 * This class is packaged here for convienence only but could also be
 * put in Callbacks.inc.
 */
class owaCallbacks {
	
	function callback($params, &$smarty, $callback, $userId=null) {
   		/* 1. Identify the exact callback */
  		switch ($callback) {
      		case 'pagetags':
        	
           	$viewName = GalleryUtilities::getRequestVariables('view');
    		// ensure this is not a Gallery admin screen
    		
    		if ($viewName == "core.SiteAdmin" || $viewName == "core.ItemAdmin"):
    			return;
        	else:
				/* 2. Load the requested data */
				
				$owa = owa_factory();
				$tags = $owa->placeHelperPageTags(false);
				
				/* 3. Assign the requested data to a template variable */
				$block =& $smarty->_tpl_vars['block'];
				
				/* By convention, put the data into $block[$moduleId] (in this case, moduleId is 'owa') */
				$block['owa']['pagetags'] = array('owaData' => $tags,
							   'randomNumber' => rand()); // You can put any data into the template variable...
	    	endif;
	    	
	    	break;
			
			case 'SomeOtherCallbackName':
            	;
            	break;
            default:
            	;
      	}

		return null;
	}
}

class owaLoginEventHandler {
	
	function owaLoginEventHandler() {
		return;
	}
	
	function __construct() {
		return;
	}
	
	function handleEvent($event) {
		global $gallery;
		owa_coreAPI::debug("hello from login event handler ".print_r($event));
		return array(null, null);
	}

}

?>