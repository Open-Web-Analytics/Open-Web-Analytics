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
<div class="panel_headline">System Health</div>
<div id="panel">

<div class="owa_panelIntro">
    OWA's background work: the scheduler and its jobs, the queue of one-off jobs, and the queue
    incoming beacons go through. <b>Action needed</b> means something has stopped until someone acts;
    <b>Attention</b> means something worth a look that the next run, or time, may resolve.
</div>

<?php foreach ( array( 'scheduler', 'jobs', 'queue', 'intake' ) as $owa_key ): ?>
<?php $owa_sec = (array) ( $owa_s[ $owa_key ] ?? array() ); if ( ! $owa_sec ) { continue; } ?>
<fieldset>
    <legend><?php $view->out( $owa_sec['title'] ); ?> <?php echo owa_cube_status_badge( $owa_sec['level'] ); ?></legend>

    <table class="management">
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
    <table class="management">
        <tr><th>Job</th><th>Schedule</th><th>Last run</th><th>Outcome</th><th>Next</th></tr>
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
        Due <?php $view->out( (string) $owa_q['due'] ); ?>,
        delayed <?php $view->out( (string) $owa_q['delayed'] ); ?>,
        running <?php $view->out( (string) $owa_q['running'] ); ?>,
        failed <?php $view->out( (string) $owa_q['failed'] ); ?>,
        done in the last week <?php $view->out( (string) $owa_q['done'] ); ?>.
        <?php if ( $owa_q['oldest_due_age'] !== null ): ?>
            The oldest due job has waited <?php $view->out( (string) intdiv( (int) $owa_q['oldest_due_age'], 60 ) ); ?> minute(s).
        <?php endif; ?>
    </div>
        <?php if ( ! empty( $owa_sec['failed'] ) ): ?>
        <table class="management">
            <tr><th>Failed job</th><th>Command</th><th>Attempts</th><th>Error</th></tr>
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

    <?php if ( $owa_key === 'intake' && isset( $owa_sec['type'] ) ): ?>
    <div class="owa_panelIntro">
        Queue type <code><?php $view->out( $owa_sec['type'] ); ?></code>;
        beacons are <?php $view->out( $owa_sec['queued'] ? 'queued and drained every minute' : 'ingested as they arrive' ); ?>.
        Waiting <?php $view->out( $owa_sec['waiting'] === null ? 'unknown' : (string) $owa_sec['waiting'] ); ?>,
        dead letters <?php $view->out( $owa_sec['dead'] === null ? 'unknown' : (string) $owa_sec['dead'] ); ?>.
    </div>
    <?php endif; ?>
</fieldset>
<?php endforeach; ?>

</div>
