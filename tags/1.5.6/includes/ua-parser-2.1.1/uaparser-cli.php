<?php

/*!
 * ua-parser-php CLI v2.1.1
 *
 * Copyright (c) 2012 Dave Olsen, http://dmolsen.com
 * Licensed under the MIT license
 *
 * spyc-0.5, for loading the YAML, is licensed under the MIT license.
 * Services_JSON, for loading the JSON in sub-PHP 5.2 installs, is licensed under the MIT license
 *
 * This is the CLI for ua-parser-php. The following commands are supported:
 *
 *   php uaparser-cli.php
 *       Provides the usage information.
 *
 *   php uaparser-cli.php [-p] [-j] "your user agent string"
 *       Parses a user agent string and dumps the results as a list.
 *       Use the -j flag to print the result as JSON.
 *       Use the -p flag to pretty print the JSON result when using PHP 5.4+.
 *
 *   php uaparser-cli.php -g [-s] [-n]
 *       Fetches an updated YAML file for UAParser and overwrites the current JSON file.
 *       By default is verbose. Use -s to turn that feature off.
 *       By default creates a back-up. Use -n to turn that feature off.
 *
 *   php uaparser-cli.php -c [-s] [-n]
 *       Converts an existing regexes.yaml file to a regexes.json file.
 *       By default is verbose. Use -s to turn that feature off.
 *       By default creates a back-up. Use -n to turn that feature off.
 *
 *   php uaparser-cli.php -y
 *       Fetches an updated YAML file. If you need to add a new UA it's easier to edit
 *       the original YAML and then convert it. Warning: This method overwrites any 
 *       existing regexes.yaml file.
 *
 *   php uaparser-cli.php -l /path/to/apache/logfile
 *       Parses the supplied Apache log file to test UAParser.php. Saves the UA to a file
 *       when the UA or OS family aren't found or when the UA is listed as a generic
 *       smartphone or as a generic feature phone.
 *
 * Thanks to Marcus Bointon (https://github.com/Synchro) for getting this file started
 * and adding the initial JSON parser for a UA string.
 *
 */

// define the base path for the file
$basePath = dirname(__FILE__).DIRECTORY_SEPARATOR;

// address 5.1 compatibility
if (!function_exists('json_decode') || !function_exists('json_encode')) {
    require_once($basePath."lib/json/jsonwrapper.php");
}

// include the YAML library
require_once($basePath."lib/spyc-0.5/spyc.php");

// include UAParser.php and make sure to turn off the CLI error
require_once($basePath."uaparser.php");

// deal with timezone issues & logging
if (!ini_get('date.timezone')) {
    date_default_timezone_set(@date_default_timezone_get());
}

/*
 * Gets the latest user agent. Back-ups the old version first. it will fail silently if something is wrong...
 */
function get($file,$silent,$nobackup,$basePath) {
	if (ini_get('allow_url_fopen')) {
       if ($data = @file_get_contents($file)) {
            if (!$silent) { print "loading and converting YAML data...\n"; };
            $data = Spyc::YAMLLoad($data);
            $data = json_encode($data);
            if (!$silent) { print "encoded as JSON...\n"; };
            if (file_exists($basePath."resources/regexes.json")) {
                if (!$nobackup) { 
                    if (!$silent) { print("backing up old JSON file...\n"); }
                    if (!copy($basePath."resources/regexes.json", $basePath."resources/regexes.".date("Ymdhis").".json")) {
                        if (!$silent) { print("back-up failed...\n"); }
                        exit;
                    }
                }
            }
            file_put_contents($basePath."resources/regexes.json", $data);
            if (!$silent) { print("saved JSON file...\n"); }
        } else {
            if (!$silent) { print("failed to get the file...\n"); }
        }
    } else {
        if (!$silent) { print("ERROR: the allow_url_fopen option is not enabled in php.ini. it needs to be set to 'On' for this feature to work...\n"); }
    }
}

/*
 * Main logic for the CLI for the parser
 */
if (php_sapi_name() == 'cli') {
    
    // define the supported argument flags
    $args = getopt("gsncyl:pj:");
    
    // process the arguments
    if (isset($args["g"])) {
        
        /* Get regexes.yaml from the repo and convert it to JSON */
        
        // set-up some standard vars
        $silent   = isset($args["s"]) ? true : false;
        $nobackup = isset($args["n"]) ? true : false;
        
        // start chatty
        if (!$silent) {
            print "getting the YAML file from the repo...\n";
        }
        
        // get the file
        get("https://raw.github.com/tobie/ua-parser/master/regexes.yaml",$silent,$nobackup,$basePath);
        
    } else if (isset($args["c"])) {
    
        /* Convert regexes.yaml to regexes.json */
        
        // set-up some standard vars
        $silent   = isset($args["s"]) ? true : false;
        $nobackup = isset($args["n"]) ? true : false;
        
        // start chatty
        if (!$silent) {
            print "getting the old YAML file...\n";
        }
        
        // get the file
        get($basePath."resources/regexes.yaml",$silent,$nobackup,$basePath);

    } else if (isset($args["y"])) {
	
		/* Grabs regexes.yaml from the repo and saves it */
		
		if ($data = @file_get_contents("https://raw.github.com/tobie/ua-parser/master/regexes.yaml")) {
	        file_put_contents($basePath."resources/regexes.yaml", $data);
	        print("saved YAML file from the repo...\n");
	    } else {
	        print("failed to get the YAML file from the repo...\n");
	    }
        
    } else if (isset($args["l"]) && $args["l"]) {
        
        /* Parse the supplied Apache log file */
        
        // load the parser
        $parser = new UAParser;
        
        // set-up some standard vars
        $i       = 0;
        $output  = "";
        $saved   = array();
        $data    = @fopen($args["l"], "r");
        
        if ($data) {
            $fp = fopen($basePath."log/results-".date("YmdHis").".txt", "w");
            while (($line = fgets($data)) !== false) {
                $failure = false;
                $show    = "";
                $line    = str_replace("\n","",$line);
                preg_match("/^(\S+) (\S+) (\S+) \[([^:]+):(\d+:\d+:\d+) ([^\]]+)\] \"(\S+) (.*?) (\S+)\" (\S+) (\S+) (\".*?\") (\"(.*?)\")$/", $line, $items);
                $ua = (isset($items[14])) ? $items[14] : "";
                if (!empty($ua) && ($ua != "-")) {
                    $result = $parser->parse($ua);
                    if (($result->ua->family == "Other") && ($result->device->family != "Spider")) {
                        $output  = "UA Not Found: ".$ua."  [".$line."]\n";
                        $show    = "U";
                    } else if (($result->os->family == "Other") && ($result->device->family != "Spider")) {
                        $output  = "OS Not Found: ".$ua."  [".$line."]\n";
                        $show    = "O";
                    } else if ($result->device->family == "Generic Smartphone") {
                        $output  = "GS:           ".$ua."  [".$line."]\n";
                        $show    = "GS";
                    } else if ($result->device->family == "Generic Feature Phone") {
                        $output  = "GFP:          ".$ua."  [".$line."]\n";
                        $show    = "GFP";
                    }
                    if ((($show == "U") || ($show == "O") || ($show == "GS") || ($show == "GFP")) && !in_array($ua,$saved)) {
                        fwrite($fp, $output);
                        $saved[] = $ua;
                        print $show;
                    } else {
                        $i = ($i < 20) ? $i+1 : 0;
                        if ($i == 0) {
                            print ".";
                        }
                    }
                }
            }
            if (!feof($data)) {
                print "Error: unexpected fgets() fail\n";
            }
            fclose($fp);
            fclose($data);
            print "\ncompleted the evaluation of the log file at ".$args["l"]."\n";
        } else { 
            print "unable to read the file at the supplied path...\n";
        }
        
    } else if (isset($args["j"]) && $args["j"]) {
        
        /* Parse the supplied UA from the command line and kick it out as JSON */
        
        // load the parser
        $parser = new UAParser;
        
        // parse and encode the results
        if (version_compare(PHP_VERSION, '5.4.0', '>=') && isset($args["p"])) {
            print json_encode($parser->parse($args["j"]), JSON_PRETTY_PRINT);
        } else {
            print json_encode($parser->parse($args["j"]));
        }
        print PHP_EOL;
        
    } else if (isset($argv[1]) && (($argv[1] != "-j") && ($argv[1] != "-p") && ($argv[1] != "-l") && ($argv[1] != "-s") && ($argv[1] != "-n"))) {
        
        /* Parse the supplied UA from the command line and kick it out as JSON */
        
        // load the parser
        $parser = new UAParser;
        
        // parse and print the results
        $result = $parser->parse($argv[1]);
        print "  ua-parser results for \"".$argv[1]."\"\n";
        foreach ($result as $key => $value) {
            if (gettype($value) == "object") {
                foreach ($value as $key2 => $value2) {
                    print "    ".$key."->".$key2.": ".$value2."\n";
                }
            } else {
                print "    ".$key.": ".$value."\n";
            }
        }
        
    } else {
        
        /* Print usage information */
        
        print "\n";
        print "Usage:\n";
        print "\n";
        print "  php uaparser-cli.php [-p] [-j] \"your user agent string\"\n";
        print "    Parses a user agent string and dumps the results as a list.\n";
        print "    Use the -j flag to print the result as JSON.\n";
        print "    Use the -p flag to pretty print the JSON result when using PHP 5.4+.\n";
        print "\n";
        print "  php uaparser-cli.php -g [-s] [-n]\n";
        print "    Fetches an updated YAML file for ua-parser and overwrites the current JSON file.\n";
        print "    By default is verbose. Use -s to turn that feature off.\n";
        print "    By default creates a back-up. Use -n to turn that feature off.\n";
        print "\n";
        print "  php uaparser-cli.php -c [-s] [-n]\n";
        print "    Converts an existing regexes.yaml file to a regexes.json file.\n";
        print "    By default is verbose. Use -s to turn that feature off.\n";
        print "    By default creates a back-up. Use -n to turn that feature off.\n";
        print "\n";
        print "  php uaparser-cli.php -y\n";
        print "    Fetches an updated YAML file. If you need to add a new UA it's easier to edit\n";
        print "    the original YAML and then convert it. Warning: This method overwrites any\n";
        print "    existing regexes.yaml file.\n";
        print "\n";
        print "  php uaparser-cli.php -l \"/path/to/apache/logfile\"\n";
        print "    Parses the supplied Apache log file to test UAParser.php. Saves the UA to a file\n";
        print "    when the UA or OS family aren't found or when the UA is listed as a generic\n";
        print "    smartphone or as a generic feature phone.\n";
        print "\n";
        
    }
    
} else {
    
    print "You must run this file from the command line.";
    
}
