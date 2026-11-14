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
<h1 class="panel_headline"><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracking Tag' ) ); ?></h1>
<div id="panel">
<div class="owa-tagScreen">

<section class="owa-tagScreen__section">
    <h2><?php $view->out( \OWA\Core\CoreAPI::t( 'Status' ) ); ?></h2>
    <dl class="owa-tagStatus">

        <div class="owa-tagStatus__row">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Profile ID' ) ); ?></dt>
            <dd><code><?php $view->out( $view->site_id ); ?></code>
                <span class="owa-tagStatus__detail"><?php $view->out( \OWA\Core\CoreAPI::t( 'What the tag and the API identify this Profile by.' ) ); ?></span></dd>
        </div>

        <?php if ( $view->last_event ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--ok">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Events' ) ); ?></dt>
            <dd><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Last received %s.' ), \OWA\Module\Base\Classes\JobStatus::readable( $view->last_event ) ) ); ?></dd>
        </div>
        <?php else: ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--pending">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Events' ) ); ?></dt>
            <dd><?php $view->out( \OWA\Core\CoreAPI::t( 'None received yet.' ) ); ?>
                <span class="owa-tagStatus__detail"><?php $view->out( \OWA\Core\CoreAPI::t( 'Once the tag below is on your pages and one of them is visited, the first event shows here.' ) ); ?></span></dd>
        </div>
        <?php endif; ?>

        <?php if ( $state === 'published' ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--ok">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracker' ) ); ?></dt>
            <dd><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Published %s.' ), date( 'Y-m-d H:i', (int) $status['published_at'] ) ) ); ?>
                <span class="owa-tagStatus__detail"><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Served from %s' ), '<code>' . htmlspecialchars( (string) $view->bundle_url, ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?></span></dd>
        </div>
        <?php elseif ( $state === 'unbuilt' ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--problem">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracker' ) ); ?></dt>
            <dd><?php $view->out( \OWA\Core\CoreAPI::t( 'Not published: the tracker has not been built on this installation.' ) ); ?>
                <span class="owa-tagStatus__detail"><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'To be served from %s' ), '<code>' . htmlspecialchars( (string) $view->bundle_url, ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?></span></dd>
        </div>
        <?php else: ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--pending">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracker' ) ); ?></dt>
            <dd><?php $view->out( \OWA\Core\CoreAPI::t( 'Waiting to publish.' ) ); ?>
                <span class="owa-tagStatus__detail"><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'It could not be written when the settings were saved, so it is queued; '
                    . 'the scheduler publishes it within a minute. If it stays here, see %1$s. To be served from %2$s' ),
                    '<code>php cli.php cmd=jobs</code>',
                    '<code>' . htmlspecialchars( (string) $view->bundle_url, ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?></span></dd>
        </div>
        <?php endif; ?>

        <?php if ( isset( $cache['ok'] ) && $cache['ok'] === true ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--ok">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Updates' ) ); ?></dt>
            <dd><?php $view->out( \OWA\Core\CoreAPI::t( 'Changes reach visitors on their next page view.' ) ); ?>
                <span class="owa-tagStatus__detail"><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'The tracker is served with %s' ), '<code>Cache-Control: ' . htmlspecialchars( (string) $cache['cache_control'], ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?></span></dd>
        </div>
        <?php elseif ( isset( $cache['ok'] ) && $cache['ok'] === false ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--problem">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Updates' ) ); ?></dt>
            <dd><?php if ( ! empty( $cache['cache_control'] ) ): ?><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'This server sends no revalidation header for the tracker (it sends %s), '
                    . 'so a change can take hours to days to reach returning visitors.' ),
                    '<code>Cache-Control: ' . htmlspecialchars( (string) $cache['cache_control'], ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?><?php else: ?><?php $view->out( \OWA\Core\CoreAPI::t( 'This server sends no revalidation header for the tracker, '
                    . 'so a change can take hours to days to reach returning visitors.' ) ); ?><?php endif; ?>
                <span class="owa-tagStatus__detail"><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'On Apache, enable %1$s and %2$s for OWA’s directory; elsewhere, send %3$s for %4$s and %5$s.' ),
                    '<code>mod_headers</code>', '<code>AllowOverride</code>', '<code>Cache-Control: no-cache</code>',
                    '<code>public/tracker/</code>', '<code>public/base/dist/</code>' ); ?></span></dd>
        </div>
        <?php elseif ( ! empty( $cache['checked_at'] ) ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--pending">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Updates' ) ); ?></dt>
            <dd><?php $view->out( \OWA\Core\CoreAPI::t( 'The cache header could not be checked.' ) ); ?>
                <span class="owa-tagStatus__detail"><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'The tracker’s URL answered %s to this server’s own request.' ),
                    ! empty( $cache['status'] ) ? sprintf( \OWA\Core\CoreAPI::t( 'HTTP %s' ), $cache['status'] ) : \OWA\Core\CoreAPI::t( 'nothing' ) ) ); ?></span></dd>
        </div>
        <?php endif; ?>

        <?php if ( $view->tracked_events ): ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--ok">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracking' ) ); ?></dt>
            <dd>
                <ul class="owa-pills">
                    <?php foreach ( (array) $view->tracked_events as $event ): ?>
                    <li class="owa-pill"><?php $view->out( $event ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="owa-tagStatus__detail"><?php $view->out( \OWA\Core\CoreAPI::t( 'As saved in the settings below.' ) ); ?></span></dd>
        </div>
        <?php else: ?>
        <div class="owa-tagStatus__row owa-tagStatus__row--pending">
            <dt><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracking' ) ); ?></dt>
            <dd>
                <?php $view->out( \OWA\Core\CoreAPI::t( 'Nothing: every event is switched off in the settings below.' ) ); ?></dd>
        </div>
        <?php endif; ?>
    </dl>
</section>

<section class="owa-tagScreen__section">
    <h2><?php $view->out( \OWA\Core\CoreAPI::t( 'Tracker Settings' ) ); ?></h2>
    <p class="owa-tagScreen__lede"><?php $view->out( \OWA\Core\CoreAPI::t( 'What this Profile’s tracker records. They are built into the tracker the tag '
    . 'below loads, so a saved change reaches your pages without pasting the tag again. Each follows the '
    . 'Property’s value until you switch its Override on.' ) ); ?></p>

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
                    $set, 'profile', (string) $view->site_id, '', null, true );
            }
        ?>

        <?php echo $view->createNonceFormField( 'base.sitesEditTagSettings' ); ?>
        <input type="hidden" name="siteId" value="<?php $view->out( $view->site_id ); ?>">
        <input type="hidden" name="action" value="base.sitesEditTagSettings">
        <input type="submit" name="submit_btn" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Save and republish' ) ); ?>" class="owa-button">
    </form>
</section>

<section class="owa-tagScreen__section">
    <h2><?php $view->out( \OWA\Core\CoreAPI::t( 'Add the tag to your pages' ) ); ?></h2>
    <p class="owa-tagScreen__lede"><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Paste this into the %s of every page, once. It loads this '
    . 'Profile’s tracker with the settings above.' ), '<code>&lt;head&gt;</code>' ); ?></p>

    <?php echo $view->codeBlock( $view->bundle_tag, 'HTML' ); ?>

    <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Sending details from a page' ) ); ?></h3>
    <p class="owa-tagScreen__lede"><?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'The tag works as it is. To send what the tracker cannot see for itself, such as the section of the site a page belongs to, who is signed in, or a purchase, queue commands on '
    . '%1$s before the tag. See the %2$s for every command.' ),
    '<code>owa_cmds</code>',
    '<a href="' . $view->makeWikiLink( 'Javascript-Tracker' ) . '">' . htmlspecialchars( \OWA\Core\CoreAPI::t( 'JavaScript tracker reference' ), ENT_QUOTES, 'UTF-8' ) . '</a>' ); ?></p>

    <?php echo $view->codeBlock( "<script>\n  var owa_cmds = owa_cmds || [];\n  owa_cmds.push(['setContentGroup', 'Blog']);\n</script>", 'HTML' ); ?>

    <details class="owa-tagScreen__classic">
        <summary><?php $view->out( \OWA\Core\CoreAPI::t( 'Classic tag format' ) ); ?></summary>
        <p class="owa-tagScreen__lede"><?php $view->out( \OWA\Core\CoreAPI::t( 'For configuring the tracker by hand and integrating its code into a site '
        . 'yourself. The classic tag writes this Profile’s settings, as they are now, into the page instead of '
        . 'loading them from this server, so you can edit them in place. It does not follow changes made on this '
        . 'screen: copy it again, or edit your copy, after changing the settings.' ) ); ?></p>
        <?php echo $view->codeBlock( $view->tracking_code, 'HTML' ); ?>
    </details>
</section>

</div>
</div>
