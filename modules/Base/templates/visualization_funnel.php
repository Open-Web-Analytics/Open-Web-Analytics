<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * A funnel: a control bar, the funnel left to right, and the numbers under it.
 *
 * Left to right, because that is what makes the drop-off legible: the bars
 * shorten as you read.
 */
$owa_steps = (array) $view->funnel;
$owa_scope = $view->get('funnel_scope');
$owa_label = $view->get('funnel_scope_label');

// The bars are scaled against the ENTRY step, not the largest, so a funnel
// always reads as a descent from 100%.
$owa_entry = 0;

foreach ( $owa_steps as $owa_s ) {

    $owa_entry = max( $owa_entry, (int) $owa_s['visitors'] );
    break;
}
?>

<div class="owa_reportControls">

    

    <?php
        /*
         * Counting scope: two radios drawn as a segmented control, and carried
         * on the URL rather than stored. A scope is a way of LOOKING at the
         * report, not a property of the site: persisting it would make one
         * link mean different things to two people.
         */
    ?>
    <span class="owa_reportControl">
        <div class="owa_scopeSwitch" id="funnelScopeSwitch" role="radiogroup" aria-labelledby="funnelScopeLabel">
            <span class="label" id="funnelScopeLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Counting:' ) ); ?></span>
            <span class="buttons owa_seg">
                <input type="radio" name="funnelscope" id="funnel-scope-visitor"<?php if ($owa_scope !== 'session'): ?> checked="checked"<?php endif; ?> />
                <label for="funnel-scope-visitor"><?php $view->out( \OWA\Core\CoreAPI::t( 'Visitors' ) ); ?></label>
                <input type="radio" name="funnelscope" id="funnel-scope-session"<?php if ($owa_scope === 'session'): ?> checked="checked"<?php endif; ?> />
                <label for="funnel-scope-session"><?php $view->out( \OWA\Core\CoreAPI::t( 'Visits' ) ); ?></label>
            </span>
        </div>
    </span>

    <?php
        /*
         * The segment: WHICH people the funnel is drawn for, as filter pills.
         * The choices come from the reporting stack (ReportSegment::options()),
         * so the funnel offers exactly the constraints every other report
         * accepts.
         */
    ?>
    <span class="owa_reportControl owa_reportFilters">
        <span class="owa_srOnly" id="funnelFiltersLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Filters' ) ); ?></span>
        <?php
            $owa_fp = [
                'pills' => 'funnelFilters', 'add' => 'funnelAddFilter', 'form' => 'funnelFilterForm',
                'dimension' => 'funnelFilterDimension', 'operator' => 'funnelFilterOperator',
                'value' => 'funnelFilterValue', 'addFilter' => 'funnelFilterAdd',
                'cancel' => 'funnelFilterCancel', 'label' => 'funnelFiltersLabel',
            ];
            include( 'filter_pills.php' );
        ?>
    </span>

    <?php
        /*
         * No edit link here.
         *
         * It is the pencil beside the title, where every custom report puts it
         * -- see Report::withEditAction(). This bar is for how you are LOOKING
         * at the funnel; changing what it IS is a different kind of act, and
         * having it in two shapes in two places said they were two things.
         */
    ?>
    <div style="clear:both;"></div>
</div>

<?php if ( $view->get('funnel_saved_filter') ): ?>
<p class="owa_funnelSavedFilter"><?php
    $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Counts only where %s.' ),
        implode( \OWA\Core\CoreAPI::t( ', and ' ), (array) $view->get('funnel_saved_filter') ) ) ); ?></p>
<?php endif; ?>

<?php if ( $view->get('funnel_segment_error') ): ?>
<?php
    /*
     * The segment was refused, so the funnel below is empty on purpose.
     * Drawing zeroes with no explanation would read as "nobody converted".
     */
?>
<div class="notice" role="status"><?php $view->out( $view->get('funnel_segment_error') ); ?></div>
<?php endif; ?>

<?php if ( $view->get('funnel_step_error') ): ?>
<?php
    /*
     * A step has no event to match, or tests a property a funnel step cannot.
     * Nothing is drawn below, on purpose: a funnel with a
     * stage quietly missing still looks like a funnel, and every percentage
     * after the gap would be a plausible number against the wrong denominator.
     */
?>
<div class="notice" role="status"><?php $view->out( $view->get('funnel_step_error') ); ?></div>
<?php endif; ?>

<?php if ( $owa_steps ):?>

<div class="owa_reportSectionContent">

    <div class="owa_funnelHeadline">
        <?php $view->out($view->completion_rate);?>
        <span class="secondaryText"><?php $view->out( \OWA\Core\CoreAPI::t( 'Completion Rate' ) );?></span>
    </div>

    <div class="owa_funnelChart">
    <?php foreach ( $owa_steps as $owa_k => $owa_step ):
        $owa_count = (int) $owa_step['visitors'];
        // Height as a share of the entry step. Floored so a step that kept
        // somebody is never an invisible sliver.
        $owa_pct   = $owa_entry > 0 ? ( $owa_count / $owa_entry ) * 100 : 0;
        $owa_h     = $owa_count > 0 ? max( 4, round( $owa_pct ) ) : 0;
        $owa_drop  = $owa_k > 0 ? (int) $owa_steps[$owa_k - 1]['visitors'] - $owa_count : 0;
    ?>
        <div class="owa_funnelStepColumn" id="step_<?php $view->out($owa_step['step_number']);?>">

            <div class="owa_funnelBarArea">
                <div class="owa_funnelBar" style="height:<?php $view->out($owa_h);?>%;" title="<?php $view->out($owa_count);?> <?php $view->out($owa_label);?>"></div>
            </div>

            <div class="owa_funnelStepNumber"><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Step %s' ), $owa_step['step_number'] ) );?></div>
            <div class="funnelStepName"><?php $view->out($owa_step['name']);?></div>
            <div class="funnelStepCount"><?php $view->out($owa_count);?> <span class="visitorCountLabel"><?php $view->out($owa_label);?></span></div>
            <div class="funnelStepPath"><?php $view->out($owa_step['summary'] ?? '');?></div>

            <?php if ( $owa_k > 0 ):?>
            <div class="owa_funnelDrop">
                <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '%s continued' ), $owa_step['visitor_percentage'] ) );?>
                <?php if ( $owa_drop > 0 ):?>
                <span class="secondaryText"><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '(%s dropped)' ), $owa_drop ) );?></span>
                <?php endif;?>
            </div>
            <?php endif;?>
        </div>
    <?php endforeach;?>
    </div>

    <div class="clear"></div>
</div>

<div class="owa_reportSectionContent">
    <div class="owa_reportSectionHeader"><h2 class="owa_reportSectionTitle"><?php $view->out( \OWA\Core\CoreAPI::t( 'Funnel Steps' ) ); ?></h2></div>
    <?php
        /*
         * The same grid every other report draws, fed the steps directly rather
         * than from a query. Its explorer controls are off: a secondary
         * dimension and a Filter both re-query the result set's own URL, and
         * these rows were computed by this report rather than fetched, so there
         * is no URL behind them and the controls would offer choices that
         * cannot do anything.
         */
    ?>
    <div id="funnel-steps-grid"></div>
</div>

<?php else:?>
<div class="owa_reportSectionContent">
    <?php $view->out( \OWA\Core\CoreAPI::t( 'This visualization has no steps yet.' ) ); ?>
    <?php $view->outMarkup( \OWA\Core\CoreAPI::t( '%1$sAdd some%2$s.' ),
        '<a href="' . $view->makeLink( [ 'do' => 'base.visualizationEdit', 'visualizationId' => $view->visualization_id ], true ) . '">', '</a>' ); ?>
</div>
<?php endif;?>

<?php
    /*
     * The steps table's rows, the segment filter and the scope switch, for
     * owa.rowsReport.js. A filter added, changed or removed reloads the
     * report, because the segment is resolved server-side -- it selects the
     * people, and the funnel is then counted over all of their pages.
     */
?>
<script type="application/json" data-ui="rows-report:config"><?php echo $view->scriptJson( [
    'grid'     => 'funnel-steps-grid',
    'item'     => 'funnelSteps',
    'table'    => $view->get( 'funnel_table' ),
    'filter'   => [ 'ids' => 'funnel', 'item' => 'funnelConstraints', 'firstPage' => false ]
        + (array) $view->get( 'funnel_filter' ),
    'reloadOn' => [
        [ 'input' => 'funnel-scope-visitor', 'params' => [ 'owa_funnelScope' => 'visitor' ] ],
        [ 'input' => 'funnel-scope-session', 'params' => [ 'owa_funnelScope' => 'session' ] ],
    ],
] ); ?></script>
