--TEST--
Log: Composite Handler
--FILE--
<?php

require_once 'Log.php';

$conf = array('lineFormat' => '%2$s [%3$s] %4$s');
$console1 = &Log::singleton('console', '', 'CONSOLE1', $conf);
$console2 = &Log::singleton('console', '', 'CONSOLE2', $conf);

$composite = &Log::singleton('composite');
$composite->addChild($console1);
$composite->addChild($console2);

$composite->log('This event will be logged to both handlers.');

$composite->setIdent('IDENT');
echo $composite->getIdent() . "\n";

$composite->log('This event will be logged to both handlers.');

--EXPECT--
CONSOLE1 [info] This event will be logged to both handlers.
CONSOLE2 [info] This event will be logged to both handlers.
IDENT
IDENT [info] This event will be logged to both handlers.
IDENT [info] This event will be logged to both handlers.
