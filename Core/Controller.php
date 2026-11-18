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
 *
 * @phpstan-import-type SettingsEntry from \OWA\Core\Template
 */

class Controller extends \OWA\Core\Base {

    /**
     * Request Parameters passed in from caller
     *
     * @var array<mixed>
     */
    public $params = [];

    /**
     * Controller Type
     *
     * @var string
     */
    public $type;

    /**
     * Is the controller for an admin function
     *
     * @var boolean
     */
    public $is_admin;

    /**
     * The priviledge level required to access this controller
     *
     * @var string
     */
    public $priviledge_level;

    /**
     * data validation control object, created by the first validation added
     *
     * @var \OWA\Module\Base\Classes\Validator|null
     */
    public $v;

    /**
     * Data container
     *
     * @var array<mixed>
     */
    public $data = [];

    /**
     * Capability
     *
     * @var string
     */
    public $capability;

    /**
     * Time period
     *
     * @var Object
     */
    public $period;

    /**
     * Dom id
     *
     * @var String
     */
    public $dom_id;

    /** @var mixed nothing sets it */
    public $state;

    /**
     * Flag for requiring nonce before performing write actions
     *
     * @var Bool
     */
    public $is_nonce_required = false;

    /**
     * The authentication methods this controller declares, or null for its
     * class's default. See setAuthMethods() and acceptedAuthMethods().
     *
     * @var string[]|null
     */
    protected $auth_methods = null;

    /**
     * Constructor
     *
     * @param array<mixed> $params
     */
    public function __construct($params) {

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
     * @return mixed not read
     */
    public function init() {
        
        return false;
    }
    
    
    /**
     * Method used to set param validators by concrete class
     *
     * @return void
     */
    public function validate() {
        
    }
    
    /**
     * The answer while updates are pending: an error for the CLI and the REST
     * API, a redirect to the updates screen for the web app.
     *
     * @return mixed this controller's data
     */
    public function updateAction() {
        
        $error_msg = \OWA\Core\CoreAPI::t( 'Cannot perform action. OWA Updates required.' );
                    
        \OWA\Core\CoreAPI::debug( $error_msg );
        
        switch ( $this->getMode() ) {
            
            case \OWA\Core\RequestType::CLI:
                
                $this->set('error_msg', $error_msg );
                $this->setView('base.genericCli');
                $this->set( 'msgs', $error_msg );
                
                break;
                
            case \OWA\Core\RequestType::REST:
            
                $this->set('error_msg', $error_msg );
                $this->setView('base.restApi');
            
                break;

            default:
                
                // reset data
                $this->data = [];
                //redirect browser to update page
                $this->setRedirectAction( 'base.updates' );
                    
                break;
        }
        
        return $this->data;
    }
    /**
     * Handles request from caller
     *
     * @return mixed this controller's data, or the non-empty answer of the
     *               action, its error action or a refusal
     */
    public function doAction() {

        \OWA\Core\CoreAPI::debug('Performing Action: '.get_class($this));

        // check if the schema needs to be updated and force the update
        // not sure this should go here...
        if ($this->is_admin === true && $this->needsCurrentSchema()) {
            // do not intercept if its the updatesApply action or a re-install else updates will never apply
            $do = $this->getParam('do');
            
            if ($do != 'base.updatesApply' && !defined('OWA_INSTALLING') && !defined('OWA_UPDATING')) {

                if ( $this->pendingUpdateStopsAction() ) {

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
        if ( \OWA\Core\RequestType::isWebApp() ) {
            
            if ($this->is_nonce_required == true) {
                
                $nonce = $this->getParam('nonce');
    
                if (!$nonce || !$this->verifyNonce($nonce)) {
                    \OWA\Core\CoreAPI::debug('Nonce is not valid.');
                    return $this->refused($this->nonceFailedAction());
                }
            }
        }
        
        // if the rest api is originating within the web app then we always need to check for a nonce
        // The only way to tell if that's the case is to check if the request was auth'd using "cookies"
        if ( \OWA\Core\RequestType::is( \OWA\Core\RequestType::REST ) ) {
            
            $auth = \OWA\Core\Auth::get_instance();
            
            if ( $auth->getAuthMethod() === 'cookies' ) {
                
                $nonce = $this->getParam('nonce');
                \OWA\Core\CoreAPI::debug( "REST API Nonce: $nonce");
                $action = \OWA\Core\CoreAPI::restApiNonceAction( $this->get('version'), $this->get('module'), $this->get('do'),
                    \OWA\Core\CoreAPI::serviceSingleton()->request->getRequestType() );
                \OWA\Core\CoreAPI::debug( $action );
                if ( ! $nonce || ! $this->verifyNonce( $nonce, $action ) ) {
                
                    \OWA\Core\CoreAPI::debug('Nonce is missing or invalid.');
                    return $this->refused($this->notAuthenticatedAction());
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

            $this->setStatusCode( $this->getParam('status_code') );
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

                /*
                 * WHAT errorAction() RETURNS IS USED, not discarded.
                 *
                 * This called errorAction() for its side effects and then
                 * returned $this->data regardless, while the success path below
                 * hands back whatever action() returns. So a controller that
                 * answers a refusal by DELEGATING -- building another
                 * controller and returning its doAction() -- had that answer
                 * thrown away, and the caller rendered this controller's own
                 * data, which for a write that redirects on success names no
                 * view at all. The result was a blank page:
                 * base.customReportSave did exactly this for a missing report
                 * name, every time.
                 *
                 * Only the return value changes. success() is NOT called here,
                 * unlike finishActionCall(): most controllers override it to
                 * set the view for the SUCCESS case, and running it now would
                 * overwrite whatever errorAction() just set. post() is not
                 * called either -- ReportController overrides it and returns
                 * $this->data from errorAction(), so calling it would add a
                 * side effect to a path that has never had one.
                 *
                 * Every errorAction() that returns nothing therefore behaves
                 * exactly as before.
                 */
                $error_result = $this->errorAction();

                if ( ! empty( $error_result ) ) {

                    return $error_result;
                }

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
     * Answers a request the controller declined to act on.
     *
     * success() is NOT called. Controllers override it to describe the outcome
     * of an action that ran -- base.usersAdd redirects to the user list with
     * "User added", a REST create answers 201 -- so routing a refused nonce
     * through finishActionCall() reported a write that never happened, and
     * replaced the refusal's own view and status code. post() is skipped for
     * the same reason the validation-error path skips it.
     *
     * @param mixed $handlerResult what the refusal handler returned
     * @return mixed
     */
    protected function refused( $handlerResult ) {

        return ! empty( $handlerResult ) ? $handlerResult : $this->data;
    }

    /**
     * Checks for the action result, calls the post method and returns correct result
     * Usage return $this->finishActionCall($this->action()))
     * @param mixed $actionResult what action() returned
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
     *
     * @return mixed not read
     */
    public function success() {
        
    }

    /**
     * The controller's work, run by doAction() once every check has passed.
     * The default does nothing.
     *
     * @return mixed a non-empty answer is doAction()'s
     */
    public function action() {

    }

    /**
     * Runs in place of action() when validation fails. A non-empty return is
     * doAction()'s answer. The default shows the validation messages on the
     * error page.
     *
     * @return mixed a non-empty answer is doAction()'s
     */
    public function errorAction() {

        $this->setView( 'base.error' );
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
        $needs_user = ! empty( $capability ) && ! \OWA\Core\CoreAPI::isEveryoneCapable( $capability );

        if ( $needs_user || ( \OWA\Core\CoreAPI::getStateParam('u') && \OWA\Core\CoreAPI::getStateParam('p') ) ) {
            /* PERFORM AUTHENTICATION */
            $auth = \OWA\Core\Auth::get_instance();
            if (!\OWA\Core\CoreAPI::isCurrentUserAuthenticated()) {
                $status = $auth->authenticateUser( $this->acceptedAuthMethods() );
                if ($status['auth_status'] != true) {

                    /*
                     * A session cookie that no longer signs anyone in, on a
                     * screen anyone may open. Refusing it sent the browser to
                     * the login form, which is such a screen, so the form
                     * refused it again and redirected to itself until the URL
                     * was too long to serve. The cookie is cleared and the
                     * request goes on signed out.
                     */
                    if ( ! $needs_user ) {
                        if ( ! $auth->refusedLegacyApiKey() ) {
                            $auth->deleteCredentials();
                        }
                        return true;
                    }

                    $this->notAuthenticatedAction();
                    return false;
                }
            } elseif ( $this->acceptedAuthMethods() !== null
                       && $auth->getAuthMethod()
                       && ! in_array( $auth->getAuthMethod(), $this->acceptedAuthMethods(), true ) ) {
                /*
                 * Signed in earlier in this request by a credential this
                 * controller does not accept. A user signed in with no method
                 * was put there by code in this process -- the CLI, a module's
                 * auth_status filter, a host embedding OWA -- not by anything
                 * the request carried, which is what the list governs.
                 */
                $this->notAuthenticatedAction();
                return false;
            }

            $currentUser = \OWA\Core\CoreAPI::getCurrentUser();
            if ( ! $this->isCurrentUserCapable( $currentUser ) ) {
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

    /**
     * Whether the user holds this controller's capability for what the request
     * is about. A site, here; ReportController answers for a Property.
     *
     * @param  \OWA\Module\Base\Classes\ServiceUser $currentUser
     * @return bool
     */
    protected function isCurrentUserCapable( $currentUser ) {

        return (bool) $currentUser->isCapable( $this->getRequiredCapability(), $this->getCurrentSiteId() );
    }

    /**
     * needed?
     *
     * @param  string $capability
     * @return bool
     */
    protected function isEveryoneCapable($capability) {

        return \OWA\Core\CoreAPI::isEveryoneCapable( $capability );
    }

    /**
     * @return void
     */
    public function createValidator() {

        $this->v = new \OWA\Module\Base\Classes\Validator();
    }

    /**
     * @param  string               $name
     * @param  mixed                $value
     * @param  string               $validation a validator, as CoreAPI::validationFactory() takes it
     * @param  array<string, mixed> $conf
     * @return null
     */
    public function addValidation($name, $value, $validation, $conf = []) {

        if ( empty( $this->v ) ) {

            $this->createValidator();
        }

        return $this->v->addValidation($name, $value, $validation, $conf);

    }

    /**
     * Fails validation of this field with this message: a required check of
     * an empty value, which always fails.
     *
     * @param  string $name
     * @param  string $msg
     * @return null
     */
    public function addValidationError( $name, $msg ) {

        return $this->addValidation( $name, '', 'required', [ 'errorMsg' => $msg ] );
    }

    /**
     * @param  string                    $name
     * @param  \OWA\Core\Validation\Validation $obj
     * @return null
     */
    public function setValidation($name, $obj) {

        if (empty($this->v)) {
            $this->createValidator();
        }

        return $this->v->setValidation($name, $obj);
    }

    /**
     * @return array<mixed>
     */
    public function getValidationErrorMsgs() {

        return $this->v->getErrorMsgs();

    }

    /**
     * Whether this action refuses to run while updates are pending. On a
     * server without a database (a collector) there is no schema version to
     * read, so nothing does.
     *
     * @return bool
     */
    public function needsCurrentSchema() {

        return \OWA\Core\ServerRole::current()->hasDatabase();
    }

    /**
     * Whether a pending update stops this action. An admin screen stops for any
     * pending update, so it shows the update notice. The CLI and the REST API
     * stop only for a schema behind the code: an update pending only for a new
     * tracker build (Base\Module::isUpToDate()) leaves the schema they need, so
     * the scheduler, the queue drain and the REST API keep running.
     *
     * @return bool
     */
    protected function pendingUpdateStopsAction() {

        if ( \OWA\Core\RequestType::isWebApp() ) {

            return (bool) \OWA\Core\CoreAPI::isUpdateRequired();
        }

        return (bool) \OWA\Core\CoreAPI::isSchemaUpdateRequired();
    }

    /**
     * @return true|void nothing when the controller is not an admin one
     */
    public function isAdmin() {

        if ($this->is_admin == true) {
            return true;
        }
    }

    /**
     * @param  string $capability
     * @return void
     */
    public function setRequiredCapability($capability) {

        $this->capability = $capability;
    }

    /**
     * @return string
     */
    public function getRequiredCapability() {

        return $this->capability;
    }

    /**
     * @param  string $name
     * @return mixed nothing when the request has no such param
     */
    public function getParam($name) {

        if (array_key_exists($name, $this->params)) {
            return $this->params[$name];
        }
    }

    /**
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function setParam($name, $value) {

        $this->params[$name] = $value;
    }

    /**
     * @param  string $name
     * @return true|void nothing when the request has no such param
     */
    public function isParam($name) {

        if (array_key_exists($name, $this->params)) {
            return true;
        }
    }

    /**
     * @param  string $name
     * @return mixed getParam()'s
     */
    public function get($name) {

        return $this->getParam($name);
    }

    /**
     * Runs before action(), once every check has passed.
     *
     * @return mixed not read
     */
    public function pre() {

        return false;
    }

    /**
     * Runs after action(), and after success() when action() answered nothing.
     *
     * @return mixed not read
     */
    public function post() {
        return false;
    }

    /**
     * @return Object|null null until setPeriod()
     */
    public function getPeriod() {

        return $this->period;
    }

    /**
     * @return void
     */
    public function setPeriod() {
        // set period

        $period = $this->makeTimePeriod($this->getParam('period'), $this->params);

        $this->period = $period;
        $this->set('period', $this->getPeriod());
        $this->data['params'] = array_merge($this->data['params'], $period->getPeriodProperties());
    }

    /**
     * @param  string               $time_period
     * @param  array<string, mixed> $params
     * @return \OWA\Module\Base\Classes\TimePeriod
     */
    public function makeTimePeriod($time_period, $params = []) {

        return \OWA\Core\CoreAPI::makeTimePeriod($time_period, $params);
    }


    /**
     * The view that renders the page.
     *
     * A view whose module ships no View class for it is the template declared
     * here (View::renderDeclaredTemplate()):
     *
     *   template  the file, from the view's module's templates
     *   vars      what the template reads: the name of one of this
     *             controller's values, or name => value
     *   wrapper   the page template, when not the report_wrapper setting's
     *   title     the page's <title>
     *
     * @param string $view     <module>.<view>
     * @param array<string, mixed> $declared as above, or nothing for a View class
     * @return void
     */
    public function setView($view, array $declared = []) {

        $this->data['view'] = $view;
        $this->declareTemplate( $view, $declared );
    }

    /**
     * Answers a REST request with the base.restApi view and this status code.
     * The response's data is what this controller set under $key, read when
     * the view renders (false when nothing was); with no key it has none.
     *
     * @param  int    $status
     * @param  string $key
     * @return void
     */
    protected function restResponse( $status, $key = '' ) {

        http_response_code( $status );
        $this->setView( 'base.restApi' );

        if ( $key !== '' ) {

            $this->set( 'response_key', $key );
        }
    }

    /**
     * The screen inside the page's view, declared as setView() declares a page:
     * template and vars. A subview has no wrapper or title of its own; the
     * page's are the outer view's.
     *
     * @param string $subview  <module>.<view>
     * @param array<string, mixed> $declared template and vars, or nothing for a View class
     * @return void
     */
    public function setSubview($subview, array $declared = []) {

        $this->data['subview'] = $subview;
        $this->declareTemplate( $subview, $declared );
    }

    /**
     * @param  string               $view     <module>.<view>
     * @param  array<string, mixed> $declared
     * @return void
     */
    private function declareTemplate( $view, array $declared ) {

        if ( $declared ) {

            $this->data['templates'][ $view ] = $declared;
        }
    }

    /**
     * @param  string $method delegate, or redirect
     * @return void
     */
    public function setViewMethod($method = 'delegate') {

        $this->data['view_method'] = $method;

    }

    /**
     * What is being looked at, as the request says it: the report link state
     * (View::LINK_STATE_KEYS) without siteId. A settings screen keeps these in
     * its links and every redirect carries them, so leaving the reports for
     * Settings and coming back changes neither. Not siteId, which names a
     * Profile: one a save may have just deleted.
     *
     * @return string[]
     */
    public static function reportState() {

        return array_values( array_diff( \OWA\Core\View::LINK_STATE_KEYS, [ 'siteId' ] ) );
    }

    /**
     * @param  string $do the action to redirect to
     * @return void
     */
    public function setRedirectAction($do) {

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
            
            // && , not || . An array satisfies ! is_object and an object
            // satisfies ! is_array, so with || every value passed and the
            // filter copied exactly what it was written to hold back.
            if ( ! is_array( $param ) && ! is_object( $param ) ) {
                
                $new_data[$k] = $param;
            }
        }

        /*
         * What is being looked at goes with every redirect -- the Property and
         * the period -- so a save lands on the screen it came from with the
         * same ones, an install-wide setting's included. A controller that
         * names another Property, or none (one it has just deleted), sets
         * propertyId itself.
         */
        foreach ( self::reportState() as $key ) {

            if ( ! array_key_exists( $key, $new_data ) && (string) $this->getParam( $key ) !== '' ) {

                $new_data[ $key ] = (string) $this->getParam( $key );
            }
        }

        \OWA\Core\CoreAPI::debug( 'setRedirectAction', $new_data );
        $this->data = $new_data;		

        // need to remove these unsets once they are no longer set in the main doAction method
        if (array_key_exists('params', $this->data)) {
            unset($this->data['params']);
        }

    }

    /**
     * @param  string $name
     * @param  mixed  $value
     * @return void
     */
    public function set($name, $value) {

        $this->data[$name] = $value;
    }
    
    /**
     * Sets the type of controler
     * @depricated
     * @todo remove this
     * @param  string $string
     * @return void
     */
    public function setControllerType($string) {

        $this->type = $string;

    }
    
    /**
     * This request's type (Core\RequestType).
     *
     * @return string
     */
    public function getMode() {
        
        return \OWA\Core\RequestType::current();
    }

    /**
     * @param  string   $url
     * @param  string[] $also_hosts see Lib::resolveRedirectUrl()
     * @return null
     */
    public function redirectBrowserToUrl($url, array $also_hosts = []) {

        return \OWA\Core\Lib::redirectBrowser($url, $also_hosts);
    }

    /**
     * @param  int|string $code a message code, as getMsg() takes it
     * @return void
     */
    public function setStatusCode($code) {

        $this->data['status_code'] = $code;
    }

    /**
     * The page for a signed-in user without the capability this one needs.
     *
     * What was refused goes in the banner's detail line, under the message.
     * In the web UI the page keeps the menu, its report links of the first
     * Profile the user may see, so there is a way on from it.
     *
     * @param  string $additionalMessage the detail line; what was refused when empty
     * @return mixed nothing here; a subclass may answer doAction()'s refusal
     */
    public function authenticatedButNotCapableAction($additionalMessage = '') {
        if ( empty($additionalMessage) ) {
            $siteId = $this->getCurrentSiteId();
            $additionalMessage = $siteId
                ? sprintf( \OWA\Core\CoreAPI::t( 'Profile: %s' ), $siteId )
                : sprintf( \OWA\Core\CoreAPI::t( 'No access to any Profile for the permission “%s”.' ), $this->getRequiredCapability() );
        }
        $msg = $this->getMsg(2003);
        $msg['detail'] = $additionalMessage;
        $this->setView('base.error');
        $this->set('error_msg', $msg);

        if ( \OWA\Core\RequestType::isWebApp() ) {
            $navSite = (string) $this->resolveCurrentSiteId();
            $this->set( 'nav_access_site', $navSite );
            $this->set( 'nav_property_id', $navSite !== ''
                ? (string) \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $navSite ) : '' );
        }
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

        /*
         * A GET that names itself as its own referrer would link to itself. A
         * POST is different: a form posting back to the URL it was rendered at
         * has that URL as its referrer, and loading it again is exactly the way
         * back -- which this used to drop, so the link never appeared.
         */
        if ( $referer === \OWA\Core\Lib::currentPublicUrl()
                && strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) !== 'POST' ) {

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
     *
     * @return mixed notAuthenticatedAction()'s answer for a visitor not signed
     *               in; nothing otherwise. A non-empty answer is doAction()'s
     */
    public function nonceFailedAction() {

        // Not signed in: the nonce is beside the point, credentials come first.
        if ( ! \OWA\Core\CoreAPI::getCurrentUser()->isAuthenticated() ) {

            return $this->notAuthenticatedAction();
        }

        if (\OWA\Core\RequestType::is( \OWA\Core\RequestType::REST )) {

            $this->setView('base.restApi');
            $this->set('error_msg', $this->getMsg(2005));
            http_response_code(403);

        } else {

            $this->setView('base.error');
            $this->set('error_msg', $this->getMsg(2005));

            /*
             * Telling someone to go back to where they started is only useful if
             * they are given the way back. The screen that rendered the expired
             * form is the referring page, and it will mint a fresh nonce. Passed
             * on its own, not inside the message: it is client-supplied, and the
             * error page escapes it as an attribute.
             */
            $this->set('error_back_url', (string) $this->getReferringPage());
        }
    }

    /**
     * Why a legacy API key or request signature was refused, and what replaces it.
     *
     * @return string
     */
    public static function legacyApiKeyMessage() {

        return \OWA\Core\CoreAPI::t( 'API keys and request signatures are no longer accepted. Send an application password in the Authorization header instead.' );
    }

    /**
     * The answer for a request without the credentials this controller needs:
     * a 401 for the REST API, a redirect to the login form otherwise.
     *
     * @return mixed nothing here; a non-empty answer is doAction()'s
     */
    public function notAuthenticatedAction() {
        
        if (\OWA\Core\RequestType::is( \OWA\Core\RequestType::REST )) {
            
            $this->setView('base.restApi');
            $this->set('error_msg', ['headline'	=> \OWA\Core\CoreAPI::t( 'Not authenticated.' ), 'msg' => \OWA\Core\Auth::get_instance()->refusedLegacyApiKey()
                ? self::legacyApiKeyMessage()
                : \OWA\Core\CoreAPI::t( 'Check the API credentials or this user\'s permissions.' )] );
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

                $this->set('go', urlencode(\OWA\Core\Lib::currentPublicUrl()));

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

    /**
     * @param  mixed  $nonce  as the request carried it
     * @param  string $action the request's action or do when empty
     * @return bool
     */
    public function verifyNonce($nonce, $action = '') {
            
        // `action` before `do`, as the dispatcher reads them
        // (CoreAPI::performAction): the nonce is checked against the action
        // that runs, not another named beside it.
        $action = $action ?: $this->getParam('action') ?: $this->getParam('do');

        /*
         * The current window and the one before it. A window is a fixed bucket
         * of the epoch, not a span starting when the form was drawn, so the
         * current window alone gives a form anywhere from nought seconds to
         * nonce_expiration_period to live. With the previous one it gets at
         * least a full period and at most two.
         */
        $tick = \OWA\Core\CoreAPI::getNonceTimeInterval();

        foreach ([$tick, $tick - 1] as $t) {

            if (is_string($nonce) && hash_equals(\OWA\Core\CoreAPI::createNonce($action, $t), $nonce)) {

                return true;
            }
        }

        \OWA\Core\CoreAPI::debug("nonce for $action did not match the current or previous window");

        return false;
    }

    /**
     * Sets nonce flag for the controller.
     *
     * @return void
     */
    public function setNonceRequired() {

        $this->is_nonce_required = true;
    }

    /**
     * Accept only these authentication methods. Auth::authenticateUser() does
     * not try the others, so their credentials are never read.
     *
     * @param string[] $methods login_form, overlay_token, app_password, cookies
     * @return void
     */
    public function setAuthMethods( array $methods ) {

        $this->auth_methods = array_values( $methods );
    }

    /**
     * The authentication methods this request may use: the controller's
     * declaration, or null for any. AdminController narrows it to the session.
     *
     * @return string[]|null
     */
    public function acceptedAuthMethods() {

        return $this->auth_methods;
    }


    /**
     * The Profile the hierarchy screens show as current.
     *
     * The tile names all three tiers, so a screen reached without a siteId --
     * editing the Organization, say -- would draw it with two of the three
     * blank, which reads as though nothing is selected rather than as though
     * this screen is simply about a higher tier.
     *
     * Without a siteId, a Profile of the Property in context: the one the
     * caller passes, else the request's propertyId. The Settings gear and the
     * install-wide entries in the settings column name a Property and no
     * Profile, and most screens call this without passing it on.
     *
     * Falls back to the first Profile the user may see. Same reasoning the
     * report controller uses when a request names no site: something has to be
     * current, and the first allowed one is the only defensible choice without
     * asking.
     *
     * @param  int|string $siteId
     * @param  int|string $propertyId
     * @return mixed $siteId when given, else a site_id; '' when the user may see no Profile
     */
    protected function resolveCurrentSiteId( $siteId = '', $propertyId = '' ) {

        if ( $siteId ) {

            return $siteId;
        }

        $sites = (array) $this->getSitesAllowedForCurrentUser();

        $propertyId = (string) $propertyId ?: (string) $this->getParam( 'propertyId' );

        /*
         * A Property in context picks a Profile OF THAT PROPERTY.
         *
         * Arriving from the control's edit link on some other Property carries
         * a propertyId and no siteId. Falling straight through to "the first
         * site I can see" then left the tile and the Profile group describing
         * whatever site happened to be first -- so the nav disagreed with the
         * Property whose screen you were looking at.
         */
        if ( $propertyId ) {

            foreach ( $sites as $site ) {

                /*
                 * Compared as strings. A property id reaches here from a URL
                 * (string), from the database (string), and from a PHP array
                 * key (which coerces a numeric id to an INT) -- so a strict
                 * comparison silently fails on the last one and falls through
                 * to the wrong site.
                 */
                if ( (string) $site->get( 'property_id' ) === (string) $propertyId ) {

                    return $site->get( 'site_id' );
                }
            }
        }

        foreach ( $sites as $site ) {

            return $site->get( 'site_id' );
        }

        return '';
    }

    /**
     * The Property a Property screen is about.
     *
     * The one the request names; else the Property of the siteId it names,
     * which is how these screens were addressed before they were Property
     * screens, so old links still land; else the Property of the first Profile
     * the user may see (resolveCurrentSiteId()).
     *
     * @param  string $propertyId
     * @param  string $siteId
     * @return string '' only when the user may see no Profile at all
     */
    protected function resolveCurrentPropertyId( $propertyId = '', $siteId = '' ) {

        if ( (string) $propertyId !== '' ) {

            return (string) $propertyId;
        }

        return \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $this->resolveCurrentSiteId( $siteId ) );
    }

    /**
     * The settings nav for the hierarchy, shown under the site control.
     *
     * One group per tier, each holding that tier's screens. This is why the
     * tiers do not need one page carrying several forms: a Property's name and
     * a Profile's goals are different screens under different headings, rather
     * than sections of one long page that saves in pieces.
     *
     * Only the install-wide options live in the settings nav proper now, so
     * this is the whole of what hangs off the hierarchy.
     *
     * @param string $siteId     the Profile the SCREEN is about, if it is one
     *                           of a Profile's own screens; '' otherwise. It
     *                           is what adds the Observation Profile group
     * @param string $propertyId the Property in context; derived from the
     *                           Profile when not given
     * @return array<string, list<SettingsEntry>> group label => array of items
     */
    protected function getHierarchyNav( $siteId = '', $propertyId = '' ) {

        if ( ! $propertyId && $siteId ) {

            $site = \OWA\Module\Base\Entity\Site::forSiteId( $siteId );
            $propertyId = $site->get( 'property_id' );
        }

        $nav = [];

        /*
         * YOUR OWN SETTINGS, first.
         *
         * Not part of the Instance group: every entry there is gated on
         * edit_settings and describes the installation, while this describes
         * the person looking at it. An analyst has one of these and none of
         * those.
         *
         * At the head, above the scope chain rather than inside it. The groups
         * below read widest to narrowest -- install, Organization, Property,
         * Profile -- and this is not a step in that chain: it is the one group
         * about you rather than about the tree, which is also why it is the one
         * every signed-in role can open.
         *
         * User-level preferences land here as siblings of Profile rather than
         * inside it.
         */
        $nav['My Preferences'] = [
            [
                'do'         => 'base.myProfile',
                'label'      => \OWA\Core\CoreAPI::t( 'User profile' ),
                'params'     => [],
                // What admin, analyst and viewer all carry and nothing else
                // does -- the capability that means "signed in".
                'capability' => 'view_site_list',
            ],
            [
                'do'         => 'base.myAppPasswords',
                'label'      => \OWA\Core\CoreAPI::t( 'Application passwords' ),
                'params'     => [],
                'capability' => 'view_site_list',
            ],
        ];

        /*
         * Install-wide options head the SAME nav now.
         *
         * They were split into a separate settings menu because the hierarchy
         * screens had no reliable context -- a screen reached without a Profile
         * drew a tile with two blank lines. Landing every session on a Profile
         * settled that: the tile is always populated, so one nav can carry both
         * without either half looking broken.
         *
         * First, because they are the widest scope: the install contains the
         * Organization contains the Property contains the Profile, and the nav
         * reads in that order.
         */
        /*
         * Built from the registered admin panels rather than listed here, so a
         * module that adds one still appears -- MaxmindGeoip and Sqs both
         * register into that map, and hardcoding Base's two entries would have
         * left theirs unreachable when the old settings menu went.
         */
        $registered = $this->registeredSettingsNav();

        if ( isset( $registered['Instance'] ) ) {

            $nav['Instance'] = $registered['Instance'];

            unset( $registered['Instance'] );
        }

        $nav['Organization'] = [
            [ 'do' => 'base.organizationProfile', 'label' => \OWA\Core\CoreAPI::t( 'Details' ), 'params' => [],
                   'capability' => 'edit_settings' ],
            /*
             * User accounts live in the Organization, which is why Users is
             * here and not in the install settings nav -- that put the people
             * belonging to an Organization somewhere that never named one.
             */
            [ 'do' => 'base.users', 'label' => \OWA\Core\CoreAPI::t( 'Users' ), 'params' => [],
                   'capability' => 'edit_users' ],
        ];

        if ( $propertyId ) {

            $nav['Property'] = [
                [ 'do' => 'base.propertyProfile', 'label' => \OWA\Core\CoreAPI::t( 'Details' ),
                       'params' => [ 'propertyId' => $propertyId ],
                       'capability' => 'edit_sites' ],
                /*
                 * Goal event definitions describe a behaviour on the WEBSITE,
                 * so they belong here and every Profile of it inherits them.
                 */
                [ 'do' => 'base.goalEventDefinitions', 'label' => \OWA\Core\CoreAPI::t( 'Goal event definitions' ),
                       'params' => [ 'propertyId' => $propertyId ],
                       'capability' => 'edit_settings' ],
                /*
                 * Custom dimensions belong to the Property because the
                 * reporting cube does -- one cube per Property, so the column a
                 * registration adds is the Property's and two Properties may
                 * use the same key for different things.
                 */
                [ 'do' => 'base.customDimensions', 'label' => \OWA\Core\CoreAPI::t( 'Custom dimensions' ),
                       'params' => [ 'propertyId' => $propertyId ],
                       'capability' => 'edit_settings' ],
                [ 'do' => 'base.propertyRetention', 'label' => \OWA\Core\CoreAPI::t( 'Data retention' ),
                       'params' => [ 'propertyId' => $propertyId ],
                       'capability' => 'edit_settings' ],
                /*
                 * Access is granted to a WEBSITE, not to one way of observing
                 * it, so it belongs to the Property. Stored per Profile;
                 * PropertyGrants reads and writes it across all of them.
                 */
                [ 'do' => 'base.propertyAccess', 'label' => \OWA\Core\CoreAPI::t( 'Access' ),
                       'params' => [ 'propertyId' => $propertyId ],
                       'capability' => 'edit_sites' ],
            ];
        }

        /*
         * The Property's Profiles, each one entry, and the way to add another.
         *
         * A Profile's own screens -- its tag, how it observes, its details --
         * are the tabs of its page (the 'Observation Profile' group below), so
         * the column names the Profile once rather than repeating three
         * screens under a heading that does not say which Profile they are of.
         * Its entry opens the first tab.
         */
        if ( $propertyId ) {

            $nav['Profiles'] = [];

            foreach ( \OWA\Module\Base\Controller\PropertyProfiles::rowsFor( $propertyId ) as $profile ) {

                $nav['Profiles'][] = [
                    'do'         => 'base.sitesInvocation',
                    'label'      => (string) ( $profile['name'] ?: $profile['site_id'] ),
                    'params'     => [ 'siteId' => $profile['site_id'] ],
                    'capability' => 'edit_sites',
                    // Filled in below, once the registered tabs are known.
                    'current_for' => [],
                ];
            }

            $nav['Profiles'][] = [
                'do'         => 'base.sitesProfile',
                'label'      => \OWA\Core\CoreAPI::t( 'Add profile' ),
                'params'     => [ 'propertyId' => $propertyId ],
                'capability' => 'edit_sites',
            ];
        }

        /*
         * Only on a screen about a Profile. The caller passes a siteId when the
         * screen is one of the Profile's own, and the Property otherwise.
         *
         * These are the tabs of the Profile's page, in this order.
         */
        if ( $siteId ) {

            $nav['Observation Profile'] = [
                [ 'do' => 'base.sitesInvocation', 'label' => \OWA\Core\CoreAPI::t( 'Tracking tag' ),
                       'params' => [ 'siteId' => $siteId ],
                       'capability' => 'edit_sites' ],
                /*
                 * An Observation Profile IS a way of observing, so how it
                 * watches the site is its own screen rather than a second form
                 * stacked under its name.
                 */
                [ 'do' => 'base.profileSettings', 'label' => \OWA\Core\CoreAPI::t( 'Observation settings' ),
                       'params' => [ 'siteId' => $siteId ],
                       'capability' => 'edit_sites' ],
                [ 'do' => 'base.sitesProfile', 'label' => \OWA\Core\CoreAPI::t( 'Details' ),
                       'params' => [ 'siteId' => $siteId, 'edit' => true ],
                       'capability' => 'edit_sites' ],
            ];
        }

        /*
         * Registered pages for the other tiers, merged after those sections
         * exist so a module can put a screen on the Property or the Profile
         * rather than being forced into Instance -- which is where every
         * registered page landed regardless of what it asked for.
         *
         * Appended, so a module adds to a section rather than replacing it.
         */
        foreach ( $registered as $section => $items ) {

            if ( ! isset( $nav[ $section ] ) ) {

                /*
                 * A section the framework did not build -- because there is no
                 * current Property, say. Dropped rather than invented: a nav
                 * heading with one third-party link under it and none of the
                 * framework's own is a worse answer than not showing it.
                 */
                continue;
            }

            foreach ( $items as $item ) {

                $nav[ $section ][] = $item;
            }
        }

        /*
         * A Profile's entry is current on every tab of its page, a registered
         * one included.
         */
        $tabs = array_merge(
            [ 'base.sitesInvocation', 'base.profileSettings', 'base.sitesProfile' ],
            array_column( (array) ( $registered['Observation Profile'] ?? [] ), 'do' ) );

        foreach ( (array) ( $nav['Profiles'] ?? [] ) as $i => $entry ) {

            if ( isset( $entry['current_for'] ) ) {

                $nav['Profiles'][ $i ]['current_for'] = $tabs;
            }
        }

        // nav_hierarchy: the whole nav, sections and entries, for this scope.
        return (array) \OWA\Core\CoreAPI::filter( 'nav_hierarchy', $nav, $siteId, $propertyId );
    }

    /**
     * Registered settings pages, bucketed by the nav section each asked for.
     *
     * Three things here were declared and ignored until now. `section` did not
     * exist at all, so every registered page was forced under Instance and a
     * module with per-Property configuration had nowhere to put it. `order`
     * was defaulted to 1 and never read, so pages appeared in whatever order
     * their modules happened to register. And `capability` was recorded and
     * then overridden with a hard-coded 'edit_settings', so a page asking for
     * something stricter was shown to anyone who could edit settings at all.
     *
     * `group` is still honoured as the name for `section`: it is what existing
     * registrations write, and breaking them to rename a key would be a poor
     * trade.
     *
     * @return array<string, list<SettingsEntry>> section => list of nav items
     */
    protected function registeredSettingsNav() {

        return self::settingsNavSections(
            (array) \OWA\Core\CoreAPI::getAdminPanels() );
    }

    /**
     * Bucket and order registered pages. Pure, so the rules above can be
     * asserted without standing up the module layer to produce panels.
     *
     * @param  array<mixed> $panels as getAdminPanels() returns them
     * @return array<string, list<SettingsEntry>> section => ordered list of nav items
     */
    public static function settingsNavSections( array $panels ) {

        $sections = [];

        foreach ( $panels as $items ) {

            foreach ( (array) $items as $item ) {

                $section = (string) ( $item['section'] ?? $item['group'] ?? 'Instance' );

                // 'General' is what registerSettingsPage() has always defaulted
                // `group` to, and it means "the install-wide settings menu".
                if ( $section === 'General' ) {

                    $section = 'Instance';
                }

                $sections[ $section ][] = [
                    'do'         => $item['do'],
                    'label'      => $item['anchortext'],
                    'params'     => [],
                    'capability' => (string) ( $item['capability'] ?? 'edit_settings' ),
                    'order'      => (int) ( $item['order'] ?? 1 ),
                ];
            }
        }

        foreach ( $sections as $section => $items ) {

            /*
             * Stable within an order: usort() is not stable in PHP, and two
             * pages that both take the default would otherwise swap places
             * between requests for no reason a reader could see.
             */
            $keyed = [];

            foreach ( $items as $i => $item ) {

                $keyed[] = [ $item['order'], $i, $item ];
            }

            sort( $keyed );

            $sections[ $section ] = array_map(
                function ( $row ) {

                    unset( $row[2]['order'] );

                    return $row[2];
                },
                $keyed );
        }

        return $sections;
    }

    /**
     * The Organization / Property / Profile tree, for the site control.
     *
     * The control is the navigation for the hierarchy -- there is no separate
     * roster screen -- so it needs the whole shape at once: the Organization
     * heading it, every Property the user may see, and the Profiles under each.
     * A Profile carries the tracker id, which is why the id travels with it and
     * is shown: it is what someone came to the control to find.
     *
     * Two queries regardless of size, not one per Property. This runs on every
     * report render.
     *
     * @param array<\OWA\Module\Base\Entity\Site> $sites site_id => base.site entity
     * @return array{organization: array<string, mixed>, properties: list<array<string, mixed>>}
     */
    protected function getSiteHierarchy( $sites ) {

        $sites = (array) $sites;

        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );
        $property     = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $organization->getTableName() );
        $db->selectColumn( 'id, name' );

        $organizations = (array) $db->getAllRows();
        $org           = $organizations ? $organizations[0] : [ 'id' => '', 'name' => '' ];

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $property->getTableName() );
        $db->selectColumn( 'id, name, domain, archived_date' );
        /*
         * ASC explicitly: orderBy() with no direction is DESC.
         *
         * getDefaultSortDirection() returns OWA_SQL_DESCENDING, so every
         * directionless orderBy() in this codebase sorts backwards -- which for
         * a picker means the Properties list reads Z to A.
         */
        $db->orderBy( 'name', OWA_SQL_ASCENDING );

        $properties = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            // Archived Properties are removed Properties.
            if ( ! empty( $row['archived_date'] ) ) {

                continue;
            }

            $row['profiles'] = [];
            $properties[ $row['id'] ] = $row;
        }

        /*
         * Only Profiles the user may see. An admin sees all of them; anyone
         * else sees what they were granted, and a Property none of whose
         * Profiles they can see is dropped rather than shown empty.
         */
        $unassigned = [];

        foreach ( $sites as $site ) {

            $entry = [
                'site_id' => $site->get( 'site_id' ),
                'name'    => $site->get( 'name' ),
                'domain'  => $site->get( 'domain' ),
            ];

            $parent = $site->get( 'property_id' );

            if ( $parent && isset( $properties[ $parent ] ) ) {

                $properties[ $parent ]['profiles'][] = $entry;

            } else {

                /*
                 * Never dropped: a Profile with no Property is still a site the
                 * user is tracking, and omitting it from the control would make
                 * it unreachable rather than merely unparented.
                 */
                $unassigned[] = $entry;
            }
        }

        /*
         * A Property with no Profiles is dropped -- unless the viewer can see
         * every Profile there is, in which case it is genuinely empty rather
         * than merely opaque to them.
         *
         * Those are two different facts that the profile count alone cannot
         * tell apart. For someone with granted access, an empty Property means
         * "none of its Profiles are yours" and showing it would leak that the
         * website exists. For an admin it means the Property really has none,
         * and that is a state worth being in: archiving a Property's last
         * Profile leaves it empty so it can be repopulated. Dropping it there
         * made the Property unreachable -- no screen could get back to it --
         * which is also what happened to a Property created from the fan-out
         * before it had a Profile.
         */
        $seesEveryProfile = \OWA\Core\CoreAPI::getCurrentUser()->isAdmin();

        $properties = array_filter( $properties, function ( $p ) use ( $seesEveryProfile ) {

            return $p['profiles'] || $seesEveryProfile;
        } );

        if ( $unassigned ) {

            $properties['__unassigned'] = [
                'id'       => '',
                'name'     => \OWA\Core\CoreAPI::t( 'Unassigned' ),
                'domain'   => '',
                'profiles' => $unassigned,
            ];
        }

        return [
            'organization' => $org,
            'properties'   => array_values( $properties ),
        ];
    }

    /**
     * Returns array of owa_site entities where the current user has access to, taken the current controller cap into account
     * @return array<\OWA\Module\Base\Entity\Site> site_id => the Profile
     */
    protected function getSitesAllowedForCurrentUser() {
   
        $currentUser = \OWA\Core\CoreAPI::getCurrentUser();

        if ( $currentUser->isAnonymousUser() || $currentUser->isAdmin() ) {
            $result = [];
           
            $relations = \OWA\Core\CoreAPI::getSitesList();

            foreach ($relations as $siteRow) {

                // From the row getSitesList() already read: loading each site
                // again by id was one query per site on every admin page.
                $site = \OWA\Core\CoreAPI::entityFactory('base.site');
                $site->hydrate( (array) $siteRow );

                // Archived Profiles are removed Profiles. getSitesList() is the
                // raw table -- migrations need that -- so the filtering happens
                // at the product-facing callers.
                if ( $site->isArchived() ) {

                    continue;
                }

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
