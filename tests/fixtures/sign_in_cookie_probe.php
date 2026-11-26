<?php

/**
 * Sets one cookie through CoreAPI::createSignInCookie() under php-cgi, which
 * prints the response's headers (tests/CreateCookieOptionsTest.php), for an
 * install at PROBE_PUBLIC_URL with cookie_domain PROBE_COOKIE_DOMAIN.
 */
require dirname( __DIR__ ) . '/bootstrap_owa.php';

\OWA\Core\CoreAPI::setSetting( 'base', 'public_url', (string) getenv( 'PROBE_PUBLIC_URL' ) );
\OWA\Core\CoreAPI::setSetting( 'base', 'cookie_domain', (string) getenv( 'PROBE_COOKIE_DOMAIN' ) );

\OWA\Core\CoreAPI::createSignInCookie( 'probe', 'v', 0 );
