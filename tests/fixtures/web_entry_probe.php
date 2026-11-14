<?php

/**
 * Runs a web entry point (index.php, api/index.php, install.php) as a GET
 * request would, and prints its status and body as one JSON line, also when
 * the entry point exits (tests/ServerRoleBootTest.php).
 *
 *   php -d auto_prepend_file=role_constants_prepend.php web_entry_probe.php index.php
 */
$entry = (string) ( $argv[1] ?? 'index.php' );
$root  = dirname( __DIR__, 2 ) . '/';

$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['HTTP_HOST']       = 'owa.example.test';
$_SERVER['REQUEST_URI']     = '/' . $entry;
$_SERVER['SCRIPT_NAME']     = '/' . $entry;
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';
$_SERVER['REMOTE_ADDR']     = '203.0.113.9';

ob_start();

register_shutdown_function( static function () {

    $body = (string) ob_get_clean();

    echo 'PROBE ' . json_encode( array( 'status' => http_response_code(), 'body' => $body ) ) . "\n";
} );

chdir( dirname( $root . $entry ) );
require $root . $entry;
