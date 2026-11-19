<?php

/**
 * Renders the pages report of Property PROBE_PROPERTY as a signed-in admin
 * under php-cgi, which prints the response's headers
 * (tests/ReportingHomeTest.php). Needs the configured database.
 */
require dirname( __DIR__ ) . '/bootstrap_owa.php';

$user = \OWA\Core\CoreAPI::getCurrentUser();
$user->setRole( 'admin' );
$user->setAuthStatus( true );

( new \OWA\Module\Base\Controller\Report(
    [ 'reportId' => 'pages', 'propertyId' => (string) getenv( 'PROBE_PROPERTY' ) ] ) )->doAction();
