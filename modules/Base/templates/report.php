<?php /** @var \OWA\Core\ViewScope $view */ ?>
<script>
OWA.items['<?php echo $view->dom_id;?>'] = new OWA.report();
OWA.items['<?php echo $view->dom_id;?>'].dom_id = "<?php echo $view->dom_id;?>";
OWA.items['<?php echo $view->dom_id;?>'].page_num = "<?php $view->out( $view->getValue( 'page_num', 'pagination' ),false );?>1";
OWA.items['<?php echo $view->dom_id;?>'].max_page_num = "<?php $view->out( $view->getValue( 'max_page_num', 'pagination' ), false );?>";
OWA.items['<?php echo $view->dom_id;?>'].max_page_num = "<?php $view->out( $view->getValue( 'more_pages', 'pagination' ), false );?>";
OWA.items['<?php echo $view->dom_id;?>'].properties = <?php echo $view->makeJson($view->params);?>;

<?php if ( ! $view->get( 'hideReportingNavigation' ) ):?>
// Bind event handlers
jQuery(document).ready(function(){   
	
	
	/*
	 * Open the group holding the current report.
	 *
	 * .owa_admin_nav_subgroup is display:none in CSS, so this is the only thing
	 * that opens one -- if no link is marked current the whole nav renders
	 * collapsed on every page load, which is exactly what it did while the
	 * current-link check could not see a reportId.
	 *
	 * Scoped to the TOP MENU item: the current report's own line carries
	 * .owa_current too now, and a bare '.owa_current' would match both.
	 *
	 * show(), not toggle(): the intent is "open the group I am in", and a toggle
	 * would close it in any state where it had already been opened.
	 */
	var owaCurrentGroup = jQuery('.owa_admin_nav_topmenu_item.owa_current');

	if ( owaCurrentGroup.next('.owa_admin_nav_subgroup').length ) {

		owaCurrentGroup.next('.owa_admin_nav_subgroup').show();
		owaCurrentGroup.children('.owa_admin_nav_topmenu_toggle')
			.removeClass('fa-caret-right').addClass('fa-caret-down');
	}
	
    // report side navigaion panels - toggle
    jQuery('.owa_admin_nav_topmenu_toggle').click(function () {
	    
	    if ( jQuery(this).parent().siblings('.owa_admin_nav_subgroup').length ) { 
			 jQuery(this).parent().siblings('.owa_admin_nav_subgroup').toggle();
			 jQuery(this).toggleClass('fa-caret-right fa-caret-down');
		}
		
    });
    
});
<?php endif;?>
</script>

<div id="<?php echo $view->dom_id;?>" class="owa_reportContainer">

    <table width="100%" cellpadding="0" cellspacing="0">

        <TR>
            <?php if ( ! $view->get( 'hideReportingNavigation' ) ):?>
            <TD valign="top" class="owa_reportLeftNavColumn">
                <div>
                    <div id="owa_reportNavPanel">
                        <?php echo $view->makeNavigationMenu($view->top_level_report_nav, $view->currentSiteId, $view->params ?? array());?>
                    </div>
                </div>
            </TD>
            <?php endif;?>
            <TD valign="top" width="*">

                <?php if ( ! $view->get( 'hideSitesFilter' ) ):?>
                <div class="reportSectionContainer reportSiteFilter" style="margin-bottom:20px;">
                <?php include('filter_site.php');?>
                </div>
                <?php endif;?>
                <div class="reportSectionContainer">
                    <div id="owa_timePeriodControl" class="owa_reportPeriod" style="float:right;"></div>
                    <div id="liveViewSwitch" style="width:auto;float:right; padding-right:30px;"></div>
                    <div class="owa_reportTitle"><?php echo $view->title;?><span class="titleSuffix"><?php echo $view->get('titleSuffix');?></span></div>

                    <div class="clear"></div>
                    <?php echo $view->subview;?>

                </div>
            </TD>
        </TR>
    </table>
</div>
<script>
OWA.items['<?php echo $view->dom_id;?>'].displayTimePeriodPicker('#owa_timePeriodControl');
OWA.items['<?php echo $view->dom_id;?>'].showSiteFilter();
OWA.items['<?php echo $view->dom_id;?>'].showAutoRefreshControl({label: 'Live View:', target: '#liveViewSwitch'});
</script>
