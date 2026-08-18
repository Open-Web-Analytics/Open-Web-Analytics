<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="subview_content">

    <h1>Success! That's It. Installation is Complete.</h1>
    <p>Open Web Analytics has been successfully installed. Login using the user name and password below and generate a tracker.</p>
    <p class="form-row">
        <span class="form-label">User Name:</span>
        <span class="form-field"><?php echo $view->u;?></span>
    </p>
    <p class="form-row">
        <span class="form-label">Password:</span>
        <span class="form-field"><?php echo $view->p;?></span>
        <span class="form-instructions"></span>
    </p>
    <BR>
    <p>
        <a href="<?php echo $view->makeLink(array("action" => "base.sitesInvocation", "siteId" => $view->site_id), false, \OWA\Core\CoreAPI::getSetting('base','public_url'));?>" target="_blank">
            <span class="owa-button">Login and generate a site tracker!</span>
        </a>
    </p>

    <BR>

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