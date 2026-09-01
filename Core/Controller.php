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
 * Abstract Controller Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Controller extends \OWA\Core\Base {

    /**
     * Request Parameters passed in from caller
     *
     * @var array
     */
    var $params = array();

    /**
     * Controller Type
     *
     * @var array
     */
    var $type;

    /**
     * Is the controller for an admin function
     *
     * @var boolean
     */
    var $is_admin;

    /**
     * The priviledge level required to access this controller
     *
     * @var string
     */
    var $priviledge_level;

    /**
     * data validation control object
     *
     * @var Object
     */
    var $v;

    /**
     * Data container
     *
     * @var Array
     */
    var $data = array();

    /**
     * Capability
     *
     * @var string
     */
    var $capability;

    /**
     * Available Views
     *
     * @var Array
     */
    var $available_views = array();

    /**
     * Time period
     *
     * @var Object
     */
    var $period;

    /**
     * Dom id
     *
     * @var String
     */
    var $dom_id;

    /**
     * Flag for requiring authenciation before performing actions
     *
     * @var Bool
     */
    var $authenticate_user;

    var $state;

    /**
     * Flag for requiring nonce before performing write actions
     *
     * @var Bool
     */
    var $is_nonce_required = false;

    /**
     * Constructor
     *
     * @param array $params
     */
    function __construct($params) {

        // call parent constructor to setup objects.
        parent::__construct();

        // set request params
        $this->params = $params;
        
        // set param validators
		$this->validate();
		
        // set the default view method
        $this->setViewMethod('delegate');

        // clobber anything that needs clobbering by conrete class
        $this->init();
    }
    
    /**
	 * Abstract method for setting any controller configuration.
	 * Fires after constructor but before doAction.
	 *
	 */
    function init() {
	    
	    return false;
    }
    
    
    /**
	 * Method used to set param validators by concrete class
	 *
	 */
    function validate() {
	    
    }
	
	function updateAction() {
		
		$error_msg = 'Cannot perform action. OWA Updates required.';
	                
        \OWA\Core\CoreAPI::debug( $error_msg );
        
        switch ( $this->getMode() ) {
            
            case 'cli':
            	
            	$this->set('error_msg', $error_msg );
            	$this->setView('base.genericCli');
            	$this->set( 'msgs', $error_msg );
            	
            	break;
            	
            case 'web_app':
            	
            	// reset data
            	$this->data = array();
            	//redirect browser to update page
            	$this->setRedirectAction( 'base.updates' );
                    
            	break;
            	
            case 'rest_api':
            
            	$this->set('error_msg', $error_msg );
            	$this->setView('base.restApi');
            
            	break;
        }
        
        return $this->data;
	}
    /**
     * Handles request from caller
     *
     */
    function doAction() {

        \OWA\Core\CoreAPI::debug('Performing Action: '.get_class($this));

        // check if the schema needs to be updated and force the update
        // not sure this should go here...
        if ($this->is_admin === true) {
            // do not intercept if its the updatesApply action or a re-install else updates will never apply
            $do = $this->getParam('do');
            
            if ($do != 'base.updatesApply' && !defined('OWA_INSTALLING') && !defined('OWA_UPDATING')) {

                if ( \OWA\Core\CoreAPI::isUpdateRequired() ) {
	            	
	            	return $this->updateAction();
	            }
            }
        }
       
        /* CHECK USER FOR CAPABILITIES */
        if ( ! $this->checkCapabilityAndAuthenticateUser( $this->getRequiredCapability() ) ) {
            
            return $this->data;
        }
		
        /* Check validity of nonce */
        
        // certain web app controllers require nonce verification
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'request_mode' ) === 'web_app' ) {
	        
	        if ($this->is_nonce_required == true) {
		        
	            $nonce = $this->getParam('nonce');
	
	            if (!$nonce || !$this->verifyNonce($nonce)) {
	                $this->e->debug('Nonce is not valid.');
	                return $this->finishActionCall($this->nonceFailedAction());
	            }
	        }
		}
        
        // if the rest api is originating within the web app then we always need to check for a nonce
        // The only way to tell if that's the case is to check if the request was auth'd using "cookies"
        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'request_mode' ) === 'rest_api' ) {
            
            $auth = \OWA\Core\Auth::get_instance();
            
            if ( $auth->getAuthMethod() === 'cookies' ) {
                
                $nonce = $this->getParam('nonce');
                \OWA\Core\CoreAPI::debug( "REST API Nonce: $nonce");
                \OWA\Core\CoreAPI::debug( $this->get('version') . $this->get('module') . $this->get('do') );
                if ( ! $nonce || ! $this->verifyNonce( $nonce, $this->get('version') . $this->get('module') . $this->get('do') ) ) {
                
                    $this->e->debug('Nonce is missing or invalid.');
                    return $this->finishActionCall($this->notAuthenticatedAction());
                }
            }
        }
        
        // TODO: These sets need to be removed and added to pre(), action() or post() methods
        // in various concrete controller classes as they screw up things when
        // redirecting from one controller to another.

        // set auth status for downstream views
        //$this->set('auth_status', true);
        //set request params
        $this->set('params', $this->params);
        // set site_id
        $this->set('site_id', $this->get('site_id'));

        /*
         * A status_code on the query string reports the outcome of the action
         * that redirected here, so it belongs to THAT action -- not to whatever
         * the user does next from the same URL.
         *
         * Carrying it onto a POST showed two contradictory messages at once.
         * A completed password reset redirects to
         * '...do=base.loginForm&status_code=3006', the login form posts back to
         * the URL it was served from, and so a failed login rendered "your
         * password has been changed" beside "login failed" -- one describing the
         * reset, one describing the login, with nothing to say which was which.
         *
         * A redirect always arrives as a GET, so every legitimate use still
         * works; a POST is a new action and reports its own outcome.
         */
        if (array_key_exists('status_code', $this->params)
            && \OWA\Core\CoreAPI::serviceSingleton()->request->getRequestType() !== 'POST') {

            $this->set('status_code', $this->getParam('status_code'));
        }

        // get error msg from error code passed on the query string from a redirect.
        if (array_key_exists('error_code', $this->params)) {
            $this->set('error_code', $this->getParam('error_code'));
        }

        // check to see if the controller has created a validator
        if (!empty($this->v)) {
            // if so do the validations required
            $this->v->doValidations();
    
            //check for errors
            if ($this->v->hasErrors === true) {
                //print_r($this->v);
                // if errors, do the errorAction instead of the normal action
                $this->set('validation_errors', $this->getValidationErrorMsgs());
              
                $this->errorAction();
                
                return $this->data;
            }
        }

        /* PERFORM PRE ACTION */
        // often used by abstract descendant controllers to set various things
        $this->pre();
        /* PERFORM MAIN ACTION */
           return $this->finishActionCall($this->action());
    }

    /**
     * Checks for the action result, calls the post method and returns correct result
     * Usage return $this->finishActionCall($this->action()))
     * @return mixed
     */
    protected function finishActionCall($actionResult) {
        // need to check ret for backwards compatability with older
        // controllers that donot use $this->data
        if (!empty($actionResult)) {

            $this->post();
            return $actionResult;
        } else {
	        // set output realted params like view, etc.
	        $this->success();
            $this->post();
            return $this->data;
        }
    }
    
    /**
	 * set output realted params like view, etc.
	 * called after action because older style controllers might set these details within action()
	 */
    function success() {
	    
    }

    /**
     * Checks if the current controller requires privileges and authenticates the user and checks for capabilities
     * If the user is not allowed the correct error view is also initialized and the calling method should return
     * @uses ->getRequiredCapability and ->getCurrentSiteId
     * @param string $capability
     * @return boolean
     */
     
    // second conditional is needed to force an authentication even when capability is added to "everyone" role.
    // ideally this auth check should happen earlier by I believe there is a race condtion so this might be the
    // earliest it can happen. The u and p params will only be present if the user has logged in.
    protected function checkCapabilityAndAuthenticateUser($capability) {
        if ( ( !empty($capability) && ! \OWA\Core\CoreAPI::isEveryoneCapable( $capability ) ) || ( \OWA\Core\CoreAPI::getStateParam('u') && \OWA\Core\CoreAPI::getStateParam('p') ) ) {
            /* PERFORM AUTHENTICATION */
            $auth = \OWA\Core\Auth::get_instance();
            if (!\OWA\Core\CoreAPI::isCurrentUserAuthenticated()) {
                $status = $auth->authenticateUser();
                if ($status['auth_status'] != true) {
                    $this->notAuthenticatedAction();
                    return false;
                }
            }

            $currentUser = \OWA\Core\CoreAPI::getCurrentUser();
            if (!$currentUser->isCapable($this->getRequiredCapability(),$this->getCurrentSiteId())) {
                \OWA\Core\CoreAPI::debug('User does not have capability required by this controller.');
                $this->authenticatedButNotCapableAction();
                //needed?
                //$this->set('go', urlencode(owa_lib::get_current_url()));
                // needed? -- set auth status for downstream views
                //$this->set('auth_status', true);
                return false;
            }

        }
        return true;
    }

    // needed?
    protected function isEveryoneCapable($capability) {

        return \OWA\Core\CoreAPI::isEveryoneCapable( $capability );
    }

    function logEvent($event_type, $properties) {

        $ed = \OWA\Core\CoreAPI::getEventDispatch();

        if (!is_a($properties, \OWA\Module\Base\Classes\Event::class)) {

            $event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
            $event->setProperties($properties);
            $event->setEventType($event_type);
        } else {
            $event = $properties;
        }

        return $ed->notify( $event );
    }

    function createValidator() {

        $this->v = \OWA\Core\CoreAPI::supportClassFactory('base', 'validator');
    }

    function addValidation($name, $value, $validation, $conf = array()) {

        if ( empty( $this->v ) ) {

            $this->createValidator();
        }

        return $this->v->addValidation($name, $value, $validation, $conf);

    }

    function setValidation($name, $obj) {

        if (empty($this->v)) {
            $this->createValidator();
        }

        return $this->v->setValidation($name, $obj);
    }

    function getValidationErrorMsgs() {

        return $this->v->getErrorMsgs();

    }

    function isAdmin() {

        if ($this->is_admin == true) {
            return true;
        }
    }

    // depricated
    function _setCapability($capability) {

        $this->setRequiredCapability($capability);
    }

    function setRequiredCapability($capability) {

        $this->capability = $capability;
    }

    function getRequiredCapability() {

        return $this->capability;
    }

    function getParam($name) {

        if (array_key_exists($name, $this->params)) {
            return $this->params[$name];
        }
    }

    function setParam($name, $value) {

        $this->params[$name] = $value;
    }

    function isParam($name) {

        if (array_key_exists($name, $this->params)) {
            return true;
        }
    }

    function get($name) {

        return $this->getParam($name);
    }

    function getAllParams() {

        return $this->params;
    }

    function pre() {

        return false;
    }

    function post() {
        return false;
    }

    function getPeriod() {

        return $this->period;
    }

    function setPeriod() {
        // set period

        $period = $this->makeTimePeriod($this->getParam('period'), $this->params);

        $this->period = $period;
        $this->set('period', $this->getPeriod());
        $this->data['params'] = array_merge($this->data['params'], $period->getPeriodProperties());
    }

    function makeTimePeriod($time_period, $params = array()) {

        return \OWA\Core\CoreAPI::makeTimePeriod($time_period, $params);
    }

    function setTimePeriod($period) {

        $this->period = $period;
        $this->set('period', $this->getPeriod());
        //$this->data['params'] = array_merge($this->data['params'], $period->getPeriodProperties());
    }


    function setView($view) {

        $this->data['view'] = $view;
    }

    function setSubview($subview) {

        $this->data['subview'] = $subview;

    }

    function setViewMethod($method = 'delegate') {

        $this->data['view_method'] = $method;

    }

    function setRedirectAction($do) {

        $this->set('view_method', 'redirect');
        $this->set('do', $do);
		
		$new_data = [
			
			'do' 			=> $do,
			'view_method'	=> 'redirect'
		];
		
/*
		if ( array_key_exists('status_code', $this->data) && ! empty($this->data['status_code'] ) ) {
			
			$new_data['status_code'] = $this->data['status_code'];
		}
		
		if ( array_key_exists('error_code', $this->data) && ! empty($this->data['error_code'] ) ) {
			
			$new_data['error_code'] = $this->data['error_code'];
		}
*/
		
		foreach ($this->data as $k => $param) {
			
			if ( ! is_array( $param ) || ! is_object($param) ) {
				
				$new_data[$k] = $param;
			}
		}
		\OWA\Core\CoreAPI::debug('setredirectAction');
		\OWA\Core\CoreAPI::debug( $new_data);
		$this->data = $new_data;		

        // need to remove these unsets once they are no longer set in the main doAction method
        if (array_key_exists('params', $this->data)) {
            unset($this->data['params']);
        }

    }

    function setPagination($pagination, $name = 'pagination') {

        $this->data[$name] = $pagination;

    }

    function set($name, $value) {

        $this->data[$name] = $value;
    }
	
	/**
	 * Sets the type of controler
	 * @depricated
	 * @todo remove this
	 */
    function setControllerType($string) {

        $this->type = $string;

    }
    
    public function getMode() {
	    
	    return \OWA\Core\CoreAPI::getSetting( 'base', 'request_mode' );
    }

    function mergeParams($array) {

        $this->params = array_merge($this->params, $array);

    }

    /**
     * redirects borwser to a particular view
     *
     * @param string $action
     * @param bool $pass_params
     */
    function redirectBrowser($action, $pass_params = true) {

        $control_params = array('view_method', 'auth_status');

        $get = '';

        $get .= \OWA\Core\CoreAPI::appNs().'do'.'='.$action.'&';

        if ($pass_params === true) {

            foreach ($this->data as $n => $v) {

                if (!in_array($n, $control_params)) {

                    $get .= \OWA\Core\CoreAPI::appNs().$n.'='.$v.'&';

                }
            }
        }

        $new_url = sprintf(\OWA\Core\CoreAPI::getSetting('base', 'link_template'), \OWA\Core\CoreAPI::getSetting('base', 'main_url'), $get);

        return \OWA\Core\Lib::redirectBrowser($new_url);

    }

    function redirectBrowserToUrl($url) {

        return \OWA\Core\Lib::redirectBrowser($url);
    }

    function setStatusCode($code) {

        $this->data['status_code'] = $code;
    }

    function setStatusMsg($msg) {

        $this->data['status_message'] = $msg;
    }

    function setErrorMsg( $msg ) {

        $this->set( 'error_msg', $msg );
    }

    function authenticatedButNotCapableAction($additionalMessage = '') {
        if ( empty($additionalMessage) ) {
            $siteIdMsg = $this->getCurrentSiteId();
            if ( empty ($siteIdMsg) ) {
                $siteIdMsg = 'No access to any site for the permission "'.$this->getRequiredCapability().'"';
            }
            $additionalMessage = $siteIdMsg;
        }
        $msg = $this->getMsg(2003);
        $msg['message'] .= $additionalMessage;
        $this->setView('base.error');
        $this->set('error_msg', $msg);
    }

    /**
     * The page the browser came from, when it belongs to this installation.
     *
     * Used to resume someone at the screen that offered a blocked action rather
     * than dumping them on start_page. Browsers send the full URL on a
     * same-origin request, which is what an admin form post is, so this is
     * normally the page that rendered the form.
     *
     * The value is client-supplied, so it is resolved against the installation
     * exactly like any other redirect target, and discarded if it does not
     * belong to it. A referrer pointing back at the current request is discarded
     * too -- resuming that would fail the same check all over again.
     *
     * @return string The referring page, or '' when there is nothing usable.
     */
    protected function getReferringPage() {

        $referer = isset( $_SERVER['HTTP_REFERER'] ) ? trim( (string) $_SERVER['HTTP_REFERER'] ) : '';

        if ( ! $referer ) {

            return '';
        }

        // resolveRedirectUrl() substitutes the base URL for anything outside the
        // installation, so a value it did not return unchanged was not ours.
        if ( \OWA\Core\Lib::resolveRedirectUrl( $referer ) !== $referer ) {

            return '';
        }

        if ( $referer === \OWA\Core\Lib::get_current_url() ) {

            return '';
        }

        // Coming from the login screen itself means the previous request was
        // already turned away. Resuming it would land the user back on the form
        // they just completed.
        if ( strpos( $referer, 'base.login' ) !== false ) {

            return '';
        }

        return $referer;
    }

    /**
     * Answers a request whose nonce was missing or did not verify.
     *
     * A nonce failure and a missing session are different conditions and were
     * answered the same way -- with the login form. For someone who is already
     * signed in that is untrue and unactionable: they re-enter credentials that
     * were never the problem, which is what made the report on #979 read as an
     * authentication failure rather than an expired token.
     *
     * A nonce carries a time window and the user_id it was minted for, so it can
     * lapse for a perfectly valid session: a form left open too long, or one
     * rendered before signing in as someone else.
     *
     * The action is deliberately not retried once a fresh nonce could be minted.
     * The nonce exists so that a state-changing request is one the user just
     * confirmed, and completing it on their behalf would defeat that.
     */
    function nonceFailedAction() {

		// Not signed in: the nonce is beside the point, credentials come first.
		if ( ! \OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated() ) {

			return $this->notAuthenticatedAction();
		}

		if (\OWA\Core\CoreAPI::getSetting('base', 'request_mode') === 'rest_api') {

			$this->setView('base.restApi');
			$this->set('error_msg', $this->getMsg(2005));
			http_response_code(403);

		} else {

			$this->setView('base.error');

			// generic_error.php echoes error_msg directly, so it has to be a
			// string -- handing it the getMsg() array renders as "Array".
			$msg = $this->getMsgAsString(2005);

			// Telling someone to go back to where they started is only useful if
			// they are given the way back. The screen that rendered the expired
			// form is the referring page, and it will mint a fresh nonce.
			$back = $this->getReferringPage();

			if ( $back ) {

				// $back is client-supplied and lands in an href, so it is escaped
				// as an attribute rather than trusted the way a built URL is.
				$msg .= sprintf(
					' <a href="%s">Return to the previous screen</a>',
					htmlspecialchars( $back, ENT_QUOTES, 'UTF-8' )
				);
			}

			$this->set('error_msg', $msg);
		}
    }

    function notAuthenticatedAction() {
		
		if (\OWA\Core\CoreAPI::getSetting('base', 'request_mode') === 'rest_api') {
			
			$this->setView('base.restApi');
			$this->set('error_msg', ['headline'	=> 'Not authenticated.', 'msg' => 'Check API credentials or permissions for this user.'] );
			http_response_code(401);	
		} else {
	        $this->setRedirectAction('base.loginForm');

			// 'go' resumes the blocked request after login, which is only safe for
			// a destination that just renders something.
			//
			// A nonce is derived from the current user_id, so one minted before
			// login can never verify after it -- the request would bounce straight
			// back to this form, looking to the user like their credentials were
			// refused. Re-minting it after login is not the fix: that would let a
			// crafted link name any action and have the server bless it, which is
			// the CSRF the nonce exists to prevent.
			//
			// So a state-changing action is never resumed. What can be resumed is
			// the page that OFFERED it -- the referring page, which will mint a
			// fresh nonce for the authenticated identity. That is a destination
			// that renders rather than one that writes, which is the distinction
			// that matters here.
			//
			// is_nonce_required marks exactly that set: every controller that sets
			// it is a write (add/edit/delete/activate/update/apply). No report or
			// view controller does, so deep-linking to a report still resumes.
			if ( ! $this->is_nonce_required ) {

				$this->set('go', urlencode(\OWA\Core\Lib::get_current_url()));

			} else {

				// A state-changing action is never resumed: the nonce is derived
				// from user_id, so one minted before signing in cannot verify
				// afterwards, and re-minting it would let a crafted link name any
				// action and have the server bless it. Login falls through to
				// start_page and the user re-initiates from a screen that mints a
				// nonce for their authenticated identity.
				$this->set('go', '');
			}
		}
    }

    function verifyNonce($nonce, $action = '') {
            
        $action = $action ?: $this->getParam('do') ?: $this->getParam('action');

        $matching_nonce = \OWA\Core\CoreAPI::createNonce($action);
        \OWA\Core\CoreAPI::debug("passed nonce: $nonce | matching nonce: $matching_nonce");
        if ($nonce === $matching_nonce) {
            return true;
        }
    }

    /**
     * Sets nonce flag for the controller.
     */
    function setNonceRequired() {

        $this->is_nonce_required = true;
    }

    function getSetting($module, $name) {
        return \OWA\Core\CoreAPI::getSetting($module, $name);
    }


    /**
     * Returns array of owa_site entities where the current user has access to, taken the current controller cap into account
     * @return array
     */
    /**
     * Group Observation Profiles under the Property they belong to.
     *
     * A Property is the website; a Profile is one way of observing it, and it
     * is the Profile that carries the tracker id. Several Profiles on one
     * Property is the point of the hierarchy -- and it is also what makes a
     * flat list unreadable, because two Profiles of the same site legitimately
     * share a domain and differ only by an auto-assigned name:
     *
     *     Observation Profile 1 | example.com
     *     Observation Profile 2 | example.com
     *
     * Grouped, the Property name supplies the context those labels lack. This
     * is presentation only -- the Profile's own name is untouched, so nothing
     * that reads it (the /v1/sites payload, and the plugin picker built on it)
     * changes shape or value.
     *
     * Each distinct Property is loaded once and reused across the Profiles
     * that share it. base.property is cachable, so a repeat render costs
     * nothing, and the surrounding code already loads a site per row -- this
     * does not change the order of the work. It is deliberately not a single
     * IN query: the Db layer has no IN operator, and inlining one here would
     * mean building a value list by hand in a class that otherwise never does.
     *
     * @param array $sites site_id => base.site entity
     * @return array property label => array of site entities, in the order given
     */
    protected function groupSitesByProperty( $sites ) {

        $sites = (array) $sites;

        if ( ! $sites ) {

            return array();
        }

        $names = array();

        $grouped = array();

        foreach ( $sites as $key => $site ) {

            $id = $site->get( 'property_id' );

            /*
             * A Profile with no Property is not dropped. It can exist -- a site
             * created before the migration, or by a path that does not assign
             * one -- and silently omitting it would remove a site from the
             * selector, which is worse than showing it under a plain heading.
             */
            if ( $id && ! array_key_exists( $id, $names ) ) {

                $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
                $property->load( $id );
                $names[ $id ] = (string) $property->get( 'name' );
            }

            /*
             * A Profile with no Property is not dropped. It can exist -- a site
             * created before the migration, or by a path that does not assign
             * one -- and silently omitting it would remove a site from the
             * selector, which is worse than showing it under a plain heading.
             */
            $label = ( $id && ! empty( $names[ $id ] ) )
                ? $names[ $id ]
                : ( $site->get( 'domain' ) ?: 'Unassigned' );

            $grouped[ $label ][ $key ] = $site;
        }

        return $grouped;
    }

    protected function getSitesAllowedForCurrentUser() {
   
        $currentUser = \OWA\Core\CoreAPI::getCurrentUser();

        if ( $currentUser->isAnonymousUser() || $currentUser->isAdmin() ) {
            $result = array();
           
            $relations = \OWA\Core\CoreAPI::getSitesList();

            foreach ($relations as $siteRow) {

                $site = \OWA\Core\CoreAPI::entityFactory('base.site');
                $site->load($siteRow['id']);
                $result[$siteRow['site_id']] = $site;
            }
 
            return $result;

        } else {
	        
            return $currentUser->getAssignedSites();
        }
    }

    /**
     * gets the siteid taking the site access permissions into account
     * If not a typical siteId parameter is set or user lacks permission, the first availabe site is used
     *
     * @return int|string|false the site id, or false if no site access
     */
    protected function getCurrentSiteId() {

        $siteParameterValue = $this->getSiteIdParameterValue();
        return $siteParameterValue;
    }

    /**
     * @return int|string|false
     */
    protected function getSiteIdParameterValue() {
        if ($this->getParam('siteId') ) {
            return $this->getParam('siteId');
        }
        elseif ($this->getParam('site_id') ) {
            return $this->getParam('site_id');
        }
        return false;
    }

}

?>