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

require_once('owa_env.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_mw.php');
require_once "$IP/includes/SpecialPage.php";

/* MEDIAWIKI GLOBALS */
global $wgCachePages, $wgUser, $wgServer, $wgScriptPath, $wgScript;
/* OWA's MEDIAWIKI CONFIGURATION OVERRIDES */

// Build OWA's Mediawiki specific config overrides array
$owa_config = array();
$wiki_url = $wgScriptPath;

$owa_config['report_wrapper'] = 'wrapper_mediawiki.tpl';
$owa_config['images_url'] = OWA_PUBLIC_URL.'i/';
$owa_config['images_absolute_url'] = $owa_config['images_url'];
$owa_config['main_url'] = $wgScriptPath.'/index.php?title=Special:Owa';
$owa_config['main_absolute_url'] = $wgServer.$owa_config['main_url'];
$owa_config['action_url'] = $wgServer.$wgScriptPath.'/index.php?action=owa&owa_specialAction';
$owa_config['log_url'] = $wgServer.$wgScriptPath.'/index.php?action=owa&owa_logAction=1';
$owa_config['link_template'] = '%s&%s';
$owa_config['site_id'] = md5($wgServer.$wiki_url);
$owa_config['is_embedded'] = true;
$owa_config['delay_first_hit'] = true;
$owa_config['error_handler'] = 'development';
$owa_config['query_string_filters'] = 'returnto';

// Turn MediaWiki Caching Off
global $wgCachePages, $wgCacheEpoch;
$wgCachePages = false;
$wgCacheEpoch = 'date +%Y%m%d%H%M%S';

// Register Extension with MediaWiki
$wgExtensionFunctions[] = 'owa_main';
$wgExtensionCredits['other'][] = array( 'name' => 'Open Web Analytics for MediaWiki', 
										'author' => 'Peter Adams <peter@openwebanalytics.com>', 
										'url' => 'http://www.openwebanalytics.com' );
										
$wgExtensionCredits['specialpage'][] = array('name' => 'Open Web Analytics for MediaWiki', 
  											 'author' => 'Peter Adams', 
  											 'url' => 'http://www.openwebanalytics.com',
  											 'description' => 'Open Web Analytics for MedaWiki');

//Load Special Page
$wgAutoloadClasses['SpecialOwa'] = __FILE__;
// Adds OWA's admin interface to special page list
$wgSpecialPages['Owa'] = 'SpecialOwa';
$wgHooks['LoadAllMessages'][] = 'SpecialOwa::loadMessages';

/**
 * Main Mediawiki Extension method
 *
 * sets up OWA to be triggered for various hooks/actions
 */
function owa_main() {

	global $wgHooks;
	
	//$owa = owa_singleton();
	
	//if ($owa->getSetting('base', 'install_complete')) {
	
		//$wgHooks['MediaWikiPerformAction'][] = 'owa_actions';
		$wgHooks['UnknownAction'][] = 'owa_actions';
		// Hook for logging Article Page Views	
		$wgHooks['ArticlePageDataAfter'][] = 'owa_logArticle';
		$wgHooks['SpecialPageExecuteAfterPage'][] = 'owa_logSpecialPage';
		$wgHooks['CategoryPageView'][] = 'owa_logCategoryPage';
		// Hook for adding helper page tracking tags 	
		$wgHooks['BeforePageDisplay'][] = 'owa_footer';
	//}
		
    return;
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
	
	global $wgOut, $wgUser;
	
	// populate the user object.
	$wgOut->disable();

	if ($_GET['action'] === 'owa') {
			
		$owa = owa_singleton();
		//print_r($wgUser);
		//owa_set_priviledges();
		$owa->handleSpecialActionRequest();
		return false;
	} else {
		return true;
	}
	
}

function owa_singleton() {

	global $wgUser, $owa_config;
	$wgUser->load();
	$owa = &owa_mw::singleton($owa_config);
	$cu = &owa_coreAPI::getCurrentUser();
	$cu->setUserData('user_id', $wgUser->mName);
	$cu->setUserData('email_address', $wgUser->mEmail);
	$cu->setUserData('real_name', $wgUser->mRealName);
	$cu->setRole(owa_translate_role($wgUser->mGroups));
	$cu->setAuthStatus(true);
	
	return $owa;
	
}

/**
 * OWA Priviledges
 *
 * Populates OWA requestion container with info about the current mediawiki user.
 * This info is needed by OWA authentication system as well as to add dimensions
 * requests that are logged.
 */
function owa_set_priviledges() {
	
	global $wgUser;	
	
	$owa = owa_singleton();
	//print_r($wgUser);
	// preemptively set the current user info and mark as authenticated so that
	// downstream controllers don't have to authenticate
	$cu = &owa_coreAPI::getCurrentUser();
	$cu->setUserData('user_id', $wgUser->mName);
	$cu->setUserData('email_address', $wgUser->mEmail);
	$cu->setUserData('real_name', $wgUser->mRealName);
	$cu->setRole(owa_translate_role($wgUser->mGroups));
	
	$cu->setAuthStatus(true);
		
	return true;
}

function owa_translate_role($level = array()) {
	
	if (!empty($level)) {

		if (in_array("*", $level)):
			$owa_role = 'everyone';
		elseif (in_array("user", $level)):
			$owa_role = 'viewer';
		elseif (in_array("autoconfirmed", $level)):
			$owa_role = 'viewer';
		elseif (in_array("emailconfirmed", $level)):
			$owa_role = 'viewer';
		elseif (in_array("bot", $level)):
			$owa_role = 'viewer';
		elseif (in_array("sysop", $level)):
			$owa_role = 'admin';
		elseif (in_array("bureaucrat", $level)):
			$owa_role = 'admin';
		elseif (in_array("developer", $level)):
			$owa_role = 'admin';
		endif;
	} else {
		$owa_role = '';
	}
	
	return $owa_role;

}

/**
 * Logs Special Page Views
 *
 * @param object $specialPage
 * @return boolean
 */
function owa_logSpecialPage(&$specialPage) {
	
	global $wgUser, $wgOut;
	
	$owa = owa_singleton();
	
	if ($owa->getSetting('base', 'install_complete')) {
	
		$event = $owa->makeEvent();
		$event->setEventType('base.page_request');
		$event->set('user_name', $wgUser->mName);
		$event->set('user_email', $wgUser->mEmail);
		$event->set('page_title', $wgOut->mPagetitle);
		$event->set('page_type', 'Special Page');
		$owa->trackEvent($event);
	}
		
	return true;
}

/**
 * Logs Category Page Views
 *
 * @param object $categoryPage
 * @return boolean
 */
function owa_logCategoryPage(&$categoryPage) {
	
	global $wgUser, $wgOut;
	
	$owa = owa_singleton();
    //owa_set_priviledges();
    if ($owa->getSetting('base', 'install_complete')) {
		$event = $owa->makeEvent();
		$event->setEventType('base.page_request');
		$event->set('user_name', $wgUser->mName);
		$event->set('user_email', $wgUser->mEmail);
		$event->set('page_title', $wgOut->mPagetitle);
		$event->set('page_type', 'Category');
		$owa->trackEvent($event);
	}
	return true;
}

/**
 * Logs Article Page Views
 *
 * @param object $article
 * @return boolean
 */
function owa_logArticle(&$article) {

	global $wgUser, $wgOut, $wgTitle;
	
	$wgTitle->invalidateCache();
	$wgOut->enableClientCache(false);
	
	// Setup Application Specific Properties to be Logged with request
	$owa = owa_singleton();
	
	if ($owa->getSetting('base', 'install_complete')) {
		owa_coreAPI::debug("logging event from MW article hook");
		//owa_set_priviledges();
		$event = $owa->makeEvent();
		$event->setEventType('base.page_request');
		$event->set('user_name', $wgUser->mName);
		$event->set('user_email', $wgUser->mEmail);
		$event->set('page_title', $article->mTitle->mTextform);
		$event->set('page_type', 'Article');
		$owa->trackEvent($event);
	}
		
	return true;
	
}

/**
 * Adds helper page tags to Article Pages if they are needed
 *
 * @param object $article
 * @return boolean
 */
function owa_footer(&$wgOut, $sk) {
	
	global $wgRequest;
	
	if ($wgRequest->getVal('action') != 'edit') {
		
		$owa = owa_singleton();
		if ($owa->getSetting('base', 'install_complete')) {
			
			$tags = $owa->placeHelperPageTags(false);		
			$wgOut->addHTML($tags);
			
		}
	}
	
	return true;
}


//////////////////////////////////////////////////////////////////////////////////


/**
 * OWA Special Page Class
 *
 * Enables OWA to be accessed through a Mediawiki special page. 
 */
class SpecialOwa extends SpecialPage {

    function SpecialOwa() {
            SpecialPage::SpecialPage('Owa','',true);
            self::loadMessages();
    }

    function execute() {
    	global $wgRequest, $wgOut, $wgUser, $wgSitename, $wgScriptPath, $wgScript, $wgServer, $wgDBtype, $wgDBname, $wgDBserver, $wgDBuser, $wgDBpassword;
            
            $this->setHeaders();
            //must be called after setHeaders for some reason or elsethe wgUser object is not yet populated.
            $owa = owa_singleton();
            //owa_set_priviledges();
            $params = array();
            
            // if no action is found...
            $do = owa_coreAPI::getRequestParam('do');
            if (empty($do)) {
            	// check to see that owa in installed.
                if (!$owa->getSetting('base', 'install_complete')) {
					
					define('OWA_INSTALLING', true);
					               	
                	$site_url = $wgServer.$wgScriptPath;

                	$params = array('site_id' => md5($site_url), 
    							'name' => $wgSitename,
    							'domain' => $site_url, 
    							'description' => '',
    							'do' => 'base.installStartEmbedded');
    				$params['db_type'] = $wgDBtype;
					$params['db_name'] = $wgDBname;
					$params['db_host'] = $wgDBserver;
					$params['db_user'] = $wgDBuser;
					$params['db_password'] = $wgDBpassword;
					$params['public_url'] = $wgServer.$wgScriptPath.'/extensions/owa/';
    				$page = $owa->handleRequest($params);
    			
    			// send to daashboard
               } else {
                	$params['do'] = 'base.reportDashboard';
		           	$page = $owa->handleRequest($params);
                }
            // do action found on url
            } else {
           		$page = $owa->handleRequestFromURL(); 
            }
            
           				

			// switch for output scenario
			//if (empty($owa->config['schema_version'])):
				return $wgOut->addHTML($page);					
			//else:
			//	$wgOut->disable();
			//	echo $page;
			//	return;
			//endif;
            
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