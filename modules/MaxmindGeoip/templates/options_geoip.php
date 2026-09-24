<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></div>

<?php /* #panel, like every settings screen -- see options_general.php. */ ?>
<div id="panel">

<?php
// Status first, because it is the question someone opening this page actually
// has: is geolocation working right now. A missing key and a missing database
// both present as "locations are blank", and neither was visible anywhere
// before this page existed.
?>
<fieldset name="owa-geoip-status" class="options">
<legend>Status</legend>

    <div class="setting" id="geoip_status">
        <div class="title">Database</div>
        <div class="description">
        <?php if ( $view->db_present ): ?>
            <?php echo htmlspecialchars( basename( (string) $view->db_file ), ENT_QUOTES, 'UTF-8' ); ?>
            is in place, last updated
            <?php echo htmlspecialchars( gmdate( 'j M Y', (int) $view->db_updated ), ENT_QUOTES, 'UTF-8' ); ?>.
            MaxMind publish updates twice a week. A database that is out of date does not fail &mdash;
            it answers with locations that have since changed hands, and the reports look normal.
        <?php else: ?>
            <strong>No database is installed</strong>, so lookups cannot resolve a location.
            Expected at <?php echo htmlspecialchars( (string) $view->db_file, ENT_QUOTES, 'UTF-8' ); ?>.
        <?php endif; ?>
        </div>
    </div>

    <div class="setting" id="geoip_key">
        <div class="title">Licence key</div>
        <div class="description">
        <?php if ( $view->has_key ): ?>
            A key is set, so the database can be downloaded and refreshed.
        <?php else: ?>
            <strong>No key is set</strong>, so the database cannot be downloaded. The field
            below is where it goes.
        <?php endif; ?>
        </div>
    </div>

    <div class="setting" id="geoip_refresh">
        <div class="title">Updating</div>
        <div class="description">
            Downloading runs from the command line, because it fetches tens of megabytes and can take
            longer than a web request is allowed to:<br>
            <code>php cli.php cmd=update-geoip-db</code><br>
            It checks with MaxMind first and does nothing if your copy is already current.
        </div>
    </div>

</fieldset>

<form method="post" name="owa_options">

<?php
/*
 * Built from the registry, not written out here. What each control is, what it
 * is called and what it offers are all in modules/MaxmindGeoip/settings.php,
 * and registerSettingsFieldSet() in Module.php says which appear here and in
 * what order.
 */
foreach ( $view->settings_fieldsets as $set ) {

    echo \OWA\Module\Base\Classes\SettingsForm::fieldSet( $set, $view->getNs() );
}
?>

    <BR>

    <?php // This module's own save action, which returns here afterwards. ?>
    <?php echo $view->createNonceFormField('maxmind_geoip.optionsGeoipUpdate');?>

    <BUTTON class="owa-button" type="submit" name="<?php echo $view->getNs();?>action" value="maxmind_geoip.optionsGeoipUpdate">Update Options</BUTTON>
    <input type="hidden" name="<?php echo $view->getNs();?>module" value="maxmind_geoip">

</form>
</div>
