--TEST--
Log: Error_Log Handler
--SKIPIF--
<?php if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') die("skip\n"); ?>
--FILE--
<?php

require_once 'Log.php';

$logger = &Log::singleton('error_log', PEAR_LOG_TYPE_SYSTEM, 'ident');
for ($i = 0; $i < 3; $i++) {
	$logger->log("Log entry $i");
}

--EXPECT--
ident: Log entry 0
ident: Log entry 1
ident: Log entry 2
