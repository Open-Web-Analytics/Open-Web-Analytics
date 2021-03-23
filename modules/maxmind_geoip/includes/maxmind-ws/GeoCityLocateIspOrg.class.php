<?php

  require_once( "MaxMindWebServices.class.php" );

  /**
   * Geo City Locate W/ ISP and Organization information
   *
   * @access      public
   * @author     Nathan White < contact at nathanwhite dot us >
   *
   */
  class GeoCityLocateIspOrg extends MaxMindWebServices {

    /**
     * Implements a singleton design pattern
     *
     * when looking for an instance one can pass an IP address to have data populated
     *
     * @access    public
     * @param    string
     * @return    reference to a GeoCityLocateIspOrg object
     */
    function &getInstance($ip = "") {
        static $instance = null;

        if ($instance === null) {
            $instance = new GeoCityLocateIspOrg();
        }

        if(!empty($ip)){
            $instance->setIP($ip);
        }

        return $instance;
    }

    /**
     * An array that holds all returned values from a Maxmind request
     *
     * @param    string
     * @access    public
     */
    function setIP($ip){
        $this->data = array();
        $this->ip = $ip;
        $this->_process();
    }
    
    /**
     * Get the IP address that is being processed
     *
     * @return    string
     * @access    public
     */
    function getIP(){
        return $this->ip;
    }

    /**
     * Get the return Country Code
     *
     * @return    string
     * @access    public
     */
    function getCountryCode(){
        return $this->data[0];
    }

    /**
     * Get the return Region Code
     *
     * @return    string
     * @access    public
     */
    function getRegion(){
        return $this->data[1];
    }
    
    /**
     * Get the return Region Code
     *
     * @return    string
     * @access    public
     */
    function getState(){
      return $this->getRegion();
    }

    /**
     * Get the return City
     *
     * @return    string
     * @access    public
     */
    function getCity(){
        return $this->data[2];
    }

    /**
     * Get the return Postal
     *
     * @return    string
     * @access    public
     */
    function getPostal(){
        return $this->data[3];
    }
    
    /**
     * Get the return Postal
     *
     * @return    string
     * @access    public
     */
    function getZip(){
        return $this->getPostal();
    }

    /**
     * Get the return Latitude
     *
     * @return    string
     * @access    public
     */
    function getLatitude(){
        return $this->data[4];
    }
    
    /**
     * Get the return Latitude
     *
     * @return    string
     * @access    public
     */
    function getLat(){
        return $this->getLatitude();
    }

    /**
     * Get the return Longitude
     *
     * @return    string
     * @access    public
     */
    function getLongitude(){
        return $this->data[5];
    }

    /**
     * Get the return Longitude
     *
     * @return    string
     * @access    public
     */
    function getLong(){
        return $this->getLongitude();
    }

    /**
     * Get the return Metro Code
     *
     * @return    string
     * @access    public
     */
    function getMetroCode(){
        return $this->data[6];
    }

    /**
     * Get the return Area Code
     *
     * @return    string
     * @access    public
     */
    function getAreaCode(){
        return $this->data[7];
    }

    /**
     * Get the return ISP
     *
     * @return    string
     * @access    public
     */
    function getISP(){
        return $this->data[8];
    }

    /**
     * Get the return Organization
     *
     * @return    string
     * @access    public
     */
    function getOrganization(){
        return $this->data[9];
    }

    /**
     * Get the return Error
     *
     * @return    string
     * @access    public
     */
    function getError(){
        return $this->data[10];
        
    }

    /**
     * Formats the url to submit to Maxmind and returns data in an array
     *
     * @access private
     */
    function _process(){
      $url = "http://maxmind.com:8010/f?l=" . $this->licenceKey . "&i=" . $this->ip;
      $response = $this->_queryMaxMind($url);
      $this->data = $this->csv_split( trim($response) );
    }
    
    
  }
  
?>