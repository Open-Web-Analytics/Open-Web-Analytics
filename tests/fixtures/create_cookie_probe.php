<?php

/**
 * Sets one cookie through CoreAPI::createCookie() under php-cgi, which prints
 * the response's headers (tests/CreateCookieOptionsTest.php). PROBE_SECURE=1
 * passes $secure true.
 */
require dirname( __DIR__ ) . '/bootstrap_owa.php';

\OWA\Core\CoreAPI::createCookie( 'probe', 'v', 0, '/', '', getenv( 'PROBE_SECURE' ) === '1', false );
