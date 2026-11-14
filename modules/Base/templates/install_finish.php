<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_installFinish">

    <h2 class="owa_installDone"><?php $view->out( \OWA\Core\CoreAPI::t( 'Installation complete' ) );?></h2>
    <p class="owa_publicIntro"><?php $view->out( \OWA\Core\CoreAPI::t( 'Sign in with the account below, then copy the tracking tag for your Observation Profile onto your website.' ) );?></p>
    <div class="owa_installCredentials">
        <div class="owa_installField">
            <label><?php $view->out( \OWA\Core\CoreAPI::t( 'User name' ) );?></label>
            <span class="owa_installCredential"><?php $view->out( $view->u );?></span>
        </div>
    </div>
    <p>
        <a class="owa-button owa_publicSubmit"
           href="<?php echo $view->makeLink(array("action" => "base.sitesInvocation", "siteId" => $view->site_id), false, \OWA\Core\CoreAPI::getSetting('base','public_url'));?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Log in and get the tracking tag' ) );?></a>
    </p>

    <div class="status owa-install-cron">
        <b><?php $view->out( \OWA\Core\CoreAPI::t( 'One more step: add OWA\'s cron entry.' ) );?></b>
        <p>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'OWA builds its reports and runs its maintenance from a single cron entry: building each Property\'s reporting cube from new events every few minutes, and keeping the date partitions ahead of incoming data. Without it, events are recorded but no report shows them.' ) );?>
        </p>
        <p><?php $view->out( \OWA\Core\CoreAPI::t( 'Add this to the crontab of the user that owns your OWA files:' ) );?></p>
        <p><code><?php $view->out( \OWA\Module\Base\Classes\SchedulerHealth::cronLine() ); ?></code></p>
        <p>
            <?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Then check it with %s, which reports whether the scheduler has run. Until the entry is in place, OWA reminds you at the top of every admin page.' ), '<code>php cli.php cmd=schedule-status</code>' );?>
        </p>
    </div>
</div>