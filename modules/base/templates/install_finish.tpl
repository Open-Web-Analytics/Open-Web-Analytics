<div class="subview_content">

    <h1>Success! That's It. Installation is Complete.</h1>
    <p>Open Web Analytics has been successfully installed. Login using the user name and password below and generate a tracker.</p>
    <p class="form-row">
        <span class="form-label">User Name:</span>
        <span class="form-field"><?php echo $u;?></span>
    </p>
    <p class="form-row">
        <span class="form-label">Password:</span>
        <span class="form-field"><?php echo $p;?></span>
        <span class="form-instructions"></span>
    </p>
    <BR>
    <p>
        <a href="<?php echo $this->makeLink(array("action" => "base.sitesInvocation", "siteId" => $site_id), false, owa_coreAPI::getSetting('base','public_url'));?>" target="_blank">
            <span class="owa-button">Login and generate a site tracker!</span>
        </a>
    </p>
</div>