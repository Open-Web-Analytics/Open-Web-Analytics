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

require_once('owa_php.php');

// Turn MediaWiki Caching Off
global $wgCachePages, $wgCacheEpoch;
$wgCachePages = false;
$wgCacheEpoch = 'date +%Y%m%d%H%M%S';

// Register Extension with MediaWiki
$wgExtensionFunctions[] = 'owa_main';
$wgExtensionCredits['other'][] = array( 'name' => 'Open Web Analytics for MediaWiki', 
										'author' => 'Peter Adams <peter@openwebanalytics.com>', 
										'url' => 'http://www.openwebanalytics.com' );

    
/**
 * Main Media Wiki Extension method
 *
 */
function owa_main() {
	global $wgHooks;

	// Create Instance of OWA
	$owa = new owa_php;

	// Hook for logging Article Page Views
	$wgHooks['ArticlePageDataAfter'][] = 'owa_logArticle';
	$wgHooks['SpecialPageExecuteAfterPage'][] = 'owa_logSpecialPage';
	$wgHooks['CategoryPageView'][] = 'owa_logCategoryPage';
	
	// Hooks for adding first_hit request handler
	if ($owa->config['delay_first_hit'] == true):
		$wgHooks['ArticlePageDataAfter'][] = 'owa_footer';
		$wgHooks['SpecialPageExecuteAfterPage'][] = 'owa_footer';
	$wgHooks['CategoryPageView'][] = 'owa_footer';
	endif;
	
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
	$owa = &new owa_php;
	$owa->logEvent('page_request', $app_params);
	
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
	$owa = &new owa_php;
	$owa->logEvent('page_request', $app_params);
	
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
	$owa = &new owa_php;
	$owa->logEvent('page_request', $app_params);
	
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
	
	$owa = &new owa_php;
	$bug = $owa->placePageTags($echo = false);
	
	$wgOut->addHTML($bug);
		
	return true;
}