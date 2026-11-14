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
<h1 class="panel_headline"><?php $view->out( \OWA\Core\CoreAPI::t( 'Reporting Cubes' ) ); ?></h1>
<div id="panel">

<div class="owa_panelIntro">
    <?php $view->out( \OWA\Core\CoreAPI::t( 'Each Property\'s reports read its reporting cube, which the scheduled build keeps up to date.' ) ); ?>
    <?php $view->outMarkup( \OWA\Core\CoreAPI::t( '%1$s means reports are wrong or will stop updating until someone acts; %2$s means something the next scheduled build, or time, will resolve.' ),
        '<b>' . htmlspecialchars( \OWA\Core\CoreAPI::t( 'Action needed' ), ENT_QUOTES, 'UTF-8' ) . '</b>',
        '<b>' . htmlspecialchars( \OWA\Core\CoreAPI::t( 'Attention' ), ENT_QUOTES, 'UTF-8' ) . '</b>' ); ?>
</div>

<?php if ( ! $view->cubes ): ?>

    <div class="owa_panelIntro"><?php $view->out( \OWA\Core\CoreAPI::t( 'No Property has collected anything yet, so there are no cubes.' ) ); ?></div>

<?php else: ?>

<table class="management">
    <tr>
        <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Status' ) ); ?></th>
        <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Property' ) ); ?></th>
        <th><?php $view->out( \OWA\Core\CoreAPI::t( 'Summary' ) ); ?></th>
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
                <?php $view->out( \OWA\Core\CoreAPI::t( 'Every check passes.' ) ); ?>
            <?php endif; ?>
        </td>
        <td>
            <a href="<?php echo $view->makeLink( array(
                'do' => 'base.cubeStatusDetail', 'propertyId' => $owa_cube['property_id'] ) ); ?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Details' ) ); ?></a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<?php endif; ?>

</div>
