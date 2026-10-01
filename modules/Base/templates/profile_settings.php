<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * How this Profile watches its Property.
 * 
 * An Observation Profile IS a way of observing, so these are the settings
 * that define it. Split out of the old three-form Profile page.
 */
?>
<DIV class="panel_headline">Observation Settings</DIV>
<div id="panel">
<div class="owa_panelIntro">How this Observation Profile watches its Property. A Profile is one way of observing a Property &mdash; these settings define what it records and what it ignores. They apply to this Profile only; another Profile on the same Property can observe it differently.</div>
<form method="post" name="owa_options">

    <?php
        /*
         * From the registry, at Profile scope: each field shows what the Profile
         * inherits until its Override switch is turned on. See
         * SettingsForm::scopedField().
         */
        echo \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet(
            \OWA\Module\Base\Classes\SettingsForm::registeredFieldSet( 'base.profileObservation' ),
            'profile', (string) ( $view->site['site_id'] ?? '' ), $view->getNs(),
            is_array( $view->posted ?? null ) ? $view->posted : null );
    ?>

    <fieldset name="owa-options" class="options">

        <?php echo $view->createNonceFormField('base.sitesEditSettings');?>
        <input type="hidden" name="<?php echo $view->getNs();?>siteId" value="<?php $view->out( $view->site['site_id'] ?? '' );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>module" value="base">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.sitesEditSettings">
        <input type="submit" name="<?php echo $view->getNs();?>submit_btn" value="Save Settings" class="owa-button">
    </fieldset>
</form>
</div>
