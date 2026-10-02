<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline">Tracking Tag</div>
<div id="panel">

<P>The Domain for this web site is: <span class=""><B><?php $view->out( $view->site->get('domain') );?></B></P>
<P>The Site ID for this web site is: <span class=""><B><?php $view->out( $view->site_id ); ?></B></P>

<div class="owa_panelIntro">
<?php if ( $view->last_event ): ?>
    <b>Last event received:</b> <?php $view->out( \OWA\Module\Base\Classes\JobStatus::readable( $view->last_event ) ); ?>.
<?php else: ?>
    <b>No events received yet.</b> Once the tag below is on your pages and one of them is visited,
    the first event shows here.
<?php endif; ?>
</div>

<?php include('invocation.php');?>

<form method="post" name="owa_tag_settings">

    <?php
        /*
         * What this Profile's tracking bundle does (PLAN 2.24.4): every fieldset
         * in the tracking_tag group, Base's and each active module's, at Profile
         * scope. Each field shows what the Profile inherits until its Override
         * switch is on. See SettingsForm::scopedField().
         */
        foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'tracking_tag' ) as $set ) {

            echo \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet(
                $set, 'profile', (string) $view->site_id, $view->getNs() );
        }
    ?>

    <?php echo $view->createNonceFormField( 'base.sitesEditTagSettings' );?>
    <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->site_id );?>">
    <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.sitesEditTagSettings">
    <input type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Tag Settings" class="owa-button">
</form>
</div>
