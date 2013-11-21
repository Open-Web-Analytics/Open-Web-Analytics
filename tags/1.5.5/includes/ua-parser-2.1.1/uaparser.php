<?php

/*!
 * ua-parser-php v2.1.1
 *
 * Copyright (c) 2011-2012 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * ua-parser-php is the PHP library for the ua-parser project. Learn more about the ua-parser project at:
 * 
 *   https://github.com/tobie/ua-parser
 *
 * The user agents data from the ua-parser project is licensed under the Apache license.
 * spyc-0.5, for loading the YAML, is licensed under the MIT license.
 * Services_JSON, for loading the JSON in sub-PHP 5.2 installs, is licensed under the MIT license
 * The initial list of generic feature phones & smartphones came from Mobile Web OSP under the MIT license
 * The initial list of spiders was taken from Yiibu's profile project under the MIT license.
 *
 * Many thanks to the following major contributors:
 *
 *   - Bryan Shelton
 *   - Michael Bond
 *   - @rjd22 (https://github.com/rjd22)
 *   - Timo Tijhof (https://github.com/Krinkle)
 *   - Marcus Bointon (https://github.com/Synchro)
 *   - Ryan Parman (https://github.com/skyzyx)
 *   - Pravin Dahal (https://github.com/pravindahal)
 */

// address 5.1 compatibility
if (!function_exists('json_decode') || !function_exists('json_encode')) {
    require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'lib/json/jsonwrapper.php');
}

class UAParser {
    
    protected $regexes;
    protected $log = false;

    /**
     * Start up the parser by importing the json file to $this->regexes
     */
    public function __construct($customRegexesFile = null) {
	
        $regexesFile = ($customRegexesFile !== null) ? $customRegexesFile : dirname(__FILE__).DIRECTORY_SEPARATOR.'resources/regexes.json';
        if (file_exists($regexesFile)) {
            $this->regexes = json_decode(file_get_contents($regexesFile));
        } else {
            $title            = 'Error loading ua-parser';
            if ($customRegexesFile !== null) {
                $message      = 'ua-parser can\'t find the custom regexes file you supplied ('.$customRegexesFile.'). Please make sure you have the correct path.';
                $instruction1 = '';
                $instruction2 = '';
            } else {
                $message      = 'Please download the regexes.json file before using uaparser.php. You can type the following at the command line to download the latest version: ';
                $instruction1 = '%: cd /path/to/UAParser/';
                $instruction2 = '%: php uaparser-cli.php -g';
            }
            
            if (php_sapi_name() == 'cli') {
                print "\n".$title."\n";
                print $message."\n\n";
                print "    ".$instruction2."\n\n";
            } else {
                print '<html><head><title>'.$title.'</title></head><body>';
                print '<h1>'.$title.'</h1>';
                print '<p>'.$message.'</p>';
                print '<blockquote>';
                print '<code>'.$instruction1.'</code><br>';
                print '<code>'.$instruction2.'</code>';
                print '</blockquote>';
                print '</body></html>';
            }

            exit;
        }
    }
    
    /**
     * Sets up some standard variables as well as starts the user agent parsing process
     * @param  string a user agent string to test, defaults to an empty string
     * @return object the result of the user agent parsing
     */
    public function parse($ua = '') {

        // build the default obj that will be returned
        $result = (object) array(
            'ua'           => (object) array(),
            'os'           => (object) array(),
            'device'       => (object) array(),
            'toFullString' => '',
            'uaOriginal'   => $ua
        );

        // figure out the ua, os, and device properties if possible
        $result->ua           = $this->uaParse($ua);
        $result->os           = $this->osParse($ua);
        $result->device       = $this->deviceParse($ua);
        
        // create a full string version based on the ua and os objects
        $result->toFullString = $this->toFullString($result->ua, $result->os);
        
        // log the results when testing
        if ($this->log) {
            $this->log($result);
        }
        
        return $result;
        
    }

    /**
     * Attempts to see if the user agent matches a user_agents_parsers regex from regexes.json
     * @param  string  a user agent string to test
     * @return object  the result of the user agent parsing
     */
    public function uaParse($uaString = '') {

        // build the default obj that will be returned
        $ua = (object) array(
                'family'          => 'Other',
                'major'           => null,
                'minor'           => null,
                'patch'           => null,
                'toString'        => '',
                'toVersionString' => ''
              );
        
        // run the regexes to match things up
        $uaRegexes = $this->regexes->user_agent_parsers;
        foreach ($uaRegexes as $uaRegex) {
            
            // tests the supplied regex against the user agent
            if (preg_match('/'.str_replace('/','\/',str_replace('\/','/',$uaRegex->regex)).'/i',$uaString,$matches)) {
                
                // Make sure matches are at least set to null or Other
                if (!isset($matches[1])) { $matches[1] = 'Other'; }
                if (!isset($matches[2])) { $matches[2] = null; }
                if (!isset($matches[3])) { $matches[3] = null; }
                if (!isset($matches[4])) { $matches[4] = null; }
                
                // ua name
                $ua->family          = isset($uaRegex->family_replacement) ? str_replace('$1',$matches[1],$uaRegex->family_replacement) : $matches[1];

                // version properties
                $ua->major           = isset($uaRegex->v1_replacement) ? $uaRegex->v1_replacement : $matches[2];
                $ua->minor           = isset($uaRegex->v2_replacement) ? $uaRegex->v2_replacement : $matches[3];
                $ua->patch           = isset($uaRegex->v3_replacement) ? $uaRegex->v3_replacement : $matches[4];

                // extra strings
                $ua->toString        = $this->toString($ua);
                $ua->toVersionString = $this->toVersionString($ua);
                
                return $ua;
            }
            
        }

        return $ua;
        
    }

    /**
     * Attempts to see if the user agent matches an os_parsers regex from regexes.json
     * @param  string  a user agent string to test
     * @return object  the result of the os parsing
     */
    public function osParse($uaString = '') {
        
        // build the default obj that will be returned
        $os = (object) array(
                'family'          => 'Other',
                'major'           => null,
                'minor'           => null,
                'patch'           => null,
                'patch_minor'     => null,
                'toString'        => '',
                'toVersionString' => ''
              );
        
        // run the regexes to match things up
        $osRegexes = $this->regexes->os_parsers;
        foreach ($osRegexes as $osRegex) {
            
            if (preg_match('/'.str_replace('/','\/',str_replace('\/','/',$osRegex->regex)).'/i',$uaString,$matches)) {

                // Make sure matches are at least set to null or Other
                if (!isset($matches[1])) { $matches[1] = 'Other'; }
                if (!isset($matches[2])) { $matches[2] = null; }
                if (!isset($matches[3])) { $matches[3] = null; }
                if (!isset($matches[4])) { $matches[4] = null; }
                if (!isset($matches[5])) { $matches[5] = null; }
                
                // os name
                $os->family          = isset($osRegex->os_replacement)    ? $osRegex->os_replacement    : $matches[1];
                
                // version properties
                $os->major           = isset($osRegex->os_v1_replacement) ? $osRegex->os_v1_replacement : $matches[2];
                $os->minor           = isset($osRegex->os_v2_replacement) ? $osRegex->os_v2_replacement : $matches[3];
                $os->patch           = isset($osRegex->os_v3_replacement) ? $osRegex->os_v3_replacement : $matches[4];
                $os->patch_minor     = isset($osRegex->os_v4_replacement) ? $osRegex->os_v4_replacement : $matches[5];
                
                // extra strings
                $os->toString        = $this->toString($os);
                $os->toVersionString = $this->toVersionString($os);

                return $os;
            }
            
        }

        return $os;
        
    }

    /**
     * Attempts to see if the user agent matches a device_parsers regex from regexes.json
     * @param  string  a user agent string to test
     * @return object  the result of the device parsing
     */
    public function deviceParse($uaString = '') {
        
        // build the default obj that will be returned
        $device = (object) array(
                    'family' => 'Other'
                  );
        
        // run the regexes to match things up
        $deviceRegexes = $this->regexes->device_parsers;
        foreach ($deviceRegexes as $deviceRegex) {
            
            if (preg_match('/'.str_replace('/','\/',str_replace('\/','/',$deviceRegex->regex)).'/i',$uaString,$matches)) {

                // Make sure matches are at least set to null or Other
                if (!isset($matches[1])) { $matches[1] = 'Other'; }
                
                // device name
                $device->family = isset($deviceRegex->device_replacement) ? str_replace('$1',str_replace("_"," ",$matches[1]),$deviceRegex->device_replacement) : str_replace("_"," ",$matches[1]);
                
                return $device;
                
            }
            
        }
        
        return $device;
        
    }
    
    /**
     * Returns a string consisting of the family and full version number based on the provided type
     * @param  object  the object (ua or os) to be used
     * @return string  the result of combining family and version
     */
    public function toString($obj) {
        
        $versionString = $this->toVersionString($obj);
        $string        = !empty($versionString) ? $obj->family.' '.$versionString : $obj->family;
        
        return $string;
    }
    
    /**
     * Returns a string consisting of just the full version number based on the provided type
     * @param  object  the obj that contains version number bits
     * @return string  the result of combining the version number bits together
     */
    public function toVersionString($obj) {
                
        $versionString = isset($obj->major) ? $obj->major : '';
        $versionString = isset($obj->minor) ? $versionString.'.'.$obj->minor : $versionString;
        $versionString = isset($obj->patch) ? $versionString.'.'.$obj->patch : $versionString;
        $versionString = isset($obj->patch_minor) ? $versionString.'.'.$obj->patch_minor : $versionString;
        
        return $versionString;
        
    }
    
    /**
     * Returns a string consistig of the family and full version number for both the browser and os
     * @param  object  the ua object
     * @param  object  the os object
     * @return string  the result of combining family and version
     */
    public function toFullString($ua,$os) {
        
        $fullString = $this->toString($ua).'/'.$this->toString($os);
        
        return $fullString;
        
    }
    
    /**
    * Logs the user agent info
    */
    protected function log($data) {
        $jsonData = json_encode($data);
        $fp = fopen(dirname(__FILE__).DIRECTORY_SEPARATOR.'log/user_agents.log', 'a');
        fwrite($fp, $jsonData."\r\n");
        fclose($fp);
    }
    
}
