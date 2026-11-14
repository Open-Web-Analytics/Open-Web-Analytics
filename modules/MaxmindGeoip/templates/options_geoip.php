<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( $view->settings_page_title ); ?></h1>

<?php /* #panel, like every settings screen -- see options_general.php. */ ?>
<div id="panel">

<?php
// Status first, because it is the question someone opening this page actually
// has: is geolocation working right now. A missing key and a missing database
// both present as "locations are blank", and neither was visible anywhere
// before this page existed.
?>
<fieldset name="owa-geoip-status" class="options">
<legend><?php $view->out( \OWA\Core\CoreAPI::t( 'Status' ) ); ?></legend>

    <div class="setting" id="geoip_status">
        <div class="title"><?php $view->out( \OWA\Core\CoreAPI::t( 'Database' ) ); ?></div>
        <div class="description">
        <?php if ( $view->db_present ): ?>
            <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '%1$s is in place, last updated %2$s.' ),
                basename( (string) $view->db_file ), gmdate( 'j M Y', (int) $view->db_updated ) ) ); ?>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'MaxMind publish updates twice a week. An out-of-date database does not fail: it returns locations that may have changed since, and reports show no sign of it.' ) ); ?>
        <?php else: ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( '%1$sNo database is installed%2$s, so lookups cannot resolve a location.' ), '<strong>', '</strong>' ); ?>
            <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Expected at %s.' ), (string) $view->db_file ) ); ?>
        <?php endif; ?>
        </div>
    </div>

    <div class="setting" id="geoip_key">
        <div class="title"><?php $view->out( \OWA\Core\CoreAPI::t( 'Licence key' ) ); ?></div>
        <div class="description">
        <?php if ( $view->has_key ): ?>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'A key is set, so the database can be downloaded and refreshed.' ) ); ?>
        <?php else: ?>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( '%1$sNo key is set%2$s, so the database cannot be downloaded. The field below is where it goes.' ), '<strong>', '</strong>' ); ?>
        <?php endif; ?>
        </div>
    </div>

    <div class="setting" id="geoip_refresh">
        <div class="title"><?php $view->out( \OWA\Core\CoreAPI::t( 'Updating' ) ); ?></div>
        <div class="description">
            <?php $view->out( \OWA\Core\CoreAPI::t( 'With a key set, the scheduled job refresh-geoip-db checks MaxMind once a week and downloads a newer database when there is one.' ) ); ?><br>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'To update now, run this from the command line. A download is tens of megabytes and can take longer than a web request is allowed to:' ) ); ?><br>
            <code>php cli.php cmd=update-geoip-db</code><br>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'It checks with MaxMind first and does nothing if your copy is already current.' ) ); ?>
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

    echo \OWA\Module\Base\Classes\SettingsForm::fieldSet( $set );
}
?>

    <BR>

    <?php // This module's own save action, which returns here afterwards. ?>
    <?php echo $view->createNonceFormField('maxmind_geoip.optionsGeoipUpdate');?>

    <BUTTON class="owa-button" type="submit" name="action" value="maxmind_geoip.optionsGeoipUpdate"><?php $view->out( \OWA\Core\CoreAPI::t( 'Update Options' ) ); ?></BUTTON>
    <input type="hidden" name="module" value="maxmind_geoip">

</form>
</div>
