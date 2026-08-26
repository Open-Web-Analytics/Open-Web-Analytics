<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * Goal funnel: a control bar, the funnel left to right, and the numbers under it.
 *
 * The funnel used to run DOWN the page as a three-column table -- prior pages,
 * the step, next pages -- one row per step. Left to right is how every other
 * tool draws a funnel, and it is what makes the drop-off legible: the bars
 * shorten as you read.
 *
 * The per-step "prior page / next page" grids are gone with that layout. They
 * had not worked in a long time: their queries constrained on
 * `funnel_json[step]['url']`, and a step is keyed `path` -- so every one of them
 * asked for `pagePath==undefined` and drew an empty grid.
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

<div class="owa_funnelControls">

    <span class="owa_funnelControl">
        <label for="goalChooser">Goal</label>
        <select id="goalChooser">
            <?php for ($i = 1; $i <= $view->numGoals; $i++):?>
            <option <?php if ($i == $view->goal_number): echo 'SELECTED'; endif;?> value="<?php $view->out($i, false); ?>">Goal <?php $view->out($i, false); ?></option>
            <?php endfor; ?>
        </select>
    </span>

    <?php
        /*
         * Counting scope, styled as the same two-segment switch the Live View
         * control uses, and carried on the URL rather than stored. A scope is a
         * way of LOOKING at the report, not a property of the site: persisting
         * it would make one link mean different things to two people.
         */
    ?>
    <span class="owa_funnelControl">
        <div class="autoRefreshControl" id="funnelScopeSwitch">
            <span class="label">Counting:</span>
            <span class="buttons">
                <input type="radio" name="funnelscope" id="funnel-scope-visitor"<?php if ($owa_scope !== 'session'): ?> checked="checked"<?php endif; ?> />
                <label for="funnel-scope-visitor">Visitors</label>
                <input type="radio" name="funnelscope" id="funnel-scope-session"<?php if ($owa_scope === 'session'): ?> checked="checked"<?php endif; ?> />
                <label for="funnel-scope-session">Visits</label>
            </span>
            <div style="clear:both;"></div>
        </div>
    </span>

    <?php
        /*
         * The segment: WHICH people the funnel is drawn for.
         *
         * The same constraint builder the report grids use, so the funnel
         * accepts exactly the constraints every other report does -- and offers
         * exactly the same choices, because the options come from the reporting
         * stack rather than a list written here. It keeps itself behind its own
         * toggle, which is why this is a bare container.
         */
    ?>
    <span class="owa_funnelControl">
        <span class="label">Filter:</span>
        <span id="funnelFilter" class="constraintPicker"></span>
    </span>

    <span class="owa_funnelControl owa_funnelControlRight">
        <a href="<?php echo $view->makeLink( array(
            'do'          => 'base.optionsGoalEntry',
            'goal_number' => $view->goal_number,
            'siteId'      => $view->get('siteId'),
        ) ); ?>">Edit this funnel</a>
    </span>

    <div style="clear:both;"></div>
</div>

<?php if ( $view->get('funnel_segment_error') ): ?>
<?php
    /*
     * The segment was refused, so the funnel below is empty on purpose.
     * Drawing zeroes with no explanation would read as "nobody converted".
     */
?>
<div class="notice" role="status"><?php $view->out( $view->get('funnel_segment_error') ); ?></div>
<?php endif; ?>

<?php if ( $owa_steps ):?>

<div class="owa_reportSectionContent">

    <div class="owa_funnelHeadline">
        <?php $view->out($view->goal_conversion_rate);?>
        <span class="secondaryText">conversion rate</span>
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
                <div class="owa_funnelBar" style="height:<?php $view->out($owa_h, false);?>%;" title="<?php $view->out($owa_count);?> <?php $view->out($owa_label);?>"></div>
            </div>

            <div class="owa_funnelStepNumber">Step <?php $view->out($owa_step['step_number']);?></div>
            <div class="funnelStepName"><?php $view->out($owa_step['name']);?></div>
            <div class="funnelStepCount"><?php $view->out($owa_count);?> <span class="visitorCountLabel"><?php $view->out($owa_label);?></span></div>
            <div class="funnelStepPath"><?php $view->out($owa_step['path']);?></div>

            <?php if ( $owa_k > 0 ):?>
            <div class="owa_funnelDrop">
                <?php $view->out($owa_step['visitor_percentage']);?> continued
                <?php if ( $owa_drop > 0 ):?>
                <span class="secondaryText">(<?php $view->out($owa_drop);?> dropped)</span>
                <?php endif;?>
            </div>
            <?php endif;?>
        </div>
    <?php endforeach;?>
    </div>

    <div class="clear"></div>
</div>

<div class="owa_reportSectionContent">
    <div class="owa_reportSectionHeader">Funnel Steps</div>
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
    This goal has no funnel steps configured.
    <a href="<?php echo $view->makeLink( array(
        'do'          => 'base.optionsGoalEntry',
        'goal_number' => $view->goal_number,
        'siteId'      => $view->get('siteId'),
    ) ); ?>">Add some</a>.
</div>
<?php endif;?>

<script>
(function () {

    // The goal chooser and the scope switch both reload the report, because both
    // are server-rendered state on the URL. Built from the CURRENT location so
    // every other parameter -- period, site, segment -- rides along.
    function goTo( params ) {

        var url = new URL( window.location.href );

        for ( var k in params ) {
            if ( params.hasOwnProperty( k ) ) {
                url.searchParams.set( k, params[ k ] );
            }
        }

        window.location.href = url.toString();
    }

    // The steps table: the standard grid, given data instead of a URL.
    var funnelTable = <?php echo $view->get('funnel_table_json') ?: 'null'; ?>;

    if ( funnelTable && funnelTable.resultsRows && funnelTable.resultsRows.length ) {

        OWA.items.funnelSteps = new OWA.resultSetExplorer( 'funnel-steps-grid' );
        OWA.items.funnelSteps.options.grid.showExplorerControls = false;
        OWA.items.funnelSteps.options.grid.showRowNumbers = false;
        OWA.items.funnelSteps.setResultSet( funnelTable );
        OWA.items.funnelSteps.refreshGrid();
    }

    // The segment filter. Applying one reloads the report, because the segment
    // is resolved server-side -- it selects the people, and the funnel is then
    // counted over all of their pages.
    var funnelFilter = <?php echo $view->get('funnel_filter_json') ?: 'null'; ?>;

    if ( funnelFilter ) {

        OWA.items.funnelConstraints = new OWA.constraintBuilder( '#funnelFilter', {} );
        OWA.items.funnelConstraints.setRelatedDimensions( funnelFilter.dimensions, [] );
        OWA.items.funnelConstraints.setRelatedMetrics( funnelFilter.metrics, [] );
        OWA.items.funnelConstraints.display( funnelFilter.constraints || '' );

        jQuery( '#funnelFilter' ).bind( 'constraint_change', function ( event, constraints ) {
            goTo( { 'owa_constraints': constraints } );
        } );
    }

    jQuery( '#goalChooser' ).change( function () {
        goTo( { 'owa_goalNumber': jQuery( this ).val() } );
    } );

    /*
     * Same two-segment switch the Live View control draws. checkboxradio with
     * icon:false BEFORE controlgroup(), or jQuery-UI 1.13 prepends a radio dot
     * to each label and it stops looking like a switch -- the same ordering
     * owa.report.js documents for the Live View toggle.
     */
    jQuery( '#funnelScopeSwitch > .buttons > input[type=radio]' ).checkboxradio( { icon: false } );
    jQuery( '#funnelScopeSwitch > .buttons' ).controlgroup();

    jQuery( '#funnel-scope-visitor' ).change( function () {
        goTo( { 'owa_funnelScope': 'visitor' } );
    } );

    jQuery( '#funnel-scope-session' ).change( function () {
        goTo( { 'owa_funnelScope': 'session' } );
    } );

}());
</script>
