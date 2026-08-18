<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
    // Only shown to someone who can act on it. A viewer cannot edit a crontab,
    // and a banner you are unable to do anything about is just noise. The
    // capability matches the one the scheduler commands themselves require.
    $cu = $view->getCurrentUser();

    $nag = ( $cu && $cu->isCapable( 'edit_modules' ) )
         ? \OWA\Module\Base\Classes\SchedulerHealth::problem()
         : null;
?>
<?php if ( $nag ): ?>
<div class="error owa-scheduler-nag">
    <b><?php $view->out( $nag['headline'] ); ?>!</b>
    <?php $view->out( $nag['message'] ); ?>
    <div class="owa-scheduler-nag-cron"><code><?php $view->out( \OWA\Module\Base\Classes\SchedulerHealth::cronLine() ); ?></code></div>
</div>
<?php endif; ?>
