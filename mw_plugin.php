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

// ensures that mediawiki is the only entry point.
if ( ! defined( 'MEDIAWIKI' ) ) {
	exit;
}

require_once( dirname( __FILE__ )  . '/' . 'owa_env.php' );
require_once( OWA_BASE_CLASSES_DIR . 'owa_mw.php' );

/** 
 * OWA MW EXTENSION SPECIFIC CONFIGURATION VARIABLES
 * To alter these, set them in your localsettings.php file AFTER you
 * include/require the extension.
 */
 
// $wgOwaSiteId is used to overide the default site_id that OWA
// will append to all tracking requests.This is handy if you want
// to aggregate stats for more than one wiki under the same site_id
$wgOwaSiteId = false;

// $wgOwaEnableSpecialPage enables/disables OWA's special page.
// Use this to deactivate and hide the special page 
$wgOwaEnableSpecialPage = true;

// $wgOwaThirdPartyCookies enables third party cookie mode for 
// OWA's javascript tracker. This is rarely a good idea and will
// have data quality ramifications.
$wgOwaThirdPartyCookies = false;

// $wgOwaCookieDomain contain the domain that OWA's javascript tracker 
// will use to write it's cookies.
$wgOwaCookieDomain = false;

/**
 * Register Extension and Hooks
 */
$wgExtensionCredits['specialpage'][] = array(
		'name' 			=> 'Open Web Analytics for MediaWiki', 
  		'author' 		=> 'Peter Adams', 
  		'url' 			=> 'http://www.openwebanalytics.com',
  		'description' 	=> 'Open Web Analytics for MedaWiki'
);

// used to sniff out admin requests	
$wgHooks['UnknownAction'][] = 'owa_actions';
// used to set proper params for logging Article Page Views	
$wgHooks['ArticlePageDataAfter'][] = 'owa_logArticle';
// used to set proper params for logging Special Page Views	
$wgHooks['SpecialPageExecuteAfterPage'][] = 'owa_logSpecialPage';
// used to set proper params for logging Category Page Views	
$wgHooks['CategoryPageView'][] = 'owa_logCategoryPage';
// used to add OWA's javascript tracking tag to all pages 	
$wgHooks['BeforePageDisplay'][] = 'owa_footer';
// used to fire Action events when articles are created
$wgHooks['ArticleInsertComplete'][] = 'owa_newArticleAction';
// used to fire Action events when articles are edited
$wgHooks['ArticleSaveComplete'][] = 'owa_editArticleAction';
// used to fire Action events when new articles are deleted
$wgHooks['ArticleDeleteComplete'][] = 'owa_deleteArticleAction';
// used to fire Action events when new user accounts are created
$wgHooks['AddNewAccount'][] = 'owa_addUserAction';
// used to fire Action events when new uploads occur
$wgHooks['UploadComplete'][] = 'owa_addUploadAction';
// used to fire Action events when users login
$wgHooks['UserLoginComplete'][] = 'owa_userLoginAction';
// used to fire Action events when talk pages are edited
$wgHooks['ArticleEditUpdateNewTalk'][] ='owa_editTalkPageAction';
// used to register OWA's special page
$wgHooks['SpecialPage_initList'][] = 'owa_registerSpecialPage';

// add 'owa_view' right to admin groups in order to view special page
$wgGroupPermissions['user']['owa_view'] = true;

/**
 * Hook Function for Registering OWA's Special Page
 */
function owa_registerSpecialPage( &$aSpecialPages ) {
	
	global $wgOwaEnableSpecialPage;
	
	// Enable Special Page
	if ( $wgOwaEnableSpecialPage === true ) {
		//Load Special Page
		$wgAutoloadClasses['SpecialOwa'] = __FILE__;
		// Adds OWA's admin interface to special page list
		$aSpecialPages['Owa'] = 'SpecialOwa';
	}
	// must return true for hook to continue processing.
	return true;
}

/**
 * Hook for OWA special actions
 *
 * This uses mediawiki's 'unknown action' hook to trigger OWA's special action handler.
 * This is setup by adding 'action=owa' to the URLs for special actions. There is 
 * probably a better way to do this so that the OWA namespace is preserved.
 *
 * @TODO figure out how to register this method to be triggered only when 'action=owa' instead of 
 *		 for all unknown mediawiki actions.
 * @param object $specialPage
 * @url http://www.mediawiki.org/wiki/Manual:MediaWiki_hooks/UnknownAction
 * @return false
 */
function owa_actions($action) {
	
	global $wgOut, $wgUser, $wgRequest;
	
	$action = $wgRequest->getText( 'action' );
	if ( $action === 'owa' ) {
		$wgOut->disable();
		$owa = owa_singleton();
		$owa->handleSpecialActionRequest();
		return false;
	} else {
		return true;
	}
}

/**
 * OWA Singelton
 *
 * Needed to avoid OWA loading for every mediawiki request
 */
function owa_singleton() {

	static $owa;
		
	if ( empty( $owa ) ) {
			
		global 	$wgUser, 
				$wgServer, 
				$wgScriptPath, 
				$wgScript, 
				$wgMainCacheType, 
				$wgMemCachedServers,
				$wgOwaSiteId,
				$wgOwaMemCachedServers;
		
		/* OWA CONFIGURATION OVERRIDES */
		$owa_config = array();
		// check for memcache. these need to be passed into OWA to avoid race condition.
		if ( $wgMainCacheType === CACHE_MEMCACHED ) {
			$owa_config['cacheType'] = 'memcached';
			$owa_config['memcachedServers'] = $wgMemCachedServers;
		}
		$owa = new owa_mw( $owa_config );
		$owa->setSetting( 'base', 'report_wrapper', 'wrapper_mediawiki.tpl' );
		$owa->setSetting( 'base', 'main_url', $wgScriptPath.'/index.php?title=Special:Owa' );
		$owa->setSetting( 'base', 'main_absolute_url', $wgServer.$owa->getSetting( 'base', 'main_url' ) );
		$owa->setSetting( 'base', 'action_url', $wgServer.$wgScriptPath.'/index.php?action=owa&owa_specialAction' );
		$owa->setSetting( 'base', 'api_url', $wgServer.$wgScriptPath.'/index.php?action=owa&owa_apiAction' );
		$owa->setSetting( 'base', 'link_template', '%s&%s' );
		$owa->setSetting( 'base', 'is_embedded', true );
		$owa->setSetting( 'base', 'query_string_filters', 'returnto' );
		
		if ( ! $wgOwaSiteId ) {
			$wgOwaSiteId = md5($wgServer.$wgScriptPath);
		}
		
		$owa->setSiteId( $wgOwaSiteId );
		
		// filter authentication
		$dispatch = owa_coreAPI::getEventDispatch();
		// alternative auth method, sets auth status, role, and allowed sites list.
		$dispatch->attachFilter('auth_status', 'owa_mwAuthUser',0);
		//print_r( $current_user );
		
	}
		
	return $owa;
}

/**
 * Populates OWA's current user object with info about the current mediawiki user.
 * This info is needed by OWA authentication system as well as to add dimensions
 * requests that are logged.
 */
function owa_mwAuthUser($auth_status) {
	
	global 	$wgUser, $wgOwaSiteId;
	
	if ( $wgUser->isLoggedIn() ) { 
	
		$cu = owa_coreAPI::getCurrentUser();
		$cu->setAuthStatus(true);	

		$cu->setUserData( 'user_id', $wgUser->getName() );
		$cu->setUserData( 'email_address', $wgUser->getEmail() );
		$cu->setUserData( 'real_name', $wgUser->getRealName() );
		$cu->setRole( owa_translate_role( $wgUser->getGroups() ) );
		
		// set list of allowed sites. In this case it's only this wiki.
		
		$domains = array($wgOwaSiteId);		
		// load assigned sites list by domain
    	$cu->loadAssignedSitesByDomain($domains);
		$cu->setInitialized();
    	
    	return true;
    	
	} else {
		// not logged in
		return false;
	} 		

}

/**
 * Transalates MW Roles into OWA Roles
 *
 * @todo make this configurable with a global property
 */
function owa_translate_role($level = array()) {
	
	if ( ! empty( $level ) ) {

		if ( in_array( "*", $level ) ) {
			$owa_role = 'everyone';
		} elseif ( in_array( "user", $level ) ) {
			$owa_role = 'viewer';
		} elseif ( in_array( "autoconfirmed", $level ) ) {
			$owa_role = 'viewer';
		} elseif ( in_array( "emailconfirmed", $level ) ) {
			$owa_role = 'viewer';
		} elseif ( in_array( "bot", $level ) ) {
			$owa_role = 'viewer';
		} elseif ( in_array( "sysop", $level ) ) {
			$owa_role = 'admin';
		} elseif ( in_array( "bureaucrat", $level ) ) {
			$owa_role = 'admin';
		} elseif ( in_array( "developer", $level ) ) {
			$owa_role = 'admin';
		}
		
	} else {
		$owa_role = '';
	}
	
	return $owa_role;
}

/**
 * Helper function for tracking page views of various types
 */
function owa_trackPageView( $params = array() ) {
	
	global $wgUser, $wgOut, $wgOwaSiteId;
	
	$owa = owa_singleton();
	
	if ( $owa->getSetting( 'base', 'install_complete' ) ) {
	
		//$event = $owa->makeEvent();
		//$event->setEventType( 'base.page_request' );
		$owa->setSiteId( $wgOwaSiteId );
		$owa->setProperty( 'user_name', $wgUser->mName );
		$owa->setProperty( 'user_email', $wgUser->mEmail );
		$owa->setProperty( 'language', owa_getLanguage() );
		if ( ! $owa->pageview_event->get( 'page_type') ) {
			$owa->setPageType( '(not set)' );
		}
		
		//foreach ( $params as $k => $v ) {
		//	$event->set( $k, $v );
		//}
		
		// if the page title is not set for some reasons, set it
		// using $wgOut.
		if ( ! $owa->pageview_event->get( 'page_title') ) {
			$owa->setPageTitle( 'page_title', $wgOut->getPageTitle() );
		}
		
		/*
		$tag = sprintf(
						'<!-- OWA Page View Tracking Params -->
						var owa_params = %s;', 
						 json_encode( $event->getProperties() )
				);
				
				$wgOut->addInlineScript( $tag );
		*/
	}
		
	return true;
}

/**
 * Logs Special Page Views
 *
 * @param object $specialPage
 * @return boolean
 */
function owa_logSpecialPage(&$specialPage) {
	
	$title_obj = $specialPage->getTitle();
	$title = $title_obj->getText();
	$owa = owa_singleton();
	$owa->setPageTitle( $title );
	$owa->setPageType( 'Special Page' );
	return true;
}

/**
 * Logs Category Page Views
 *
 * @param object $categoryPage
 * @return boolean
 */
function owa_logCategoryPage( &$categoryPage ) {
	
	$title_obj = $categoryPage->getTitle();
	$title = $title_obj->getText();
	$owa = owa_singleton();
	$owa->setPageTitle( $title );
	$owa->setPageType( 'Category' );
	return true;
}

/**
 * Logs Article Page Views
 *
 * @param object $article
 * @return boolean
 */
function owa_logArticle( &$article ) {
	
	$title_obj = $article->getTitle();
	$title = $title_obj->getText();
	$owa = owa_singleton();
	$owa->setPageTitle( $title );
	$owa->setPageType( 'Article' );
	return true;
}

/**
 * Helper Function for tracking Action Events
 * 
 * This function is a wrapper for the Action Event API in owa_client.
 *
 * @param	$action_name	string	The name of the action being tracked
 * @param	$label			string	The label associated with the action being tracked
 * @return boolean	true
 */
function owa_trackAction( $action_name, $label ) {

	$owa = owa_singleton();
   
    if ( $owa->getSetting( 'base', 'install_complete' ) ) {
		$owa->trackAction( 'mediawiki', $action_name, $label );
		owa_coreAPI::debug( "logging action event " . $action_name );
	}
	
	return true;
}

/**
 * Logs New Articles
 *
 * @param object $categoryPage
 * @return boolean
 */
function owa_newArticleAction(&$article, &$user, $text, $summary, $minoredit, $watchthis, $sectionanchor, &$flags, $revision) {
	
	$label = $article->getTitle()->getText();
	return owa_trackAction( 'Article Created', $label );
}

function owa_editArticleAction($article, &$user, $text, $summary, 
		$minoredit, $watchthis, $sectionanchor, &$flags, $revision, 
		&$status, $baseRevId, &$redirect = '') {
	
	if ( $flags & EDIT_UPDATE ) {
		
		$label = $article->getTitle()->getText();
		return owa_trackAction( 'Article Edit', $label );
		
	} else {
		
		return true;
	}
}

function owa_deleteArticleAction( &$article, &$user, $reason, $id ) {
	
	$label = $article->getTitle()->getText();
	return owa_trackAction( 'Article Deleted', $label );
}

function owa_addUserAction( $user, $byEmail ) {
	
	$label = '';
	return owa_trackAction( 'User Account Added', $label );
}

function owa_addUploadAction( &$image ) {
	
	$label = $image->getLocalFile()->getMimeType();
	return owa_trackAction( 'File Upload', $label );
}

function owa_userLoginAction( &$user, &$inject_html ) {
	
	$label = '';
	return owa_trackAction( 'Login', $label );
}

function editTalkPageAction( $article ) {

	$label = $article->getTitle()->getText();
	return owa_trackAction( 'Talk Page Edit', $label );
}

/**
 * Adds javascript tracker to pages
 *
 * @param object $article
 * @return boolean
 */
function owa_footer(&$wgOut, $sk) {
	
	global $wgRequest, $wgOwaThirdPartyCookies, $wgOwaCookieDomain;
	
	if ($wgRequest->getVal('action') != 'edit' && $wgRequest->getVal('title') != 'Special:Owa') {
		
		$owa = owa_singleton();
		if ($owa->getSetting('base', 'install_complete')) {
			
			$cmds  = "";
			if ( $wgOwaThirdPartyCookies ) {
				$cmds .= "owa_cmds.push( ['setOption', 'thirdParty', true] );";
			}
			
			if ( $wgOwaCookieDomain ) {
				$cmds .= "owa_cmds.push( ['setCookieDomain', '$wgOwaCookieDomain'] );";
			}
			
			$page_properties = $owa->getAllEventProperties($owa->pageview_event);
			if ( $page_properties ) {
				$page_properties_json = json_encode( $page_properties );
				$cmds .= "owa_cmds.push( ['setPageProperties', $page_properties_json] );";
			}
			
			//$wgOut->addInlineScript( $cmds );
			
			$options = array( 'cmds' => $cmds );
			
			$tags = $owa->placeHelperPageTags(false, $options);		
			$wgOut->addHTML($tags);
			
		}
	}
	
	return true;
}

/**
 * Gets mediawiki Language variable
 */
function owa_getLanguage() {
    	
	global $wgLang, $wgContLang;
	$code = '';
	
	$code = $wgLang->getCode();
	if ( ! $code ) {
		$code = $wgContLang->getCode();
	}
	
	return $code;
}  

/**
 * OWA Special Page Class
 *
 * Enables OWA to be accessed through a Mediawiki special page. 
 */
class SpecialOwa extends SpecialPage {

    function __construct() {
            parent::SpecialPage('Owa', 'owa_view');
            self::loadMessages();
    }

    function execute() {
    
    	global $wgRequest, $wgOut, $wgUser, $wgSitename, $wgScriptPath, $wgScript, $wgServer, 
    		   $wgDBtype, $wgDBname, $wgDBserver, $wgDBuser, $wgDBpassword;

        //must be called after setHeaders for some reason or elsethe wgUser object is not yet populated.        
        $this->setHeaders();
        
        if ($this->userCanExecute($wgUser)) {
         

	        $owa = owa_singleton();
	        $params = array();
	        
	        // if no action is found...
	        $do = owa_coreAPI::getRequestParam('do');
	        if (empty($do)) {
	        	// check to see that owa in installed.
	            if (!$owa->getSetting('base', 'install_complete')) {
					
					define('OWA_INSTALLING', true);
					               	
	            	$site_url = $wgServer.$wgScriptPath;
	
	            	$params = array(
	            			'site_id' 		=> md5($site_url), 
							'name' 			=> $wgSitename,
							'domain' 		=> $site_url, 
							'description' 	=> '',
							'do' 			=> 'base.installStartEmbedded');
					
					$params['db_type'] = $wgDBtype;
					$params['db_name'] = $wgDBname;
					$params['db_host'] = $wgDBserver;
					$params['db_user'] = $wgDBuser;
					$params['db_password'] = $wgDBpassword;
					$params['public_url'] = $wgServer.$wgScriptPath.'/extensions/owa/';
					$page = $owa->handleRequest($params);
				
				// send to daashboard
	           } else {
	            	//$params['do'] = 'base.reportDashboard';
	            	
		           	$page = $owa->handleRequest($params);
	            }
	        // do action found on url
	        } else {
	       		$page = $owa->handleRequestFromURL(); 
	        }
	        
			return $wgOut->addHTML($page);					
		} else {
			$this->displayRestrictionError();
		}
    }

    function loadMessages() {
    	static $messagesLoaded = false;
        global $wgMessageCache;
            
		if ( $messagesLoaded ) return;
		
		$messagesLoaded = true;
		
		// this should be the only msg defined by mediawiki
		$allMessages = array(
			 'en' => array( 
				 'owa' => 'Open Web Analytics'
				 )
			);


		// load msgs in to mediawiki cache
		foreach ( $allMessages as $lang => $langMessages ) {
			   $wgMessageCache->addMessages( $langMessages, $lang );
		}
		
		return true;
    }    
}

?>
