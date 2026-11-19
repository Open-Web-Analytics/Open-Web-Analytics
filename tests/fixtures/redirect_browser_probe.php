<?php

/**
 * Redirects through Lib::redirectBrowser() to PROBE_URL under php-cgi, on an
 * installation at https://owa.example.test/owa/ (tests/RedirectTargetTest.php).
 */
require dirname( __DIR__ ) . '/bootstrap_owa.php';

\OWA\Core\CoreAPI::setSetting( 'base', 'public_url', 'https://owa.example.test/owa/' );

\OWA\Core\Lib::redirectBrowser( (string) getenv( 'PROBE_URL' ) );
