<?php

/**
 * Boots OWA with the SQS constants a mode names (tests/SqsNodeConfigTest.php),
 * and prints what it found as one JSON line.
 *
 *   php sqs_node_probe.php <mode>
 *
 *   url        OWA_SQS_QUEUE_URL and temporary credentials (OWA_SQS_KEY,
 *              OWA_SQS_SECRET, OWA_SQS_TOKEN); the region from the URL
 *   region     the same, with OWA_SQS_REGION naming another region
 *   collector  the same as url, on OWA_SERVER_ROLE collector
 *   derived    no queue URL: OWA_SQS_REGION and credentials from the
 *              environment, the module switched on by the caller
 *   none       no SQS constant
 */

$mode = $argv[1] ?? '';

// Credentials from the environment, carrying their account id, as the SDK's
// default chain reads them: no metadata service, and no request to SQS.
putenv( 'AWS_ACCESS_KEY_ID=AKIAPROBEEXAMPLE' );
putenv( 'AWS_SECRET_ACCESS_KEY=probe-secret' );
putenv( 'AWS_ACCOUNT_ID=123456789012' );

if ( in_array( $mode, array( 'url', 'region', 'collector' ), true ) ) {
    define( 'OWA_SQS_QUEUE_URL', 'https://sqs.eu-west-2.amazonaws.com/210987654321/my-own-queue' );
    define( 'OWA_SQS_KEY', 'ASIAPROBEEXAMPLE' );
    define( 'OWA_SQS_SECRET', 'probe-secret' );
    define( 'OWA_SQS_TOKEN', 'probe-session-token' );
}

if ( $mode === 'region' ) {
    define( 'OWA_SQS_REGION', 'eu-central-1' );
}

if ( $mode === 'collector' ) {
    define( 'OWA_SERVER_ROLE', 'collector' );
}

if ( $mode === 'derived' ) {
    define( 'OWA_SQS_REGION', 'eu-west-2' );
}

chdir( dirname( __DIR__, 2 ) );
require 'owa.php';

new owa( $mode === 'derived'
    ? array( 'base' => array( 'request_type' => 'tracking' ), 'sqs' => array( 'is_active' => true ) )
    : array( 'request_type' => 'tracking' ) );

$active = \OWA\Core\CoreAPI::getActiveModules();
$out    = array(
    'active'    => $active,
    'governed'  => \OWA\Core\CoreAPI::configSingleton()->configFileConstantFor( 'sqs', 'is_active' ),
    'backend'   => \OWA\Module\Base\Classes\TrackerIngest::backend(),
    'queued'    => \OWA\Module\Base\Classes\TrackerIngest::isQueued(),
    'connected' => (bool) \OWA\Core\CoreAPI::dbSingleton()->isConnectionEstablished(),
);

if ( in_array( 'sqs', $active, true ) ) {

    $queue = new \OWA\Module\Sqs\Classes\SqsQueue();

    $out += array(
        'sqs_type' => (bool) \OWA\Core\CoreAPI::serviceSingleton()->getMapValue( 'event_queue_types', 'sqs' ),
        'region'   => \OWA\Module\Sqs\Classes\Sqs::region(),
        'name'     => $queue->name(),
        'derived'  => \OWA\Module\Sqs\Classes\Sqs::queueName(),
        // url( false ): built from the credentials' account, so no request is made.
        'url'      => $queue->url( false ),
        'dlq_url'  => $queue->deadLetterQueue()->url( false ),
        'token'    => \OWA\Module\Sqs\Classes\Sqs::clientArgs( 'eu-west-2' )['credentials']['token'] ?? null,
        'source'   => \OWA\Module\Sqs\Classes\Sqs::credentialSource(),
    );
}

echo 'PROBE ' . json_encode( $out ) . "\n";
