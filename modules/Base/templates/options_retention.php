<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></div>
<div id="panel">
<?php
/*
 * The confirmation before a save is the browser's (owa.retention.js); what it
 * says comes from the server (Classes\Retention::preview()), fetched from the
 * route on the form.
 */
?>
<form method="post" name="owa_retention" data-owa-retention-form="install"
      data-owa-retention-preview="<?php $view->out( $view->makeApiLink( array(
          'do' => 'retentionPreview', 'module' => 'base', 'version' => 'v1' ) ) ); ?>">
<?php
foreach ( $view->settings_fieldsets as $set ) {

    echo \OWA\Module\Base\Classes\SettingsForm::fieldSet( $set, $view->getNs() );
}
?>
    <?php echo $view->createNonceFormField( 'base.optionsRetentionUpdate' );?>
    <input type="hidden" name="<?php echo $view->getNs();?>module" value="base">
    <BUTTON class="owa-button" type="submit" name="<?php echo $view->getNs();?>action" value="base.optionsRetentionUpdate">Save</BUTTON>
</form>
</div>
