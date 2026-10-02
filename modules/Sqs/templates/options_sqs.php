<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></div>

<div id="panel">

<fieldset name="owa-sqs-status" class="options">
<legend>Status</legend>

    <div class="setting" id="sqs_queues">
        <div class="title">Queues</div>
        <div class="description">
            <code><?php $view->out( $view->queue_name ); ?></code> and its dead-letter queue
            <code><?php $view->out( $view->dlq_name ); ?></code>. The names are derived from this
            install's database, so a logging node with the same <code>owa-config.php</code> finds them.
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
        <?php if ( $view->in_use ): ?>
            Yes: <code>tracker_ingest_queue_type</code> is <code>sqs</code>.
        <?php else: ?>
            No. Beacons go to the file queue until <code>owa-config.php</code> sets
            <code>define('OWA_TRACKER_INGEST_QUEUE_TYPE', 'sqs');</code>
        <?php endif; ?>
        </div>
    </div>

</fieldset>

<form method="post" name="owa_options">

<?php
foreach ( $view->settings_fieldsets as $set ) {

    echo \OWA\Module\Base\Classes\SettingsForm::fieldSet( $set, $view->getNs() );
}
?>

    <BR>

    <?php echo $view->createNonceFormField('sqs.optionsSqsUpdate');?>

    <BUTTON class="owa-button" type="submit" name="<?php echo $view->getNs();?>action" value="sqs.optionsSqsUpdate">Save</BUTTON>
    <input type="hidden" name="<?php echo $view->getNs();?>module" value="sqs">

</form>
</div>
