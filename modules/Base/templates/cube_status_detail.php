<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * One Property's reporting cube.
 *
 * The day table is what a gap looks like: raw above zero with the cube at zero,
 * or the two disagreeing on a settled day. A day that is not settled yet is
 * rebuilt by the next scheduled build, so a difference there is expected.
 */
require_once __DIR__ . '/cube_status_badge.php';

$owa_c = (array) $view->cube;
?>
<div class="panel_headline">Reporting Cube</div>
<div id="panel">

<div class="owa_panelIntro">
    <a href="<?php echo $view->makeLink( array( 'do' => 'base.cubeStatus' ) ); ?>">&larr; All reporting cubes</a>
</div>

<?php if ( ! $owa_c ): ?>

    <div class="owa_panelIntro">No such Property.</div>

<?php else: ?>

<fieldset>
    <legend><?php $view->out( $owa_c['name'] ); ?> <?php echo owa_cube_status_badge( $owa_c['level'] ); ?></legend>

    <div class="owa_panelIntro"><code><?php $view->out( $owa_c['table'] ); ?></code></div>

    <table class="management">
        <tr><th>Status</th><th>Check</th><th>Detail</th></tr>
        <?php foreach ( $owa_c['checks'] as $owa_check ): ?>
        <tr>
            <td><?php echo owa_cube_status_badge( $owa_check['level'] ); ?></td>
            <td><?php $view->out( $owa_check['label'] ); ?></td>
            <td><?php $view->out( $owa_check['detail'] ); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</fieldset>

<?php if ( $owa_c['exists'] ): ?>

<fieldset>
    <legend>Last <?php echo count( $owa_c['days'] ); ?> days</legend>

    <table class="management">
        <tr>
            <th>Day</th>
            <th>Raw rows</th>
            <th>Cube rows</th>
            <th>Partition</th>
            <th>Last built</th>
            <th>Settled</th>
        </tr>
        <?php foreach ( $owa_c['days'] as $owa_day ): ?>
        <tr>
            <td><?php $view->out( \OWA\Module\Base\Classes\Cube\Status::day( $owa_day['day'] ) ); ?></td>
            <td><?php echo number_format( $owa_day['raw'] ); ?></td>
            <td><?php echo number_format( $owa_day['cube'] ); ?>
                <?php if ( $owa_day['settled'] && $owa_day['raw'] !== $owa_day['cube'] ): ?>
                    <span class="owa_cubeStatusMismatch">differs from raw</span>
                <?php endif; ?>
            </td>
            <td><code><?php $view->out( $owa_day['partition'] ); ?></code></td>
            <td><?php $view->out( $owa_day['built_at']
                ? \OWA\Module\Base\Classes\JobStatus::readable( intdiv( (int) $owa_day['built_at'], 1000000 ) )
                : 'never' ); ?></td>
            <td><?php
                /* A day with nothing on either side has nothing to settle. */
                if ( ! $owa_day['raw'] && ! $owa_day['cube'] && ! $owa_day['built_at'] ) {
                    echo 'No data';
                } else {
                    echo $owa_day['settled'] ? 'Yes' : 'Not yet';
                }
            ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</fieldset>

<fieldset>
    <legend>Partitions</legend>
    <?php $owa_p = $owa_c['partitions']; ?>
    <table class="management">
        <tr><td>Partitions</td><td><?php echo (int) $owa_p['count']; ?></td></tr>
        <tr><td>Reaches back to</td><td><?php $view->out( $owa_p['first']
            ? \OWA\Module\Base\Classes\Cube\Status::day( $owa_p['first'] ) : 'none' ); ?></td></tr>
        <tr><td>Daily through</td><td><?php $view->out( $owa_p['daily_through']
            ? \OWA\Module\Base\Classes\Cube\Status::day( $owa_p['daily_through'] ) : 'no daily partitions ahead' ); ?></td></tr>
        <tr><td>Dated partitions end</td><td><?php $view->out( $owa_p['lead_end']
            ? \OWA\Module\Base\Classes\Cube\Status::day( $owa_p['lead_end'] ) : 'none' ); ?></td></tr>
        <tr><td>Rows in the catch-all</td><td><?php echo number_format( (int) $owa_p['catch_all_rows'] ); ?></td></tr>
    </table>
</fieldset>

<?php endif; ?>

<fieldset>
    <legend>Commands</legend>
    <div class="owa_panelIntro">A build cannot run from this screen; it is longer than any request may take.
        From the OWA directory:</div>
    <pre class="owa_cubeStatusCommands">php cli.php cmd=schedule-status
php cli.php cmd=cube-rebuild property=<?php $view->out( $owa_c['property_id'] ); ?>

php cli.php cmd=cube-rebuild property=<?php $view->out( $owa_c['property_id'] ); ?> from=yyyymmdd</pre>
</fieldset>

<?php endif; ?>

</div>
