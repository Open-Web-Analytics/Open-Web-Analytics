<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * In place of a report's widgets while its Profile's Property has no cube.
 *
 * Nothing on this page asks for data, which is the point: a cube exists only
 * after a scheduled build, and before one the report has nothing to read. The
 * links go where the next step is -- the Tracking Tag page answers "is data
 * arriving?", the Reporting Cube page answers "why is it not built?", and only
 * someone who can act on the second is offered it.
 */
$owa_r = (array) $view->readiness;
?>
<div class="owa_reportNotReady">

    <div class="owa_reportNotReadyHeadline"><?php $view->out( $owa_r['headline'] ?? '' ); ?></div>

    <p><?php $view->out( $owa_r['message'] ?? '' ); ?></p>

    <?php if ( ! empty( $owa_r['cron'] ) ): ?>
        <pre class="owa_reportNotReadyCron"><?php $view->out( $owa_r['cron'] ); ?></pre>
    <?php endif; ?>

    <p>
        <?php if ( ( $owa_r['state'] ?? '' ) !== 'no_property' ): ?>
        <a href="<?php echo $view->makeLink( array(
            'do' => 'base.sitesInvocation', 'siteId' => $view->siteId ) ); ?>">Tracking Tag</a>
        <?php endif; ?>

        <?php if ( $view->can_view_cube_status && ! empty( $owa_r['property_id'] ) ): ?>
        &middot;
        <a href="<?php echo $view->makeLink( array(
            'do' => 'base.cubeStatusDetail', 'propertyId' => $owa_r['property_id'] ) ); ?>">Reporting Cube status</a>
        <?php endif; ?>
    </p>
</div>
