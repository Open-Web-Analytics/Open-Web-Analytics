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
require_once(OWA_BASE_CLASSES_DIR.'owa_php.php');
require_once "$IP/includes/SpecialPage.php";

global $wgCachePages, $wgDBtype, $wgDBname, $wgDBserver, $wgDBuser, $wgDBpassword, $wgUser, $wgServer, $wgScriptPath, $wgScript;

// OWA Configuration


// OWA DATABASE CONFIGURATION 
// Will use Wordpress config unless there is a config file present.
// OWA uses this to setup it's own DB connection seperate from the one
// that Wordpress uses.

$config_file = OWA_CONF_DIR.'owa-config.php';
if (file_exists($config_file)):
	// do nothing as the caller class will define the DB config constants later.
	;
else:
	// use the Wordpress configuration
	define('OWA_DB_TYPE', $wgDBtype);
	define('OWA_DB_NAME', $wgDBname);
	define('OWA_DB_HOST', $wgDBserver);
	define('OWA_DB_USER', $wgDBuser);
	define('OWA_DB_PASSWORD', $wgDBpassword);
endif;

// Public folder URI
define('OWA_PUBLIC_URL', $wgServer.$wgScriptPath.'/extensions/owa/public/');

// Build the OWA wordpress specific config overrides array
$owa_config = array();
$owa_config['report_wrapper'] = 'wrapper_mediawiki.tpl';
$owa_config['images_url'] = OWA_PUBLIC_URL.'i/';
$owa_config['images_absolute_url'] = $owa_config['images_url'];
$owa_config['main_url'] = $wgScriptPath.'/index.php?title=Special:Owa';
$owa_config['main_absolute_url'] = $wgServer.$owa_config['main_url'];
$owa_config['action_url'] = $wgServer.$wgScriptPath.'/index.php?action=owa&owa_specialAction';
$owa_config['log_url'] = $wgServer.$wgScriptPath.'/index.php?action=owa&owa_logAction=1';
$owa_config['link_template'] = '%s&%s';
$owa_config['authentication'] = 'mediawiki';
$owa_config['site_id'] = md5($wgServer.$wiki_url);
$owa_config['is_embedded'] = 'true';

//$owa = new owa_php($owa_config);

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
$wgSpecialPages['Owa'] = 'SpecialOwa';
$wgHooks['LoadAllMessages'][] = 'SpecialOwa::loadMessages';
$wgHooks['UnknownAction'][] = 'owa_actions';


// OWA Factory

function owa_factory() {

	global $owa_config;
	static $owa;
	
	if( isset($owa)):
		return $owa;
	else:
		$owa = new owa_php($owa_config);
		return $owa;
	endif;

}

/**
 * Main Media Wiki Extension method
 *
 */
function owa_main() {
	global $wgHooks;

	
	
	// Create Instance of OWA
	//$owa = new owa_php;
	//$owa = owa_factory();
	// Hook for logging Article Page Views
	$wgHooks['ArticlePageDataAfter'][] = 'owa_logArticle';
	$wgHooks['SpecialPageExecuteAfterPage'][] = 'owa_logSpecialPage';
	$wgHooks['CategoryPageView'][] = 'owa_logCategoryPage';
	
	// Hooks for adding page tracking tags 
	
	$wgHooks['ArticlePageDataAfter'][] = 'owa_footer';
	$wgHooks['SpecialPageExecuteAfterPage'][] = 'owa_footer';
	$wgHooks['CategoryPageView'][] = 'owa_footer';
	
	
	//SpecialPage::addPage(new OwaSpecialPage());
	
    return;
}

/**
 * Logs Special Page Views
 *
 * @param object $specialPage
 * @url http://www.mediawiki.org/wiki/Manual:MediaWiki_hooks/UnknownAction
 * @return false
 */
function owa_actions() {
	
	global $wgOut;
	
	$owa = owa_factory();
    owa_set_priviledges();
	

	$wgOut->disable();
	$owa->handleSpecialActionRequest();
	
	return false;

}

function owa_set_priviledges() {

	global $wgUser;
	
	$owa = owa_factory();
	$owa->params['caller']['mediawiki']['user_data'] = array(
	
					'user_level' 	=> $wgUser->mGroups,
					'user_ID'		=> $wgUser->mName,
					'user_login'	=> $wgUser->mName,
					'user_email'	=> $wgUser->mEmail,
					'user_identity'	=> $wgUser->mRealName,
					//'user_password'	=> $wgUser->mPassword
					);
					
					$owa->params['u'] = 'xxxxx'.$wgUser->mName;
					$owa->params['p'] = 'xxxxxxx';//$wgUser->mPassword;

	return;
}

/**
 * Logs Special Page Views
 *
 * @param object $specialPage
 * @return boolean
 */
function owa_logSpecialPage(&$specialPage) {
	
	global $wgUser, $wgOut;
	
	$app_params['user_name']= $wgUser->mName;
    $app_params['user_email'] = $wgUser->mEmail;
    $app_params['page_title'] = $wgOut->mPagetitle;
    $app_params['page_type'] = 'Special Page';

    //print_r($wgOut);
	// Log the request
	$owa = owa_factory();
	$owa->log($app_params);
	
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
	
	$app_params['user_name']= $wgUser->mName;
    $app_params['user_email'] = $wgUser->mEmail;
    $app_params['page_title'] = $wgOut->mPagetitle;
    $app_params['page_type'] = 'Category';
	
	// Log the request
	$owa = owa_factory();
	$owa->log($app_params);
	
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
	$app_params['user_name']= $wgUser->mName;
    $app_params['user_email'] = $wgUser->mEmail;
    $app_params['page_title'] = $article->mTitle->mTextform;
    $app_params['page_type'] = 'article';
    
	// Log the request
	$owa = owa_factory();
	$owa->log($app_params);
	
	return true;
	
}

/**
 * Adds first hit web bug to Article Pages if needed
 *
 * @param object $article
 * @return boolean
 */
function owa_footer(&$article) {
	
	global $wgOut;
	$owa = owa_factory();
	$tags = $owa->placeHelperPageTags(false);
	
	$wgOut->addHTML($tags);
		
	return true;
}


/* Special Page Class
 * 
 */
class SpecialOwa extends SpecialPage {

        function SpecialOwa() {
                SpecialPage::SpecialPage('Owa','',true);
                self::loadMessages();
        }

        function execute() {
                global $wgRequest, $wgOut, $wgUser, $wgSitename, $wgScriptPath, $wgScript, $wgServer;
                
                $this->setHeaders();
                $owa = owa_factory();
                # Get request data from, e.g.
               
           		// sets authentication priviledges
           		owa_set_priviledges();
                
                $params = array();
                
                // check to see that owa in installed.
                if (empty($owa->config['install_complete'])):

                	$site_url = $wgServer.$wgScriptPath;
                	
                	$params = array('site_id' => md5($site_url), 
    							'name' => $wgSitename,
    							'domain' => $site_url, 
    							'description' => '',
    							'do' => 'base.installStartEmbedded');
    							
                elseif (empty($owa->params['do'])):
                	if (empty($owa->params['view'])):
                		$params['do'] = 'base.reportDashboard';
                	endif;
                endif;
                
				$page = $owa->handleRequest($params);

				// switch for output scenario
				if (empty($owa->config['install_complete'])):
					return $wgOut->addHTML($page);					
				else:
					$wgOut->disable();
					echo $page;
					return;
				endif;
                
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
        }
        
}



?>