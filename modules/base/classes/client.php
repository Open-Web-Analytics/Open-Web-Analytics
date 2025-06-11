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

require_once( OWA_BASE_CLASSES_DIR . 'owa_caller.php' );

//////////////////////////////////////////////////////////////
// THIS CLASS IS DEPRECATED AND IS NO LONGER SUPPORTED.
// USE THE OWA PHP SDK TO BUILD A TRACKER FOR PHP APPLICATIONS.
//////////////////////////////////////////////////////////////

/**
 * OWA Client Class
 *
 * Abstract Client Class for use in php based applications
 *
 * @deprecated
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * 
 */

class owa_client extends owa_caller {

    var $commerce_event;

    var $pageview_event;

    var $global_event_properties = array();

    var $stateInit;

    var $organicSearchEngines;

    // set one traffic has been attributed.
    var $isTrafficAttributed;

    public function __construct($config = null) {

        parent::__construct($config);
        owa_coreAPI::notice("Use of owa_client class is deprecated. Re-implement your tracker using OWA's PHP SDK.");
        
    }

    public function setPageTitle($value) {
        return false;
    }

    public function setPageType($value) {
        return false;
    }

    public function setProperty($name, $value) {
        return false;
    }

    private function setGlobalEventProperty($name, $value) {

        return false;
    }

    private function getGlobalEventProperty($name) {
        
        return false;
    }

    private function deleteGlobalEventProperty( $name ) {

        return false;
    }

    private function manageState( &$event ) {

        return false;
    }

    private function setVisitorId( &$event ) {

        return false;
    }

    private function setNumberPriorSessions( &$event ) {
        
        return false;
    }

    private function setFirstSessionTimestamp( &$event ) {

        return false;
    }

    private function setDaysSinceLastSession( &$event ) {

        return false;
    }

    private function setSessionId( &$event ) {

        return false;
    }

    private function setLastRequestTime( &$event ) {

        return false;
    }

    /**
     * Check to see if request is a new or active session
     *
     * @return boolean
     */
    private function isNewSession($timestamp = '', $last_req = 0) {

        return false;
    }

    /**
     * Logs tracking event
     *
     * This function fires a tracking event that will be processed and then dispatched
     *
     * @param object $event
     * @return boolean
     */
    public function trackEvent($event) {

        return false;
    }

    public function setAllGlobalEventProperties( $event ) {

        return false;
    }

    public function getAllEventProperties( $event ) {

        return false;
    }

    public function trackPageview($event = '') {

        return false;
    }

    public function trackAction($action_group = '', $action_name, $action_label = '', $numeric_value = 0) {

        return false;
    }

    /**
     * Creates a ecommerce Transaction event
     *
     * Creates a parent commerce.transaction event
     */
    public function addTransaction(
            $order_id,
            $order_source = '',
            $total = 0,
            $tax = 0,
            $shipping = 0,
            $gateway = '',
            $country = '',
            $state = '',
            $city = '',
            $page_url = '',
            $session_id = ''
        ) {

        return false;
    }

    /**
     * Adds a line item to a commerce transaction
     *
     * Creates and a commerce.line_item event and adds it to the parent transaction event
     */
    public function addTransactionLineItem($order_id, $sku = '', $product_name = '', $category = '', $unit_price = 0, $quantity = 0) {

        return false;
    }

    /**
     * tracks a commerce events
     *
     * Tracks a parent transaction event by sending it to the event queue
     */
    public function trackTransaction() {

        return false;
    }

    public function createSiteId($value) {

        return false;
    }

    public function setCampaignNameKey( $key ) {

        return false;
    }

    public function setCampaignMediumKey( $key ) {

        return false;
    }

    public function setCampaignSourceKey( $key ) {

        return false;
    }

    public function setCampaignSearchTermsKey( $key ) {

        return false;
    }

    public function setCampaignAdKey( $key ) {

        return false;
    }

    public function setCampaignAdTypeKey( $key ) {

        return false;
    }

    public function setUserName( $value ) {

        return false;
    }

    function getCampaignProperties( $event ) {

        return false;
    }

    private function setCampaignSessionState( $properties ) {

        return false;
    }

    function directAttributionModel( &$campaign_properties ) {

        return false;
    }

    function originalAttributionModel( &$campaign_properties ) {

        return false;
    }

    function getCampaignState() {

        return false;
    }

    function setTrafficAttribution( &$event ) {

        return false;
    }

    private function inferTrafficAttribution() {

        return false;
    }

    private function isRefererSearchEngine( $uri ) {

        return false;
    }

    function setCampaignCookie($values) {

        return false;
    }

    // sets cookies domain
    function setCookieDomain($domain) {

        return false;
    }

    /**
     * Set a custom variable
     *
     * @param    slot    int        the identifying number for the custom variable. 1-5.
     * @param    name    string    the key of the custom variable.
     * @param    value    string    the value of the variable
     * @param    scope    string    the scope of the variable. can be page, session, or visitor
     */
    public function setCustomVar( $slot, $name, $value, $scope = '' ) {

        return false;
    }

    public function getCustomVar( $slot ) {

        return false;
    }

    public function deleteCustomVar( $slot ) {

        return false;
    }

    private function resetSessionState() {
        
        return false;
    }

    public function addOrganicSearchEngine( $domain, $query_param, $prepend = '' ) {

        return false;
    }
}

?>