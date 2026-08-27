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
                    <?php if ( ! $view->get( 'hideTimeControls' ) ):?>
                    <div id="owa_timePeriodControl" class="owa_reportPeriod" style="float:right;"></div>
                    <div id="liveViewSwitch" style="width:auto;float:right; padding-right:30px;"></div>
                    <?php endif;?>
                    <?php
                        /*
                         * Actions belonging to the SCREEN, on the title's line.
                         *
                         * Declared as data -- url, label, icon -- rather than
                         * as markup a controller echoes, so a controller cannot
                         * put arbitrary HTML into the report header.
                         *
                         * Floated right like the period picker, which is what
                         * puts them on the title's line rather than under it.
                         */
                    ?>
                    <?php
                        /*
                         * An action is drawn in one of two places, and which
                         * one follows from whether it carries a label.
                         *
                         * A LABELLED action is a button, and a button says what
                         * it does, so it can sit on its own at the right of the
                         * header -- "New Custom Report" on the roster.
                         *
                         * An ICON-ONLY action cannot: a pencil floating at the
                         * far right, past the period picker and Live View, is a
                         * pencil that edits something unstated. So it goes ON
                         * the title, where the thing it acts on is the words
                         * immediately to its left. That is the whole reason
                         * for the split -- an icon needs a subject and the
                         * title is it.
                         */
                        $owa_titleActions  = (array) ( $view->get( 'title_actions' ) ?: array() );

                        $owa_titleMarks = array_values( array_filter( $owa_titleActions,
                            function ( $a ) { return ! empty( $a['iconOnly'] ); } ) );

                        $owa_titleButtons = array_values( array_filter( $owa_titleActions,
                            function ( $a ) { return empty( $a['iconOnly'] ); } ) );
                    ?>
                    <?php if ( $owa_titleButtons ): ?>
                    <div class="owa_titleActions">
                        <?php foreach ( $owa_titleButtons as $owa_action ): ?>
                        <a class="owa_button" href="<?php $view->out( $owa_action['url'], false ); ?>"><?php
                            if ( ! empty( $owa_action['icon'] ) ): ?><i class="fa <?php
                                $view->out( $owa_action['icon'] ); ?> owa_titleActionIcon"></i><?php
                            endif; ?><?php $view->out( $owa_action['label'] ); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php
                        /*
                         * A count beside the title, as a quiet pill. It says how
                         * much is on the page, which a heading alone does not,
                         * without competing with the heading for attention.
                         *
                         * ABSENT IS `false`, NOT NULL. View::get() answers false
                         * for a key nobody set, so `!== null` is not an absence
                         * check here -- it was true on every report, and out()
                         * prints false as nothing, so every report in the
                         * install grew an empty grey pill beside its heading.
                         *
                         * Zero still draws: a roster with nothing on it says so,
                         * and 0 !== false, so the two stay distinguishable.
                         */
                        $owa_titleCount = $view->get( 'title_count' );

                        $owa_hasCount = $owa_titleCount !== false
                            && $owa_titleCount !== null
                            && $owa_titleCount !== '';
                    ?>
                    <div class="owa_reportTitle"><?php echo $view->title;
                        ?><?php if ( $owa_hasCount ): ?><span
                            class="owa_titleCount"><?php $view->out( $owa_titleCount ); ?></span><?php
                        endif; ?><span class="titleSuffix"><?php echo $view->get('titleSuffix');?></span><?php
                        /*
                         * The label is not drawn, so it has to be said twice:
                         * title for a reader hovering, aria-label for one who
                         * never sees the glyph.
                         */
                        foreach ( $owa_titleMarks as $owa_mark ): ?><a
                            class="owa_titleActionMark" href="<?php $view->out( $owa_mark['url'], false ); ?>"
                            title="<?php $view->out( $owa_mark['label'] ); ?>"
                            aria-label="<?php $view->out( $owa_mark['label'] ); ?>"><i class="fa <?php
                            $view->out( $owa_mark['icon'] ); ?>"></i></a><?php
                        endforeach; ?></div>

                    <div class="clear"></div>
                    <?php echo $view->subview;?>

                </div>
            </TD>
        </TR>
    </table>
</div>
<script>
<?php if ( ! $view->get( 'hideTimeControls' ) ):?>
OWA.items['<?php echo $view->dom_id;?>'].displayTimePeriodPicker('#owa_timePeriodControl');
OWA.items['<?php echo $view->dom_id;?>'].showAutoRefreshControl({label: 'Live View:', target: '#liveViewSwitch'});
<?php endif;?>
<?php if ( ! $view->get( 'hideSitesFilter' ) ):?>
OWA.items['<?php echo $view->dom_id;?>'].showSiteFilter();
<?php endif;?>
</script>
