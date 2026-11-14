<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></h1>
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

    echo \OWA\Module\Base\Classes\SettingsForm::fieldSet( $set );
}
?>
    <?php echo $view->createNonceFormField( 'base.optionsRetentionUpdate' );?>
    <input type="hidden" name="module" value="base">
    <BUTTON class="owa-button" type="submit" name="action" value="base.optionsRetentionUpdate"><?php $view->out( \OWA\Core\CoreAPI::t( 'Save' ) ); ?></BUTTON>
</form>
</div>
