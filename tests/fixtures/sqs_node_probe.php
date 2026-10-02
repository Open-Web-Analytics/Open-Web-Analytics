<?php
/**
 * Boots OWA as a logging node configured only in owa-config.php would be
 * (tests/SqsNodeConfigTest.php), and prints what it found as one JSON line.
 *
 *   php sqs_node_probe.php [static]
 *
 * "static" adds OWA_USE_STATIC_CONFIG_ONLY, so nothing is read from the
 * database.
 */
define( 'OWA_ACTIVE_MODULES', array( 'sqs', 'no_such_module' ) );
define( 'OWA_SQS_QUEUE_URL', 'https://sqs.eu-west-2.amazonaws.com/123456789012/owa-tracker-ingest-0123456789ab' );

if ( ( $argv[1] ?? '' ) === 'static' ) {
    define( 'OWA_USE_STATIC_CONFIG_ONLY', true );
}

chdir( dirname( __DIR__, 2 ) );
require 'owa.php';
new owa( array( 'instance_role' => 'logger' ) );

$queue = new \OWA\Module\Sqs\Classes\SqsQueue();

echo 'PROBE ' . json_encode( array(
    'active'   => \OWA\Core\CoreAPI::getActiveModules(),
    'sqs_type' => (bool) \OWA\Core\CoreAPI::serviceSingleton()->getMapValue( 'event_queue_types', 'sqs' ),
    'governed' => \OWA\Core\CoreAPI::configSingleton()->configFileConstantFor( 'sqs', 'is_active' ),
    'region'   => \OWA\Module\Sqs\Classes\Sqs::region(),
    'name'     => $queue->name(),
    // url( false ): from the constant, so no request is made.
    'url'      => $queue->url( false ),
    'dlq_url'  => $queue->deadLetterQueue()->url( false ),
) ) . "\n";
