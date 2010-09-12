--TEST--
Log: Backtrace Vars
--FILE--
<?php

require_once 'Log.php';

$conf = array('lineFormat' => '%6$s [%8$s::%7$s] %4$s');
$logger = &Log::singleton('console', '', 'ident', $conf);

# Top-level Logger
#
$logger->log("Top-level Logger");

# Function Logger
#
function functionLog($logger)
{
	$logger->log("Function Logger");
}

functionLog($logger);

# Class Logger
#
class ClassLogger
{
	function log($logger)
	{
		$logger->log("Class Logger - log()");
		$logger->info("Class Logger - info()");
	}
}

$classLogger = new ClassLogger();
$classLogger->log($logger);

# Composite Logger
#
$composite = &Log::singleton('composite');
$composite->addChild($logger);

$composite->log("Composite Logger - log()");
$composite->info("Composite Logger - info()");

# Composite Logger via Class
#
$classLogger->log($composite);

--EXPECT--
10 [::(none)] Top-level Logger
16 [::functionLog] Function Logger
27 [ClassLogger::log] Class Logger - log()
28 [ClassLogger::log] Class Logger - info()
40 [::(none)] Composite Logger - log()
41 [::(none)] Composite Logger - info()
27 [ClassLogger::log] Class Logger - log()
28 [ClassLogger::log] Class Logger - info()
