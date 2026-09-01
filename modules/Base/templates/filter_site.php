<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="owa_reportSiteFilter" style="line-height:30px;">
    
    <div style="float:left;">
        <span>Web Site:</span>
        <SELECT name="owa_reportSiteFilterSelect" id="owa_reportSiteFilterSelect" style="height:auto;">
        <?php
        /*
         * Grouped by Property, the way the dimension picker groups dimensions.
         *
         * Two Observation Profiles of the same website legitimately share a
         * domain and differ only by an auto-assigned name, so a flat list reads
         * as "Observation Profile 1 / Observation Profile 2" with nothing to
         * say which site either belongs to. The optgroup supplies that without
         * touching the Profile's own name -- which the /v1/sites payload and
         * the WordPress plugin's picker still read unchanged.
         *
         * Falls back to the flat list if the grouped one is absent, so a
         * controller that has not been taught to group still renders a usable
         * selector rather than an empty one.
         */
        $owa_site_groups = $view->sites_by_property;

        if ( ! $owa_site_groups ) {
            $owa_site_groups = array( '' => (array) $view->sites );
        }
        ?>
        <?php foreach ( $owa_site_groups as $owa_property_label => $owa_property_sites ):?>
            <?php if ( $owa_property_label !== '' ):?><OPTGROUP label="<?php $view->out( $owa_property_label );?>"><?php endif;?>
            <?php foreach ( $owa_property_sites as $site ):?>
            <OPTION VALUE="<?php $view->out($site->get('site_id'), false);?>" <?php if ($view->params['siteId'] === $site->get('site_id')):?>selected="selected" selected <?php endif; ?>><?php $view->out( $site->get('name') );?></OPTION>
            <?php endforeach;?>
            <?php if ( $owa_property_label !== '' ):?></OPTGROUP><?php endif;?>
        <?php endforeach;?>
        </SELECT>
    </div>
    &nbsp
    <span class="genericHorizontalList" style="font-size:12px;float:left;vertical-align:middle;">
    <ul>
        <?php if (\OWA\Core\CoreAPI::isCurrentUserCapable("edit_settings")):?>
        <LI>
            <a href="<?php echo $view->makeLink( array('do' => 'base.sitesProfile', 'siteId' => $view->params['siteId'], 'edit' => true ) );?>">Settings</a>
        </LI>
        <LI>
            <a href="<?php echo $view->makeLink( array('do' => 'base.optionsGoals', 'siteId' => $view->params['siteId'] ) );?>">Goals</a>
        </LI>
         <?php endif;?>
    </ul>
    </span>
    <div style="clear:both;"></div>
</div>