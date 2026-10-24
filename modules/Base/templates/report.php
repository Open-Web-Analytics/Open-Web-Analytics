<?php /** @var \OWA\Core\ViewScope $view */ ?>
<script>
OWA.items['<?php echo $view->dom_id;?>'] = new OWA.report();
OWA.items['<?php echo $view->dom_id;?>'].dom_id = "<?php echo $view->dom_id;?>";
OWA.items['<?php echo $view->dom_id;?>'].page_num = "<?php $view->out( $view->getValue( 'page_num', 'pagination' ),false );?>1";
OWA.items['<?php echo $view->dom_id;?>'].max_page_num = "<?php $view->out( $view->getValue( 'max_page_num', 'pagination' ), false );?>";
OWA.items['<?php echo $view->dom_id;?>'].max_page_num = "<?php $view->out( $view->getValue( 'more_pages', 'pagination' ), false );?>";
OWA.items['<?php echo $view->dom_id;?>'].properties = <?php echo $view->makeJson($view->params);?>;

</script>

<div id="<?php echo $view->dom_id;?>" class="owa_reportContainer">

    <table width="100%" cellpadding="0" cellspacing="0">

        <TR>
            <?php if ( ! $view->get( 'hideReportingNavigation' ) ):?>
            <TD valign="top" class="owa_reportLeftNavColumn">
                <?php
                    /*
                     * On a narrow screen this leaves the page and slides in over
                     * the report from the menu button, closing again when a
                     * report is chosen (owa.reportnav.js, owa.report.css). At
                     * full width it is the column it always was.
                     */
                ?>
                <div class="owa_reportNavRail" id="owa_reportNavRail">
                    <?php // On a narrow screen: the logo, which the header gives up to the menu button, and the close button. ?>
                    <div class="owa_reportNavRailHead">
                        <span class="owa_railLogo"><?php include( 'logo_image.php' );?></span>
                        <button type="button" class="owa_reportNavClose" aria-label="Hide the report menu">
                            <i class="fas fa-times" aria-hidden="true"></i>
                        </button>
                    </div>
                    <?php
                        /*
                         * The site control sits ABOVE the nav because it scopes
                         * it: every report in the menu below is a report of the
                         * Property named here.
                         */
                    ?>
                    <?php
                        /*
                         * The Property chooser is in the header; on a narrow
                         * screen owa.report.js moves it here, into the menu.
                         */
                    ?>
                    <div class="owa_railSiteControl"></div>
                    <?php
                        // Without a Property there is nothing for its links to be of.
                        if ( (string) $view->currentPropertyId !== '' ):
                        $owa_rail   = $view->navRail( $view->top_level_report_nav, (string) $view->currentPropertyId,
                            $view->params ?? array(), (string) $view->currentSiteId );
                        $owa_column = \OWA\Core\Template::reportColumn( $owa_rail );
                        include( 'nav_shell.php' );
                        endif;
                    ?>
                </div>
            </TD>
            <?php endif;?>
            <TD valign="top" width="*">
                <?php echo \OWA\Core\View::BANNER_SLOT; // the page's messages go here, not across the nav ?>

                <div class="reportSectionContainer">
                    <?php
                        /*
                         * Actions belonging to the SCREEN, on the title's line.
                         *
                         * Declared as data -- url, label, icon -- rather than
                         * as markup a controller echoes, so a controller cannot
                         * put arbitrary HTML into the report header.
                         *
                         * Beside the period picker, opposite the title.
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
                    <?php // The title and when its numbers are from; the period and the screen's actions opposite. ?>
                    <div class="owa_reportHead">
                    <div class="owa_reportHeadText">
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
                            aria-label="<?php $view->out( $owa_mark['label'] ); ?>"><i class="<?php
                            /*
                             * The icon names its own Font Awesome STYLE, rather
                             * than having 'fa' prefixed here. FA5 splits solid
                             * from regular -- fas fa-star is filled, far fa-star
                             * is the outline -- and a mark that wants the
                             * outline cannot say so if this hardcodes a prefix.
                             * (The FA4 spelling, fa-star-o, is defined nowhere
                             * in 5.13 and renders as an empty box.)
                             */
                            $view->out( $owa_mark['icon'] ); ?>"></i></a><?php
                        endforeach; ?></div>
                    <?php if ( ! $view->get( 'hideTimeControls' ) ):?>
                    <?php
                        /*
                         * Where Live View was. It re-polled widgets that read the
                         * cube, which moves only at a build, so it refreshed
                         * nothing; the reader needs when the numbers were built.
                         * The time arrives with the widgets' results.
                         */
                        $owa_asOf = $view->get( 'report_as_of' );
                    ?>
                    <?php if ( $owa_asOf ): ?>
                    <div id="owa_reportAsOf" class="owa_reportAsOf<?php echo $owa_asOf['warning'] ? ' owa_reportAsOfStale' : ''; ?>"
                         data-timezone="<?php $view->out( $owa_asOf['timezone'] ); ?>">
                        Data as of <span class="owa_reportAsOfTime">&hellip;</span>
                        <?php if ( $owa_asOf['warning'] ): ?>
                            &mdash; <?php $view->out( $owa_asOf['warning'] ); ?><?php if ( $owa_asOf['can_view'] ): ?>
                            (<a href="<?php echo $view->makeLink( array( 'do' => 'base.cubeStatusDetail',
                                'propertyId' => $owa_asOf['property_id'] ) ); ?>">details</a>)<?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif;?>
                    </div>
                    <div class="owa_reportHeadControls">
                    <?php if ( $owa_titleButtons ): ?>
                    <div class="owa_titleActions">
                        <?php foreach ( $owa_titleButtons as $owa_action ): ?>
                        <?php
                            /*
                             * An optional class, so an action can be HOOKED as
                             * well as followed. The visualizations roster asks
                             * which kind first, in a modal, and needs something
                             * to bind to -- while the href stays a real link,
                             * so the action still works with no JavaScript.
                             */
                        ?>
                        <a class="owa_button<?php
                            if ( ! empty( $owa_action['class'] ) ): ?> <?php
                                $view->out( $owa_action['class'] ); endif; ?>"
                           href="<?php $view->out( $owa_action['url'], false ); ?>"><?php
                            if ( ! empty( $owa_action['icon'] ) ): ?><i class="fa <?php
                                $view->out( $owa_action['icon'] ); ?> owa_titleActionIcon"></i><?php
                            endif; ?><?php $view->out( $owa_action['label'] ); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ( ! $view->get( 'hideTimeControls' ) ):?>
                    <div id="owa_timePeriodControl" class="owa_reportPeriod"></div>
                    <?php endif;?>
                    </div>
                    </div>

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
OWA.items['<?php echo $view->dom_id;?>'].showAsOf('#owa_reportAsOf');
<?php endif;?>
<?php if ( ! $view->get( 'hideSitesFilter' ) ):?>
OWA.items['<?php echo $view->dom_id;?>'].showSiteFilter();
<?php endif;?>
</script>
