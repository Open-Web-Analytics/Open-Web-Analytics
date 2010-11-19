<?php

if ( ! class_exists('Daemon' ) ) {
	require_once( OWA_INCLUDE_DIR.'Daemon.class.php' );
}

class owa_daemon extends Daemon {
	
	var $pids = array();
	var $params = array();
	var $max_workers = 5;
	
	function __construct() {
		
		$this->params = $this->getArgs();
		return parent::__construct();
	}
	
	function getArgs() {
		
		$params = array();
		// get params from the command line args
		// $argv is a php super global variable
		for ( $i=1; $i < count( $argv ); $i++ ) {
			$it = split("=",$argv[$i]);
			$params[$it[0]] = $it[1];
		}
		
		return $params;
	}

	function _logMessage($msg, $status = DLOG_NOTICE) {
		
		if ($status & DLOG_TO_CONSOLE) {
        	echo $msg."\n";
        }
        
		owa_coreAPI::notice("Daemon: $msg");
	}

	function _doTask() {
		
		if ( count( $this->pids ) < $this->max_workers ) {
 			
 			$pid = pcntl_fork();
 			
			if ( ! $pid ) {
 				//pcntl_exec( $program, $arguments ); // takes an array of arguments
 				owa_coreAPI::debug( 'hello from new child process ');
 				exit();
 			} else {
				// We add pids to a global array, so that when we get a kill signal
				// we tell the kids to flush and exit.
				$this->pids[] = $pid;
			}
		}

		// Collect any children which have exited on their own. pcntl_waitpid will
		// return the PID that exited or 0 or ERROR
		// WNOHANG means we won't sit here waiting if there's not a child ready
		// for us to reap immediately
		// -1 means any child
		$dead_and_gone = pcntl_waitpid( -1, $status, WNOHANG);
		
		while( $dead_and_gone > 0 ){
			// Remove the gone pid from the array
			unset( $this->pids[array_search( $dead_and_gone, $this->pids )] ); 
		
			// Look for another one
			$dead_and_gone = pcntl_waitpid(-1,$status,WNOHANG);
		}
		
		// Sleep for 1 second
		sleep(1);
	}
}

?>