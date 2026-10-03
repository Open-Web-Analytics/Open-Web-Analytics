<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The Tracking Tag screen: where things stand (ending with what the tracker
 * records), the settings that decide what it records, then the tag that
 * loads it. The Profile's domain and name are in the breadcrumb above.
 */
$status = (array) $view->bundle_status;
$cache  = (array) $view->bundle_cache;
$state  = (string) ( $status['state'] ?? '' );
?>
<div class="panel_headline">Tracking Tag</div>
<div id="panel">
<div class="owa-tagScreen">

<section class="owa-tagScreen__section">
    <h2>Status</h2>
    <dl class="owa-tagStatus">

        <div class="owa-tagStatus__row">
            <dt>Profile ID</dt>
            <dd><code><?php $view->out( $view->site_id ); ?></code>
                <span class="owa-tagStatus__detail">What the tag and the API identify this Profile by.</span></dd>
        </div>

        <?php if ( $view->last_event ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--ok">
            <dt>Events</dt>
            <dd>Last received <?php $view->out( \OWA\Module\Base\Classes\JobStatus::readable( $view->last_event ) ); ?>.</dd>
        </div>
        <?php else: ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--pending">
            <dt>Events</dt>
            <dd>None received yet.
                <span class="owa-tagStatus__detail">Once the tag below is on your pages and one of them is visited, the first event shows here.</span></dd>
        </div>
        <?php endif; ?>

        <?php if ( $state === 'published' ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--ok">
            <dt>Tracker</dt>
            <dd>Published <?php $view->out( date( 'Y-m-d H:i', (int) $status['published_at'] ) ); ?>.
                <span class="owa-tagStatus__detail">Served from <code><?php $view->out( $view->bundle_url ); ?></code></span></dd>
        </div>
        <?php elseif ( $state === 'unbuilt' ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--problem">
            <dt>Tracker</dt>
            <dd>Not published: the tracker has not been built on this installation.
                <span class="owa-tagStatus__detail">To be served from <code><?php $view->out( $view->bundle_url ); ?></code></span></dd>
        </div>
        <?php else: ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--pending">
            <dt>Tracker</dt>
            <dd>Waiting to publish.
                <span class="owa-tagStatus__detail">It could not be written when the settings were saved, so it is queued;
                the scheduler publishes it within a minute. If it stays here, see <code>php cli.php cmd=jobs</code>.
                To be served from <code><?php $view->out( $view->bundle_url ); ?></code></span></dd>
        </div>
        <?php endif; ?>

        <?php if ( isset( $cache['ok'] ) && $cache['ok'] === true ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--ok">
            <dt>Updates</dt>
            <dd>Changes reach visitors on their next page view.
                <span class="owa-tagStatus__detail">The tracker is served with <code>Cache-Control: <?php $view->out( $cache['cache_control'] ); ?></code></span></dd>
        </div>
        <?php elseif ( isset( $cache['ok'] ) && $cache['ok'] === false ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--problem">
            <dt>Updates</dt>
            <dd>This server sends no revalidation header for the tracker<?php if ( ! empty( $cache['cache_control'] ) ): ?> (it sends <code>Cache-Control: <?php $view->out( $cache['cache_control'] ); ?></code>)<?php endif; ?>,
                so a change can take hours to days to reach returning visitors.
                <span class="owa-tagStatus__detail">On Apache, enable <code>mod_headers</code> and <code>AllowOverride</code> for
                OWA&rsquo;s directory; elsewhere, send <code>Cache-Control: no-cache</code> for <code>public/tracker/</code>
                and <code>public/base/dist/</code>.</span></dd>
        </div>
        <?php elseif ( ! empty( $cache['checked_at'] ) ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--pending">
            <dt>Updates</dt>
            <dd>The cache header could not be checked.
                <span class="owa-tagStatus__detail">The tracker&rsquo;s URL answered
                <?php $view->out( ! empty( $cache['status'] ) ? 'HTTP ' . $cache['status'] : 'nothing' ); ?> to this server&rsquo;s own request.</span></dd>
        </div>
        <?php endif; ?>

        <?php if ( $view->tracked_events ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--ok">
            <dt>Tracking</dt>
            <dd>
                <ul class="owa-pills">
                    <?php foreach ( (array) $view->tracked_events as $event ): ?>
                    <li class="owa-pill"><?php $view->out( $event ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="owa-tagStatus__detail">As saved in the settings below.</span></dd>
        </div>
        <?php else: ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--pending">
            <dt>Tracking</dt>
            <dd>
                Nothing: every event is switched off in the settings below.</dd>
        </div>
        <?php endif; ?>
    </dl>
</section>

<section class="owa-tagScreen__section">
    <h2>Tracker Settings</h2>
    <p class="owa-tagScreen__lede">What this Profile&rsquo;s tracker records. They are built into the tracker the tag
    below loads, so a saved change reaches your pages without pasting the tag again. Each follows the
    Property&rsquo;s value until you switch its Override on.</p>

    <form method="post" name="owa_tag_settings">

        <?php
            /*
             * Every fieldset in the tracking_tag group, Base's and each active
             * module's, at Profile scope (PLAN 2.24.4), each a group closed until
             * opened. Each field shows what the Profile inherits until its
             * Override switch is on. See SettingsForm::scopedField().
             */
            foreach ( \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'tracking_tag' ) as $set ) {

                echo \OWA\Module\Base\Classes\SettingsForm::scopedFieldSet(
                    $set, 'profile', (string) $view->site_id, $view->getNs(), null, true );
            }
        ?>

        <?php echo $view->createNonceFormField( 'base.sitesEditTagSettings' ); ?>
        <input type="hidden" name="<?php echo $view->getNs(); ?>siteId" value="<?php $view->out( $view->site_id ); ?>">
        <input type="hidden" name="<?php echo $view->getNs(); ?>action" value="base.sitesEditTagSettings">
        <input type="submit" name="<?php echo $view->getNs(); ?>submit_btn" value="Save and republish" class="owa-button">
    </form>
</section>

<section class="owa-tagScreen__section">
    <h2>Add the tag to your pages</h2>
    <p class="owa-tagScreen__lede">Paste this into the <code>&lt;head&gt;</code> of every page, once. It loads this
    Profile&rsquo;s tracker with the settings above.</p>

    <?php echo $view->codeBlock( $view->bundle_tag, 'HTML' ); ?>

    <h3>Sending details from a page</h3>
    <p class="owa-tagScreen__lede">The tag works as it is. To add what the tracker cannot see for itself &mdash; the
    section of the site a page belongs to, who is signed in, a purchase &mdash; queue commands on
    <code>owa_cmds</code> before the tag. See the
    <a href="<?php echo $view->makeWikiLink( 'Javascript-Tracker' ); ?>">JavaScript tracker reference</a> for every command.</p>

    <?php echo $view->codeBlock( "<script>\n  var owa_cmds = owa_cmds || [];\n  owa_cmds.push(['setContentGroup', 'Blog']);\n</script>", 'HTML' ); ?>

    <details class="owa-tagScreen__classic">
        <summary>Classic tag format</summary>
        <p class="owa-tagScreen__lede">For configuring the tracker by hand and integrating its code into a site
        yourself. The classic tag writes this Profile&rsquo;s settings, as they are now, into the page instead of
        loading them from this server, so you can edit them in place. It does not follow changes made on this
        screen: copy it again, or edit your copy, after changing the settings.</p>
        <?php echo $view->codeBlock( $view->tracking_code, 'HTML' ); ?>
    </details>
</section>

</div>
</div>
