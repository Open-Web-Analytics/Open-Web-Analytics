<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * Domstreams: a control bar, then the recordings in the standard grid.
 *
 * What this replaces was a hand-written <table> that drew its own header from a
 * labels object and formatted its own cells, so it shared nothing with the rest
 * of the reporting UI and drifted from it. The grid is the same control every
 * other report draws, fed rows this report computed rather than a URL.
 *
 * The Play link is built by the grid's overlayLink formatter rather than
 * assembled here: the grid renders cells, and a cell's value is data.
 */
$owa_filter_dimensions = (array) $view->get('domstreams_filter_dimensions');
$owa_filter_metrics    = (array) $view->get('domstreams_filter_metrics');
$owa_table             = (array) $view->get('domstreams');
$owa_rows              = isset( $owa_table['resultsRows'] ) ? $owa_table['resultsRows'] : [];
?>

<div class="owa_reportControls">

    <?php
        /*
         * The segment: WHICH visits the recordings are listed for, as filter
         * pills. The choices come from the reporting stack
         * (ReportSegment::options()), so this report offers exactly the
         * constraints every other one accepts.
         */
    ?>
    <span class="owa_reportControl owa_reportFilters">
        <span class="owa_srOnly" id="domstreamFiltersLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Filters' ) ); ?></span>
        <?php
            $owa_fp = [
                'pills' => 'domstreamFilters', 'add' => 'domstreamAddFilter', 'form' => 'domstreamFilterForm',
                'dimension' => 'domstreamFilterDimension', 'operator' => 'domstreamFilterOperator',
                'value' => 'domstreamFilterValue', 'addFilter' => 'domstreamFilterAdd',
                'cancel' => 'domstreamFilterCancel', 'label' => 'domstreamFiltersLabel',
            ];
            include( $view->getTemplatePath( 'base', 'filter_pills.php' ) );
        ?>
    </span>

    <span class="owa_reportControl owa_reportControlRight">
        <?php $view->out( sprintf( \OWA\Core\CoreAPI::tn( '%s recording', '%s recordings', (int) $view->get('domstreams_total') ),
            number_format( (int) $view->get('domstreams_total') ) ) );?>
    </span>

    <div style="clear:both;"></div>
</div>

<?php if ( $view->get('domstreams_segment_error') ): ?>
<?php
    /*
     * The segment was refused, so the list below is empty on purpose. An empty
     * table with no explanation reads as "nothing was recorded", which is a
     * different claim.
     */
?>
<div class="notice" role="status"><?php $view->out( $view->get('domstreams_segment_error') ); ?></div>
<?php endif; ?>

<?php if ( $owa_rows ):?>

<div class="owa_reportSectionContent">
    <div id="domstreams-grid"></div>
</div>

<?php echo $view->makePaginationFromResultSet(
    $view->get('domstreams_pagination'),
    [ 'do' => 'base.report', 'reportId' => 'domstreams' ],
    true
);?>

<?php elseif ( ! $view->get('domstreams_segment_error') ):?>
<div class="owa_reportSectionContent">
    <?php $view->out( \OWA\Core\CoreAPI::t( 'There are no recordings for this period.' ) ); ?>
</div>
<?php endif;?>

<?php
    /*
     * The recordings and the segment filter, for owa.rowsReport.js (Base).
     *
     * The segment selects the visits, and the recordings made during them are
     * then listed; a new filter goes back to the first page. The Play cell
     * carries the player's parameters as its value, and the overlayLink
     * formatter turns them into a link. The player opens in a window sized to
     * the viewport the recording was made in, because the replay positions
     * events against that geometry.
     */
?>
<script type="application/json" data-ui="rows-report:config"><?php echo $view->scriptJson( [
    'grid'        => 'domstreams-grid',
    'item'        => 'domstreams',
    'table'       => $owa_table,
    'gridOptions' => [ 'columnFormatters' => [ 'play' => 'overlayLink' ] ],
    'filter'      => [
        'ids'         => 'domstream',
        'item'        => 'domstreamConstraints',
        'firstPage'   => true,
        'dimensions'  => $owa_filter_dimensions,
        'metrics'     => $owa_filter_metrics,
        'constraints' => (string) $view->get('domstreams_constraints'),
    ],
    'sizedWindow' => [ 'link' => 'a.owa_overlayLink', 'name' => 'OWA Recording' ],
] ); ?></script>
