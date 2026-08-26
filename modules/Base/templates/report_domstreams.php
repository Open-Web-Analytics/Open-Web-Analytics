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
 * The Play link is built by a NAMED formatter rather than assembled here: the
 * grid renders cells, and a cell's value is data. See owa.resultSetExplorer.js.
 */
$owa_filter_dimensions = (array) $view->get('domstreams_filter_dimensions');
$owa_filter_metrics    = (array) $view->get('domstreams_filter_metrics');
$owa_table             = (array) $view->get('domstreams');
$owa_rows              = isset( $owa_table['resultsRows'] ) ? $owa_table['resultsRows'] : array();
?>

<?php if ( ! empty( $view->document ) ): require('item_document.php'); endif;?>

<div class="owa_reportControls">

    <?php
        /*
         * The segment: WHICH visits the recordings are listed for.
         *
         * The same constraint builder the report grids use, so this report
         * accepts exactly the constraints every other one does -- and offers
         * exactly the same choices, because the options come from the reporting
         * stack rather than a list written here. It keeps itself behind its own
         * toggle, which is why this is a bare container.
         */
    ?>
    <span class="owa_reportControl">
        <span class="label">Filter:</span>
        <span id="domstreamFilter" class="constraintPicker"></span>
    </span>

    <span class="owa_reportControl owa_reportControlRight">
        <?php $view->out( number_format( (int) $view->get('domstreams_total') ) );?>
        <?php $view->out( (int) $view->get('domstreams_total') === 1 ? 'recording' : 'recordings' );?>
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
    array( 'do' => 'base.report', 'reportId' => 'domstreams' ),
    true
);?>

<?php elseif ( ! $view->get('domstreams_segment_error') ):?>
<div class="owa_reportSectionContent">
    There are no Dom Streams this time period.
</div>
<?php endif;?>

<script>
(function () {

    // Applying a filter reloads the report, because the segment is resolved
    // server-side -- it selects the visits, and the recordings made during them
    // are then listed.
    function goTo( params ) {

        var url = new URL( window.location.href );

        for ( var k in params ) {
            if ( params.hasOwnProperty( k ) ) {
                url.searchParams.set( k, params[ k ] );
            }
        }

        // A new filter means a new first page. Keeping the old page number
        // would land the reader on page 4 of a two-page list.
        url.searchParams.delete( 'page' );

        window.location.href = url.toString();
    }

    var recordings = <?php echo json_encode( $owa_table ); ?>;

    if ( recordings && recordings.resultsRows && recordings.resultsRows.length ) {

        OWA.items.domstreams = new OWA.resultSetExplorer( 'domstreams-grid' );
        OWA.items.domstreams.options.grid.showExplorerControls = false;
        OWA.items.domstreams.options.grid.showRowNumbers = false;

        // The Play cell carries the player's parameters as its value; this is
        // what turns them into a link.
        OWA.items.domstreams.options.grid.columnFormatters = { play: 'domstreamPlayer' };

        OWA.items.domstreams.setResultSet( recordings );
        OWA.items.domstreams.refreshGrid();
    }

    var filter = {
        dimensions: <?php echo json_encode( $owa_filter_dimensions ); ?>,
        metrics: <?php echo json_encode( $owa_filter_metrics ); ?>,
        constraints: <?php echo json_encode( (string) $view->get('domstreams_constraints') ); ?>
    };

    if ( filter.dimensions ) {

        OWA.items.domstreamConstraints = new OWA.constraintBuilder( '#domstreamFilter', {} );
        OWA.items.domstreamConstraints.setRelatedDimensions( filter.dimensions, [] );
        OWA.items.domstreamConstraints.setRelatedMetrics( filter.metrics, [] );
        OWA.items.domstreamConstraints.display( filter.constraints || '' );

        jQuery( '#domstreamFilter' ).bind( 'constraint_change', function ( event, constraints ) {
            goTo( { 'owa_constraints': constraints } );
        } );
    }

    /*
     * The player opens in a window sized to the viewport the recording was made
     * in, because the replay positions events against that geometry -- an
     * ordinary tab would render the page at the reader's size and the pointer
     * would land in the wrong places.
     *
     * Delegated, so it keeps working when the grid redraws its rows.
     */
    jQuery( '#domstreams-grid' ).on( 'click', 'a.play', function ( e ) {

        e.preventDefault();

        var link   = jQuery( this );
        var height = link.data( 'height' );
        var width  = link.data( 'width' );

        window.open(
            link.attr( 'href' ),
            'OWA Dom Stream',
            'menubar=yes,location=yes,resizable=no,scrollbars=yes,status=yes'
                + ',height=' + height + ',width=' + width
        );
    } );

}());
</script>
