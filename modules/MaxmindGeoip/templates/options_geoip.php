<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline">GeoIP Settings</div>

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

    <fieldset name="owa-geoip-options" class="options">
    <legend>MaxMind Account</legend>

    <div class="setting" id="db_license_key">
        <div class="title">Licence Key</div>
        <div class="description">
            The GeoLite2 databases are free, but MaxMind stopped allowing anonymous downloads at the
            end of 2019, so fetching one needs a key. Creating a MaxMind account and a key costs
            nothing.
            <?php if ( ! $view->has_key ): ?>
                <br><strong>No key is set</strong>, so the database cannot be downloaded.
            <?php endif; ?>
        </div>
        <div class="field">
            <input type="text" size="50"
                   name="<?php echo $view->getNs(); ?>config[maxmind_geoip.db_license_key]"
                   value="<?php echo htmlspecialchars(
                       (string) ( $view->configuration['db_license_key'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>">
        </div>
    </div>

    <div class="setting" id="db_edition">
        <div class="title">Database Edition</div>
        <div class="description">
            City resolves city, region and country. Country resolves only the country and is a
            fraction of the size, which is the better trade if your reports never go below country
            level. Changing this changes which file is downloaded and which one is read, together.
        </div>
        <div class="field">
            <select name="<?php echo $view->getNs(); ?>config[maxmind_geoip.db_edition]">
            <?php foreach ( (array) $view->editions as $edition ): ?>
                <option value="<?php echo htmlspecialchars( $edition, ENT_QUOTES, 'UTF-8' ); ?>"
                    <?php if ( $view->edition === $edition ): ?>SELECTED<?php endif; ?>>
                    <?php echo htmlspecialchars( $edition, ENT_QUOTES, 'UTF-8' ); ?>
                </option>
            <?php endforeach; ?>
            </select>
        </div>
    </div>

    </fieldset>

    <BR>

    <?php // This module's own save action, which returns here afterwards. ?>
    <?php echo $view->createNonceFormField('maxmind_geoip.optionsGeoipUpdate');?>

    <BUTTON class="owa-button" type="submit" name="<?php echo $view->getNs();?>action" value="maxmind_geoip.optionsGeoipUpdate">Update Options</BUTTON>
    <input type="hidden" name="<?php echo $view->getNs();?>module" value="maxmind_geoip">

</form>
</div>
