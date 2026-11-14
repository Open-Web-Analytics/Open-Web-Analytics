<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></h1>

<div id="panel">

<fieldset name="owa-sqs-status" class="options">
<legend><?php $view->out( \OWA\Core\CoreAPI::t( 'Status' ) ); ?></legend>

    <div class="setting" id="sqs_queues">
        <div class="title"><?php $view->out( \OWA\Core\CoreAPI::t( 'Queues' ) ); ?></div>
        <div class="description">
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( '%1$s and its dead-letter queue %2$s.' ),
                '<code>' . htmlspecialchars( (string) $view->queue_name, ENT_QUOTES, 'UTF-8' ) . '</code>',
                '<code>' . htmlspecialchars( (string) $view->dlq_name, ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'The names are derived from this install\'s database, so a logging node with the same %s finds them.' ),
                '<code>owa-config.php</code>' ); ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( '%1$s in %2$s names the queue instead.' ),
                '<code>OWA_SQS_QUEUE_URL</code>',
                '<code>owa-config.php</code>' ); ?>
        </div>
    </div>

    <div class="setting" id="sqs_provisioned">
        <div class="title"><?php $view->out( \OWA\Core\CoreAPI::t( 'Provisioning' ) ); ?></div>
        <div class="description">
        <?php $p = $view->provisioned; ?>
        <?php if ( ! $p ): ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Not yet. The queues are created when a region is saved here, when the module is activated, or by %s.' ),
                '<code>php cli.php cmd=tracker-ingest-provision</code>' ); ?>
        <?php elseif ( ! empty( $p['ok'] ) ): ?>
            <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'In place since %s UTC.' ), gmdate( 'j M Y H:i', (int) $p['at'] ) ) ); ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'A logging node that cannot read this install\'s database, with the same %s database settings so it derives the same queue name, needs:' ),
                '<code>owa-config.php</code>' ); ?><br>
            <code>define('OWA_ACTIVE_MODULES', array('sqs'));</code><br>
            <code>define('OWA_TRACKER_INGEST_QUEUE_TYPE', 'sqs');</code><br>
            <code>define('OWA_QUEUE_TRACKER_INGEST', true);</code><br>
            <code>define('OWA_SQS_REGION', '<?php $view->out( (string) $view->region ); ?>');</code><br>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Its credentials need %1$s, %2$s and %3$s.' ),
                '<code>sqs:GetQueueUrl</code>',
                '<code>sqs:GetQueueAttributes</code>',
                '<code>sqs:SendMessage</code>' ); ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'If they carry the account id (%1$s, or %2$s in its AWS profile), it builds the queue URL itself and makes one request per beacon instead of two.' ),
                '<code>AWS_ACCOUNT_ID</code>',
                '<code>aws_account_id</code>' ); ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'A beacon the queue does not take is kept in the node\'s file queue; run %s from its cron to forward those.' ),
                '<code>php cli.php cmd=drain-tracker-ingest forward_only=1</code>' ); ?>
        <?php else: ?>
            <strong><?php $view->out( \OWA\Core\CoreAPI::t( 'Failed' ) ); ?></strong> <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '%s UTC:' ), gmdate( 'j M Y H:i', (int) $p['at'] ) ) ); ?>
            <?php $view->out( (string) $p['error'] ); ?>
        <?php endif; ?>
        </div>
    </div>

    <div class="setting" id="sqs_credentials">
        <div class="title"><?php $view->out( \OWA\Core\CoreAPI::t( 'Credentials' ) ); ?></div>
        <div class="description">
            <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'From %s. They are never stored by OWA.' ), $view->credential_source ) ); ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'The identity needs %1$s, %2$s, %3$s, %4$s, %5$s, %6$s, %7$s and %8$s on %9$s.' ),
                '<code>sqs:CreateQueue</code>',
                '<code>sqs:GetQueueUrl</code>',
                '<code>sqs:GetQueueAttributes</code>',
                '<code>sqs:SetQueueAttributes</code>',
                '<code>sqs:SendMessage</code>',
                '<code>sqs:ReceiveMessage</code>',
                '<code>sqs:DeleteMessage</code>',
                '<code>sqs:ChangeMessageVisibility</code>',
                '<code>owa-tracker-ingest-*</code>' ); ?>
        </div>
    </div>

    <div class="setting" id="sqs_in_use">
        <div class="title"><?php $view->out( \OWA\Core\CoreAPI::t( 'In use' ) ); ?></div>
        <div class="description">
        <?php if ( $view->in_use ): ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Yes: %1$s is %2$s.' ),
                '<code>tracker_ingest_queue_type</code>',
                '<code>sqs</code>' ); ?>
        <?php else: ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'No. Beacons go to the file queue until %1$s sets %2$s' ),
                '<code>owa-config.php</code>',
                '<code>define(\'OWA_TRACKER_INGEST_QUEUE_TYPE\', \'sqs\');</code>' ); ?>
        <?php endif; ?>
        </div>
    </div>

</fieldset>

<form method="post" name="owa_options">

<?php
foreach ( $view->settings_fieldsets as $set ) {

    echo \OWA\Module\Base\Classes\SettingsForm::fieldSet( $set );
}
?>

    <BR>

    <?php echo $view->createNonceFormField('sqs.optionsSqsUpdate');?>

    <BUTTON class="owa-button" type="submit" name="action" value="sqs.optionsSqsUpdate"><?php $view->out( \OWA\Core\CoreAPI::t( 'Save' ) ); ?></BUTTON>
    <input type="hidden" name="module" value="sqs">

</form>
</div>
