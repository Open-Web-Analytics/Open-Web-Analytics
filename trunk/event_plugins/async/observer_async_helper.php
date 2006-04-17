<?php

require_once OWA_BASE_DIR .'/owa_env.php';

class Log_observer_async_helper extends Log_observer {

	var $processor_path = OWA_BASE_DIR;
	var $processor_script = 'process_log.php';
	var $shell_args;
	var $config;

    function Log_observer_async_helper($priority, $conf)
    {
        /* Call the base class constructor. */
        $this->Log_observer($priority);

        /* Configure the observer. */
		$this->_event_type = array('session_update');

		/* Setup configuration */
		return;
    }

    function notify($event) {
	
	//	$this->execInBackground($this->processor_path, $this->processor_script, $this->shell_args);
	
		return;
	}
		
		
	function execInBackground($path, $exe, $args = "") {
   
   	//	if (file_exists($path ."/". $exe)):
       		chdir($path);
			print getcwd() . "\n";
       		if (substr(php_uname(), 0, 7) == "Windows"):
           		pclose(popen("start \"bla\" \"" . $exe . "\" " . escapeshellarg($args), "r"));    
       		else:
			//	print "php " . $path . "/" . $exe . " " . escapeshellarg($args) . " > /dev/null &";
           	exec("php " . $exe . " > /dev/null &");    
       		endif;
   		//endif;
		
		return;
	
		
		
    }
}

?>
