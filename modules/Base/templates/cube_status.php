<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * Every Property's reporting cube, worst first.
 *
 * The summary column is the first check that is not green, because that is the
 * one somebody opening this screen came to find. What the levels mean, and
 * every check behind them, is Classes\Cube\Status.
 */
require_once __DIR__ . '/cube_status_badge.php';
?>
<div class="panel_headline">Reporting Cubes</div>
<div id="panel">

<div class="owa_panelIntro">
    Each Property's reports read its reporting cube, which the scheduled build keeps up to date.
    <b>Action needed</b> means reports are wrong or will stop updating until someone acts;
    <b>Attention</b> means something the next scheduled build, or time, will resolve.
</div>

<?php if ( ! $view->cubes ): ?>

    <div class="owa_panelIntro">No Property has collected anything yet, so there are no cubes.</div>

<?php else: ?>

<table class="management">
    <tr>
        <th>Status</th>
        <th>Property</th>
        <th>Summary</th>
        <th style="width:1%"></th>
    </tr>
    <?php foreach ( $view->cubes as $owa_cube ): ?>
    <?php
        $owa_first = null;

        foreach ( $owa_cube['checks'] as $owa_check ) {

            if ( $owa_check['level'] !== 'green' ) {

                $owa_first = $owa_check;
                break;
            }
        }
    ?>
    <tr>
        <td><?php echo owa_cube_status_badge( $owa_cube['level'] ); ?></td>
        <td>
            <?php $view->out( $owa_cube['name'] ); ?><br>
            <code><?php $view->out( $owa_cube['table'] ); ?></code>
        </td>
        <td>
            <?php if ( $owa_first ): ?>
                <b><?php $view->out( $owa_first['label'] ); ?>:</b> <?php $view->out( $owa_first['detail'] ); ?>
            <?php else: ?>
                Every check passes.
            <?php endif; ?>
        </td>
        <td>
            <a href="<?php echo $view->makeLink( array(
                'do' => 'base.cubeStatusDetail', 'propertyId' => $owa_cube['property_id'] ) ); ?>">Details</a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<?php endif; ?>

</div>
