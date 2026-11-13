<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * How this Profile watches its Property.
 * 
 * An Observation Profile IS a way of observing, so these are the settings
 * that define it. Split out of the old three-form Profile page.
 */
?>
<h1 class="panel_headline">Observation Settings</h1>
<div id="panel">
<div class="owa_panelIntro">What this Observation Profile records and ignores. These settings apply to this Profile only; other Profiles of the same Property can use different ones.</div>
<form method="post" name="owa_options">

    <?php
        /*
         * From the registry, at Profile scope: each field shows what the Profile
         * inherits until its Override switch is turned on. See
         * SettingsForm::scopedField(). The group is what SitesEditSettings saves.
         */
        foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'profile_observation' ) as $owa_set ) {

            echo \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet(
                $owa_set, 'profile', (string) ( $view->site['site_id'] ?? '' ), '',
                is_array( $view->posted ?? null ) ? $view->posted : null );
        }
    ?>

    <fieldset name="owa-options" class="options">

        <?php echo $view->createNonceFormField('base.sitesEditSettings');?>
        <input type="hidden" name="siteId" value="<?php $view->out( $view->site['site_id'] ?? '' );?>">
        <input type="hidden" name="module" value="base">
        <input type="hidden" name="action" value="base.sitesEditSettings">
        <input type="submit" name="submit_btn" value="Save Settings" class="owa-button">
    </fieldset>
</form>
</div>
