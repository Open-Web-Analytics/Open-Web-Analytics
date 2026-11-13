<?php
/**
 * Boots OWA as a logging node configured only in owa-config.php would be
 * (tests/SqsNodeConfigTest.php), and prints what it found as one JSON line.
 *
 *   php sqs_node_probe.php [static|url]
 *
 * "static" adds OWA_USE_STATIC_CONFIG_ONLY, so nothing is read from the
 * database. "url" names the queue with OWA_SQS_QUEUE_URL and gives temporary
 * credentials: OWA_SQS_KEY, OWA_SQS_SECRET and OWA_SQS_TOKEN.
 */
define( 'OWA_ACTIVE_MODULES', array( 'sqs', 'no_such_module' ) );
define( 'OWA_SQS_REGION', 'eu-west-2' );

// Credentials from the environment, carrying their account id, as the SDK's
// default chain reads them: no metadata service, and no request to SQS.
putenv( 'AWS_ACCESS_KEY_ID=AKIAPROBEEXAMPLE' );
putenv( 'AWS_SECRET_ACCESS_KEY=probe-secret' );
putenv( 'AWS_ACCOUNT_ID=123456789012' );

if ( ( $argv[1] ?? '' ) === 'static' ) {
    define( 'OWA_USE_STATIC_CONFIG_ONLY', true );
}

if ( ( $argv[1] ?? '' ) === 'url' ) {
    define( 'OWA_SQS_QUEUE_URL', 'https://sqs.eu-west-2.amazonaws.com/210987654321/my-own-queue' );
    define( 'OWA_SQS_KEY', 'ASIAPROBEEXAMPLE' );
    define( 'OWA_SQS_SECRET', 'probe-secret' );
    define( 'OWA_SQS_TOKEN', 'probe-session-token' );
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
    'derived'  => \OWA\Module\Sqs\Classes\Sqs::queueName(),
    // url( false ): built from the credentials' account, so no request is made.
    'url'      => $queue->url( false ),
    'dlq_url'  => $queue->deadLetterQueue()->url( false ),
    'token'    => \OWA\Module\Sqs\Classes\Sqs::clientArgs( 'eu-west-2' )['credentials']['token'] ?? null,
    'source'   => \OWA\Module\Sqs\Classes\Sqs::credentialSource(),
) ) . "\n";
