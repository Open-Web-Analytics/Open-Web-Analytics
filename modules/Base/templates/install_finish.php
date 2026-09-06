<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_installFinish">

    <h2 class="owa_installDone">Installation complete</h2>
    <p class="owa_publicIntro">Sign in with the account below, then copy the tracking tag
    for your Observation Profile onto your website.</p>
    <div class="owa_installCredentials">
        <div class="owa_installField">
            <label>User name</label>
            <span class="owa_installCredential"><?php $view->out( $view->u );?></span>
        </div>
        <?php
            /*
             * The password appears ONLY when OWA generated one, which happens
             * when none was given. The wizard requires one, so on this path the
             * operator already knows it -- printing it back would leave a live
             * credential in the page for nothing.
             */
        ?>
        <?php if ( $view->p ): ?>
        <div class="owa_installField">
            <label>Password</label>
            <span class="owa_installCredential"><?php $view->out( $view->p );?></span>
            <span class="owa_installHint">Generated for you. It is not shown again.</span>
        </div>
        <?php endif; ?>
    </div>
    <p>
        <a class="owa-button owa_publicSubmit"
           href="<?php echo $view->makeLink(array("action" => "base.sitesInvocation", "siteId" => $view->site_id), false, \OWA\Core\CoreAPI::getSetting('base','public_url'));?>">Log in and get the tracking tag</a>
    </p>

    <div class="status owa-install-cron">
        <b>One more step: add OWA's cron entry.</b>
        <p>
            OWA runs its scheduled maintenance from a single cron entry: keeping the
            fact tables' date partitions ahead of incoming data, and anything else you
            schedule later. Without it that maintenance never runs.
        </p>
        <p>Add this to the crontab of the user that owns your OWA files:</p>
        <p><code><?php $view->out( \OWA\Module\Base\Classes\SchedulerHealth::cronLine() ); ?></code></p>
        <p>
            Then check it with <code>php cli.php cmd=schedule-status</code>, which reports
            whether the scheduler has run. Until the entry is in place, OWA reminds you at
            the top of every admin page.
        </p>
    </div>
</div>