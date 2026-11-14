<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></h1>

<div id="panel">

<fieldset name="owa-sqs-status" class="options">
<legend>Status</legend>

    <div class="setting" id="sqs_queues">
        <div class="title">Queues</div>
        <div class="description">
            <code><?php $view->out( $view->queue_name ); ?></code> and its dead-letter queue
            <code><?php $view->out( $view->dlq_name ); ?></code>. <code>OWA_SQS_QUEUE_URL</code> in
            <code>owa-config.php</code> names the queue; without it the names are derived from this
            install's database.
        </div>
    </div>

    <div class="setting" id="sqs_provisioned">
        <div class="title">Provisioning</div>
        <div class="description">
        <?php $p = $view->provisioned; ?>
        <?php if ( ! $p ): ?>
            Not yet. The queues are created when a region is saved here, when the module is activated,
            or by <code>php cli.php cmd=tracker-ingest-provision</code>.
        <?php elseif ( ! empty( $p['ok'] ) ): ?>
            In place since <?php $view->out( gmdate( 'j M Y H:i', (int) $p['at'] ) ); ?> UTC.
            A collector, a server that receives beacons and has no database, needs:<br>
            <code>define('OWA_SERVER_ROLE', 'collector');</code><br>
            <code>define('OWA_SQS_QUEUE_URL', '<?php $view->out( (string) ( $p['main'] ?? '' ) ); ?>');</code><br>
            Its credentials need <code>sqs:GetQueueUrl</code>, <code>sqs:GetQueueAttributes</code> and
            <code>sqs:SendMessage</code>. If they carry the account id (<code>AWS_ACCOUNT_ID</code>, or
            <code>aws_account_id</code> in its AWS profile), it builds the queue URL itself and makes one
            request per beacon instead of two. A beacon the queue does not take is kept in the node's file
            queue; run <code>php cli.php cmd=drain-tracker-ingest forward_only=1</code> from its cron to
            forward those.
        <?php else: ?>
            <strong>Failed</strong> <?php $view->out( gmdate( 'j M Y H:i', (int) $p['at'] ) ); ?> UTC:
            <?php $view->out( (string) $p['error'] ); ?>
        <?php endif; ?>
        </div>
    </div>

    <div class="setting" id="sqs_credentials">
        <div class="title">Credentials</div>
        <div class="description">
            From <?php $view->out( $view->credential_source ); ?>. They are never stored by OWA.
            The identity needs <code>sqs:CreateQueue</code>, <code>sqs:GetQueueUrl</code>,
            <code>sqs:GetQueueAttributes</code>, <code>sqs:SetQueueAttributes</code>,
            <code>sqs:SendMessage</code>, <code>sqs:ReceiveMessage</code>, <code>sqs:DeleteMessage</code>
            and <code>sqs:ChangeMessageVisibility</code> on <code>owa-tracker-ingest-*</code>.
        </div>
    </div>

    <div class="setting" id="sqs_in_use">
        <div class="title">In use</div>
        <div class="description">
        <?php if ( $view->in_use && $view->queued ): ?>
            Yes: <code>OWA_SQS_QUEUE_URL</code> is set, and every beacon is queued here.
        <?php elseif ( $view->in_use ): ?>
            Yes: <code>OWA_SQS_QUEUE_URL</code> is set. Beacons are written as they arrive; only one whose
            write fails is queued here. <code>define('OWA_QUEUE_TRACKING_EVENTS', true);</code> queues every beacon.
        <?php else: ?>
            No. Beacons go to the file queue until <code>owa-config.php</code> sets
            <code>OWA_SQS_QUEUE_URL</code>.
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

    <BUTTON class="owa-button" type="submit" name="action" value="sqs.optionsSqsUpdate">Save</BUTTON>
    <input type="hidden" name="module" value="sqs">

</form>
</div>
