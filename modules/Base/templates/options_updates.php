<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></h1>
<div id="panel">
<div data-ui="updates:state">
<?php foreach ( (array) $view->updates_state['lines'] as $line ): ?>
    <p><?php $view->out( $line ); ?></p>
<?php endforeach; ?>
</div>
<?php
$labels = [
    'check'   => \OWA\Core\CoreAPI::t( 'Check now' ),
    'install' => \OWA\Core\CoreAPI::t( 'Install now' ),
];
if ( $view->updates_state['actions'] ): ?>
<form method="post" name="owa_updates_run">
    <?php echo $view->createNonceFormField( 'base.updatesRun' );?>
    <input type="hidden" name="module" value="base">
    <input type="hidden" name="action" value="base.updatesRun">
<?php foreach ( (array) $view->updates_state['actions'] as $run ): ?>
    <button class="owa-button" type="submit" name="run" value="<?php $view->out( $run ); ?>" data-ui="updates:<?php $view->out( $run ); ?>"><?php $view->out( $labels[ $run ] ); ?></button>
<?php endforeach; ?>
    <p><?php $view->out( \OWA\Core\CoreAPI::t( 'The scheduler runs it within a minute; reload this page to see the result.' ) ); ?></p>
</form>
<?php endif; ?>

<form method="post" name="owa_updates_settings">
<?php
foreach ( $view->settings_fieldsets as $set ) {

    echo \OWA\Module\Base\Classes\SettingsForm::fieldSet( $set ); // trusted: SettingsForm escapes what it prints
}
?>
    <p><?php $view->out( \OWA\Core\CoreAPI::t( 'A change takes effect at the next release check, within an hour, or at once with Check now. Install now installs the newest downloaded release at once, whatever the update window says; these settings choose which releases are downloaded.' ) ); ?></p>
    <p><?php $view->out( \OWA\Core\CoreAPI::t( 'The release check lists OWA\'s releases four times a day, with automatic updates on or off, from OWA\'s update host unless owa-config.php names another. It sends the installed OWA version and nothing else about this installation.' ) ); ?></p>
    <?php echo $view->createNonceFormField( 'base.optionsUpdatesUpdate' );?>
    <input type="hidden" name="module" value="base">
    <BUTTON class="owa-button" type="submit" name="action" value="base.optionsUpdatesUpdate"><?php $view->out( \OWA\Core\CoreAPI::t( 'Save' ) ); ?></BUTTON>
</form>
</div>
