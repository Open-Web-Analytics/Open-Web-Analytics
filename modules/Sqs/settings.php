<?php

/**
 * What this module stores, and what boot needs of it.
 *
 * Read from disk before any module object exists -- see
 * Core\Module::settingsRegistry() for why that is forced rather than chosen.
 *
 * is_active and schema_version are NOT here. Core\Module::settingsRegistry()
 * adds those to every module, from mechanicalSettings().
 *
 * No credentials and no queue name. Credentials come from the AWS SDK's
 * default chain, or OWA_SQS_KEY / OWA_SQS_SECRET in owa-config.php, never the
 * database; the queue names are derived (Classes\Sqs::queueName()).
 */
return array(

    'module' => 'sqs',

    'settings' => array(

        /*
         * Read on log.php's path when the intake is sqs, so eager. No
         * default: unset, the SDK's own region chain (AWS_REGION, ~/.aws)
         * decides, which is what a logging node with OWA_SQS_REGION in its
         * owa-config.php relies on.
         */
        'region' => array(
            'storable'    => true,
            'autoload'    => true,
            'type'        => 'text',
            'pattern'     => '/^[a-z]{2}(-gov)?-[a-z]+-\d$/',
            'pattern_problem' => \OWA\Core\CoreAPI::t( 'An AWS region looks like us-east-1.' ),
            'label'       => 'AWS Region',
            'description' => 'The region the tracker-ingest queues live in, such as us-east-1.',
        ),

        /*
         * The last provisioning outcome -- ok, at, error, queue URLs -- for
         * the settings screen. Written by Classes\Sqs::provision(), never by
         * a form.
         */
        'provisioned' => array(
            'storable' => true,
            'internal' => true,
        ),
    ),
);
