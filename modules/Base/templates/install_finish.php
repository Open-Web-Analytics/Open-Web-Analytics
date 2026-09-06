<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_installFinish">

    <h2 class="owa_installDone">Installation complete</h2>
    <p>Open Web Analytics has been successfully installed. Login using the user name and password below and generate a tracker.</p>
    <?php
        /*
         * The one time this password is ever shown. It is generated, not
         * chosen, so a copy of it that is easy to select matters.
         */
    ?>
    <div class="owa_installCredentials">
        <div class="owa_installField">
            <label>User name</label>
            <span class="owa_installCredential"><?php $view->out( $view->u );?></span>
        </div>
        <div class="owa_installField">
            <label>Password</label>
            <span class="owa_installCredential"><?php $view->out( $view->p );?></span>
        </div>
    </div>
    <p>
        <a class="owa-button owa_publicSubmit"
           href="<?php echo $view->makeLink(array("action" => "base.sitesInvocation", "siteId" => $view->site_id), false, \OWA\Core\CoreAPI::getSetting('base','public_url'));?>">Log in and generate a tracker</a>
    </p>

    <div class="status owa-install-cron">
        <b>One more step: add OWA's cron entry.</b>
        <p>
            OWA runs its scheduled maintenance -- keeping the fact tables' date
            partitions ahead of incoming data, and anything else you schedule later --
            from a single cron entry. Without it that maintenance never runs.
        </p>
        <p>Add this to the crontab of the user that owns your OWA files:</p>
        <p><code><?php $view->out( \OWA\Module\Base\Classes\SchedulerHealth::cronLine() ); ?></code></p>
        <p>
            Then check it took, with
            <code>php cli.php cmd=schedule-status</code> -- it will tell you in as many
            words whether the scheduler has ever run. Until the entry is in place, OWA
            will keep reminding you at the top of every admin page.
        </p>
    </div>
</div>