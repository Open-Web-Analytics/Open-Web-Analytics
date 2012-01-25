<?php

/**
 * The Abstraction Layer that all MaxMind Web Services extend from
 *
 * @access  private
 * @author 	Nathan White < contact at nathanwhite dot us >
 *
 */

class MaxMindWebServices {


	/**
     * The licence Key for of a Maxmind web services account
     *
     * @var     string
     * @access  private
     */
	var $licenceKey = "";
	
	
	/**
	 * An array that holds all returned values from a Maxmind request
     *
     * @var		array
     * @access	private
	 */
	var $data = array();

	/**
	 * Set the Licence Key
     *
     * @var		string
     * @access	public
	 */
	function setLicenceKey($key){
		$this->licenceKey = $key;
	}

	/**
	 * Test to see if the Service produced an Error
     *
     * @return	bool
     * @access	public
	 */
	function isError(){
		$error = $this->getError();
		if( isset($error) ) return true;
		else return false;
	}

	/**
	 * Get all Results in a single array for fast processing
     *
     * @return	array
     * @access	public
	 */
	function getResultArray(){
		return $this->data;
	}
	
	/**
	 * Returns the City and State from a metro code
     *
     * @param 	string
     * @return	string
     * @access	public
	 */
	function lookupMetroCode($code){
		if( !isset($this->_metroCodes) ){
			$this->_metroCodes = parse_ini_file(dirname( __FILE__ ).'/ini/metroCodes.ini');
		}
		return $this->_metroCodes[$code];
	}
	
	/**
	 * Returns the Country Name from the code
     *
     * @param 	string
     * @return	string
     * @access	public
	 */
	function lookupCountryCode($code){
		if( !isset($this->_countryCodes) ){
			$this->_countryCodes = parse_ini_file(dirname( __FILE__ ).'/ini/countryCodes.ini');
		}
		return $this->_countryCodes["'".$code."'"];
	}
	
	/**
	 * Returns the SubCountry Name from the code ( States, Provinces )
     *
     * @param 	string
     * @param	string
     * @return	string
     * @access	public
	 */	
	function lookupSubCountryCode($code, $countryCode){
		if( !isset($this->_subCountryCodes) ){
			$this->_subCountryCodes = parse_ini_file(dirname( __FILE__ ).'/ini/subCountryCodes.ini', true);
		}
		if( is_array($this->_subCountryCodes["'".$countryCode."'"]) ){
			return $this->_subCountryCodes["'".$countryCode."'"]["'".$code."'"];
		}
	}
	/**
	 * Generic Web Service Request for MaxMind
     *
     * @access 	private
	 */
	function _queryMaxMind($url){
		
		$ch = curl_init();    // initialize curl handle
		curl_setopt($ch, CURLOPT_URL,$url); // set url to post to
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1); // return into a variable
        curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_TIMEOUT, 4); // times out after 4s

		return curl_exec($ch);
		
	}


	/**
	 * Function to handle parsing the csv string returned from MaxMind
     *
     * This function was found in the comments section on:
     * http://www.php.net/manual/en/function.fgetcsv.php
     *
     * @var		string	csv line to parse
     * @var		string	delimiter to use for spliting
     * @var		bool	remove quotes around values
     * @return	array	the parts of the csv line
     * @access	public
     * @author	php@dogpoop.cjb.net
	 */

	function csv_split($line,$delim=',',$removeQuotes=true) {

		$fields = array();
		$fldCount = 0;
		$inQuotes = false;
		for ($i = 0; $i < strlen($line); $i++) {
			if (!isset($fields[$fldCount])) $fields[$fldCount] = "";
			$tmp = substr($line,$i,strlen($delim));
			
			if ($tmp === $delim && !$inQuotes) {
				$fldCount++;
				$i += strlen($delim)-1;
			}
			else if ($fields[$fldCount] == "" && $line[$i] == '"' && !$inQuotes) {
				if (!$removeQuotes) $fields[$fldCount] .= $line[$i];
				$inQuotes = true;
			}
			else if ($line[$i] == '"') {
				if ($line[$i+1] == '"') {
					$i++;
					$fields[$fldCount] .= $line[$i];
				}
				else {
					if (!$removeQuotes) $fields[$fldCount] .= $line[$i];
					$inQuotes = false;
				}
			}
			else {
				$fields[$fldCount] .= $line[$i];
			}
		}
		return $fields;
	}


}

?>
