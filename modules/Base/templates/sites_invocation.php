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

<div class="owa_trackerBundle">
    <div class="inline_h2">Tracking bundle</div>
    <p>This Profile&rsquo;s tracker, configured with the settings below, is served from
    <code><?php $view->out( $view->bundle_url );?></code>.</p>
    <?php $status = (array) $view->bundle_status; $cache = (array) $view->bundle_cache; ?>
    <?php if ( ( $status['state'] ?? '' ) === 'published' ): ?>
        <p><b>Published</b> <?php $view->out( date( 'Y-m-d H:i', (int) $status['published_at'] ) );?>.</p>
    <?php elseif ( ( $status['state'] ?? '' ) === 'unbuilt' ): ?>
        <p><b>Not published:</b> the tracker has not been built on this installation.</p>
    <?php else: ?>
        <p><b>Waiting to publish.</b> It could not be written when the settings were saved, so it is queued; the scheduler publishes it within a minute. If it stays here, see <code>php cli.php cmd=jobs</code>.</p>
    <?php endif; ?>
    <?php if ( isset( $cache['ok'] ) && $cache['ok'] === true ): ?>
        <p class="owa-inherit-note">Changes reach visitors on their next page view: the bundle is served with <code>Cache-Control: <?php $view->out( $cache['cache_control'] );?></code>.</p>
    <?php elseif ( isset( $cache['ok'] ) && $cache['ok'] === false ): ?>
        <p class="owa-inherit-note"><b>This server sends no revalidation header for the tracker</b><?php if ( ! empty( $cache['cache_control'] ) ): ?> (it sends <code>Cache-Control: <?php $view->out( $cache['cache_control'] );?></code>)<?php endif; ?>,
        so a change can take hours to days to reach returning visitors. On Apache, enable <code>mod_headers</code> and
        <code>AllowOverride</code> for OWA&rsquo;s directory; elsewhere, send <code>Cache-Control: no-cache</code> for
        <code>public/tracker/</code> and <code>public/base/dist/</code>.</p>
    <?php elseif ( ! empty( $cache['checked_at'] ) ): ?>
        <p class="owa-inherit-note">The cache header could not be checked: the bundle URL answered
        <?php $view->out( ! empty( $cache['status'] ) ? 'HTTP ' . $cache['status'] : 'nothing' );?> to this server&rsquo;s own request.</p>
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
