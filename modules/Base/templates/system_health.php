<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The installation's background work, a section each: what is true, and
 * what to run where something needs doing. Classes\SystemHealth decides
 * every level and message; this only lays them out.
 */
require_once __DIR__ . '/cube_status_badge.php';

$owa_s = (array) $view->sections;
?>
<h1 class="panel_headline"><?php $view->out( \OWA\Core\CoreAPI::t( 'System Health' ) ); ?></h1>
<div id="panel">

<div class="owa_panelIntro">
    <?php $view->out( \OWA\Core\CoreAPI::t( 'The scheduler and its jobs, the job queue, the beacon queue, the Profiles\' tracking bundles, and the installation.' ) ); ?>
    <?php $view->outMarkup( \OWA\Core\CoreAPI::t( '%1$s means something has stopped until someone acts; %2$s means something to look at that the next run, or time, may resolve.' ),
        '<b>' . htmlspecialchars( \OWA\Core\CoreAPI::t( 'Action needed' ), ENT_QUOTES, 'UTF-8' ) . '</b>',
        '<b>' . htmlspecialchars( \OWA\Core\CoreAPI::t( 'Attention' ), ENT_QUOTES, 'UTF-8' ) . '</b>' ); ?>
</div>

<?php foreach ( array( 'scheduler', 'jobs', 'queue', 'intake', 'bundles', 'data', 'install' ) as $owa_key ): ?>
<?php $owa_sec = (array) ( $owa_s[ $owa_key ] ?? array() ); if ( ! $owa_sec ) { continue; } ?>
<fieldset>
    <legend><?php $view->out( $owa_sec['title'] ); ?> <?php echo owa_cube_status_badge( $owa_sec['level'] ); ?></legend>

    <?php if ( in_array( $owa_key, array( 'data', 'install' ), true ) && ! empty( $owa_sec['facts'] ) ): ?>
    <table class="management owa_numericTable">
        <?php foreach ( $owa_sec['facts'] as $owa_label => $owa_value ): ?>
        <tr><td style="width:1%"><b><?php $view->out( $owa_label ); ?></b></td><td><?php if ( $owa_key === 'install' ): ?><code><?php $view->out( $owa_value ); ?></code><?php else: ?><?php $view->out( $owa_value ); ?><?php endif; ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <table class="management owa_numericTable">
    <?php foreach ( (array) $owa_sec['findings'] as $owa_f ): ?>
        <tr>
            <td style="width:1%"><?php echo owa_cube_status_badge( $owa_f['level'] ); ?></td>
            <td>
                <b><?php $view->out( $owa_f['label'] ); ?></b><?php if ( $owa_f['detail'] !== '' ): ?>: <?php $view->out( $owa_f['detail'] ); ?><?php endif; ?>
                <?php if ( $owa_f['command'] ): ?><br><code><?php $view->out( $owa_f['command'] ); ?></code><?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </table>

    <?php if ( $owa_key === 'jobs' && ! empty( $owa_sec['rows'] ) ): ?>
    <table class="management owa_numericTable">
        <tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Job' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Schedule' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Last run' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Outcome' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Next' ) ); ?></th></tr>
        <?php foreach ( $owa_sec['rows'] as $owa_r ): ?>
        <tr>
            <td><?php echo owa_cube_status_badge( $owa_r['level'] ); ?> <code<?php if ( $owa_r['description'] !== '' ): ?> title="<?php $view->out( $owa_r['description'] ); ?>"<?php endif; ?>><?php $view->out( $owa_r['name'] ); ?></code></td>
            <td><?php $view->out( $owa_r['schedule'] ); ?></td>
            <td><?php $view->out( $owa_r['last_run'] ); ?></td>
            <td><?php $view->out( $owa_r['outcome'] ?: '--' ); ?><?php if ( $owa_r['outcome'] !== 'ok' && $owa_r['message'] !== '' ): ?><br><?php $view->out( $owa_r['message'] ); ?><?php endif; ?></td>
            <td><?php $view->out( $owa_r['next'] ); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if ( $owa_key === 'queue' ): ?>
    <?php $owa_q = (array) $owa_sec['stats']; ?>
    <div class="owa_panelIntro">
        <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Due %1$s, delayed %2$s, running %3$s, failed %4$s, done in the last week %5$s.' ),
            (string) $owa_q['due'], (string) $owa_q['delayed'], (string) $owa_q['running'],
            (string) $owa_q['failed'], (string) $owa_q['done'] ) ); ?>
        <?php if ( $owa_q['oldest_due_age'] !== null ): ?>
            <?php $owa_wait = intdiv( (int) $owa_q['oldest_due_age'], 60 );
                  $view->out( sprintf( \OWA\Core\CoreAPI::tn( 'The oldest due job has waited %d minute.', 'The oldest due job has waited %d minutes.', $owa_wait ), $owa_wait ) ); ?>
        <?php endif; ?>
    </div>
        <?php if ( ! empty( $owa_sec['failed'] ) ): ?>
        <table class="management owa_numericTable">
            <tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Failed job' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Command' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Attempts' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Error' ) ); ?></th></tr>
            <?php foreach ( $owa_sec['failed'] as $owa_j ): ?>
            <tr>
                <td><code><?php $view->out( (string) $owa_j['id'] ); ?></code></td>
                <td><code><?php $view->out( (string) $owa_j['command'] ); ?></code></td>
                <td><?php $view->out( $owa_j['attempts'] . ' / ' . $owa_j['max_attempts'] ); ?></td>
                <td><?php $view->out( (string) $owa_j['last_error'] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ( $owa_key === 'bundles' && ! empty( $owa_sec['rows'] ) ): ?>
    <table class="management owa_numericTable">
        <tr><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Profile' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Bundle' ) ); ?></th><th><?php $view->out( \OWA\Core\CoreAPI::t( 'Published' ) ); ?></th></tr>
        <?php foreach ( $owa_sec['rows'] as $owa_b ): ?>
        <tr>
            <td><?php $view->out( $owa_b['name'] !== '' ? $owa_b['name'] : $owa_b['site_id'] ); ?><br><code><?php $view->out( $owa_b['site_id'] ); ?></code></td>
            <td><?php echo owa_cube_status_badge( $owa_b['level'] ); ?> <?php $view->out( $owa_b['state'] ); ?></td>
            <td><?php $view->out( $owa_b['published_at'] ?: '--' ); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <?php if ( $owa_key === 'intake' && isset( $owa_sec['type'] ) ): ?>
    <div class="owa_panelIntro">
        <?php if ( $owa_sec['queued'] ): ?>
        <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Beacons are queued (%s) and drained every minute.' ), '<code>' . htmlspecialchars( (string) $owa_sec['type'], ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?>
        <?php else: ?>
        <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Beacons are written as they arrive; only a failed write is queued (%s), to retry a minute later.' ), '<code>' . htmlspecialchars( (string) $owa_sec['type'], ENT_QUOTES, 'UTF-8' ) . '</code>' ); ?>
        <?php endif; ?>
        <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Waiting %1$s, dead letters %2$s.' ),
            $owa_sec['waiting'] === null ? \OWA\Core\CoreAPI::t( 'unknown' ) : (string) $owa_sec['waiting'],
            $owa_sec['dead'] === null ? \OWA\Core\CoreAPI::t( 'unknown' ) : (string) $owa_sec['dead'] ) ); ?>
    </div>
    <?php endif; ?>
</fieldset>
<?php endforeach; ?>

</div>
