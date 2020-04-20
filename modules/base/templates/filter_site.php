<div id="owa_reportSiteFilter" style="line-height:30px;">
    
    <div style="float:left;">
        <span>Web Site:</span>
        <SELECT name="owa_reportSiteFilterSelect" id="owa_reportSiteFilterSelect" style="height:auto;">
        <?php  foreach ($sites as $site ):?>
            <OPTION VALUE="<?php $this->out($site->get('site_id'), false);?>" <?php if ($params['siteId'] === $site->get('site_id')):?>selected="selected" selected <?php endif; ?>><?php $this->out( $site->get('name') );?></OPTION>
        <?php endforeach;?>
        </SELECT>
    </div>
    &nbsp
    <span class="genericHorizontalList" style="font-size:12px;float:left;vertical-align:middle;">
    <ul>
        <?php if (owa_coreAPI::isCurrentUserCapable("edit_settings")):?> 
        <LI>
            <a href="<?php echo $this->makeLink( array('do' => 'base.sitesProfile', 'siteId' => $params['siteId'], 'edit' => true ) );?>">Settings</a>    
        </LI>
        <LI>
            <a href="<?php echo $this->makeLink( array('do' => 'base.optionsGoals', 'siteId' => $params['siteId'] ) );?>">Goals</a>    
        </LI>
         <?php endif;?>
    </ul>
    </span>
    <div style="clear:both;"></div>
</div>