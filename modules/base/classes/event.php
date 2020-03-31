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

/**
 * Abstract OWA Event Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_event {

    /**
     * Event Properties
     *
     * @var array
     */
    var $properties = array();

    /**
     * State
     *
     * @var string
     */
    //var $state;

    var $eventType;

    /**
     * Time since last request.
     *
     * Used to tell if a new session should be created.
     *
     * @var integer $time_since_lastreq
     */
    var $time_since_lastreq;

    /**
     * Event guid
     *
     * @var string
     */
    var $guid;

    /**
     * Creation Timestamp in UNIX EPOC UTC
     *
     * @var int
     */
    var $timestamp;

    /**
     * Last error msg set by handler
     *
     * @var string
     */
    var $last_error_msg;

    /**
     * Number of times event was received from message quque
     *
     * @var integer
     */
    var $receive_count = 0;

    /**
     * Timestamp of last receipt from message queue
     *
     * @var string
     */
    var $last_receive_timestamp;

    /**
     * Timestamp of first receipt from message queue
     *
     * @var string
     */
    var $first_receive_timestamp;

    /**
     * Timestamp not to handled event before
     *
     * @var string
     */
    var $do_not_receive_before_timestamp;

    var $status;

    // backwards compat. remove soon.
    var $old_queue_id;

    const handled = 'handled';
    const unhandled = 'unhandled';
    const broken = 'broken';

    /**
     * Constructor
     * @access public
     */
    function __construct() {

        // Set GUID for event
        $this->guid = $this->set_guid();
        $this->timestamp = time();
        //needed?
        $this->set('guid', $this->guid);
        $this->set('timestamp', $this->timestamp );
        $this->status = self::unhandled;
    }

    function setStatusAsHandled() {

        $this->status = self::handled;
        //clear any error
        $this->last_error_msg = '';
    }

    function setStatusAsBroken() {

        $this->status = self::broken;
    }

    function getTimestamp() {

        return $this->timestamp;
    }

    function set($name, $value) {

        $this->properties[$name] = $value;
    }

    function get($name) {

        if(array_key_exists($name, $this->properties)) {
            //print_r($this->properties[$name]);
            return $this->properties[$name];
        } else {
            return false;
        }
    }

    /**
     * removes a property
     */
    function delete( $name ) {

        if (array_key_exists( $name, $this->properties ) ) {

            unset( $this->properties[ $name ] );
        }
    }

    /**
     * Sets time related event properties
     *
     * @param integer $timestamp
     */
    function setTime($timestamp = null) {

        if ( $timestamp ) {
            $this->set('timestamp', $timestamp);
        } else {
            $timestamp = $this->getTimestamp();
        }

        $this->set('timestamp', $timestamp);
        $this->set('year', date("Y", $timestamp));
        $this->set('month', date("Ym", $timestamp));
        $this->set('day', date("d", $timestamp));
        $this->set('yyyymmdd', date("Ymd", $timestamp));
        $this->set('dayofweek', date("D", $timestamp));
        $this->set('dayofyear', date("z", $timestamp));
        $this->set('weekofyear', date("W", $timestamp));
        $this->set('hour', date("G", $timestamp));
        $this->set('minute', date("i", $timestamp));
        $this->set('second', date("s", $timestamp));

        //epoc time
        list($msec, $sec) = explode(" ", microtime());
        $this->set('sec', $sec);
        $this->set('msec', $msec);

    }

    function setCookieDomain($domain) {

        $this->properties['cookie_domain'] = $domain;
    }

    /**
     * Determines the time since the last request from this borwser
     *
     * @access private
     * @return integer
     */
    function timeSinceLastRequest() {

        return ($this->get('timestamp') - $this->get('last_req'));
    }

    /**
     * Applies calling application specific properties to request
     *
     * @access     private
     * @param     array $properties
     */
    function setProperties($properties = null) {

        if(!empty($properties)) {

            if (empty($this->properties)) {
                $this->properties = $properties;
            } else {
                $this->properties = array_merge($this->properties, $properties);
            }
        }
    }

    /**
     * Adds new properties to the eventt without overwriting values
     * for properties that are already set.
     *
     * @param     array $properties
     */
    function setNewProperties( $properties = array() ) {

        $this->properties = array_merge($properties, $this->properties);

    }

    /**
     * Exports Event Class variables
     *
     * @return     array
     */
     function export() {

         return get_object_vars( $this );
     }

    /**
     * Loads Event class variables from an array
     *
     * @param     array $properties
     */
     function loadFromArray ( $vars ) {

         $has = get_object_vars( $this );

        foreach ($has as $name => $oldValue ) {
        
            if ( isset( $vars[$name] ) ) {

                $this->$name = $vars[ $name ];
            }
        }
    }

    function replaceProperties($properties) {

        $this->properties = $properties;
    }

    /**
     * Create guid from process id
     *
     * @return    integer
     * @access     private
     */
    function set_guid() {

        return owa_lib::generateRandomUid();
    }

    /**
     * Create guid from string
     *
     * @param     string $string
     * @return     integer
     * @access     private
     */
    function set_string_guid($string) {

        return crc32(strtolower($string));

    }

    /**
     * Attempts to make a unique ID out of http request variables.
     * This should only be used when storing state in a cookie is impossible.
     *
     * @return integer
     */
    function setEnvGUID() {

        return crc32( $this->get('ua') . $this->get('ip_address') );

    }

    function setSiteSessionState($site_id, $name, $value, $store_type = 'cookie') {

        $store_name = owa_coreAPI::getSetting('base', 'site_session_param').'_'.$site_id;
        return owa_coreAPI::setState($store_name, $name, $value, $store_type, true);
    }

    function deleteSiteSessionState($site_id, $store_type = 'cookie') {

        $store_name = owa_coreAPI::getSetting('base', 'site_session_param').'_'.$site_id;
        return owa_coreAPI::clearState($store_name);
    }

    function getProperties() {

        return $this->properties;
    }

    function getEventType() {

        if (!empty($this->eventType)) {
            return $this->eventType;
        } elseif ($this->get('event_type')) {
            return $this->get('event_type');
        } else {

            return 'unknown_event_type';
        }
    }

    function setEventType($value) {
        $this->eventType = $value;
    }

    function cleanProperties() {

        return $this->setProperties(owa_lib::inputFilter($this->getProperties()));
    }

    function setPageTitle($value) {

        $this->set('page_title', $value);
    }

    function setSiteId($value) {

        $this->set('siteId', $value);
        $this->set('site_id', $value);
    }

    function getSiteId() {

        if ( $this->get('siteId') ) {
            return $this->get('siteId');
        } else {
            return $this->get('site_id');
        }


    }

    function setPageType($value) {

        $this->set('page_type', $value);
    }

    function getGuid() {

        return $this->guid;
    }

    function getSiteSpecificGuid($site_id) {

        return owa_lib::generateRandomUid();
    }

    function getStatus() {

        return $this->status;
    }

    function setDoNotReceiveBeforeTimestamp( $time ) {

        $this->do_not_receive_before_timestamp = $time;
    }

    function wasReceived() {

        $time = time();

        $this->last_receive_timestamp = $time;

        if ( $this->receive_count === 0 ) {

            $this->first_receive_timestamp = $time;
        }

        $this->incrementReceiveCount();
    }

    function incrementReceiveCount() {

        $this->receive_count++;
    }

    function getReceiveCount() {

        return $this->receive_count;
    }

    function setErrorMeg( $error_msg ) {

        $this->last_error_msg = $error_msg;
    }

    function getErrorMsg() {

        return $this->last_error_msg;
    }

    function getLastReceiveTimestamp() {

        return $this->last_receive_timestamp;
    }

    function getDoNotReceiveBeforeTimestamp() {

        return $this->do_not_receive_before_timestamp;
    }

    // backwards compat for dbEventQueue. remove_soon.
    function setOldQueueId( $id ) {

        if ( $id != $this->getGuid() ) {

            $this->old_queue_id = $id;
        }
    }

    function getQueueGuid() {

        if ( $this->old_queue_id ) {

            return $this->old_queue_id;

        } else {

            return $this->getGuid();
        }
    }
}

?>