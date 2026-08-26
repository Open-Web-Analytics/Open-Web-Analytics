<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * The custom report builder.
 *
 * The canvas shows the report as BLOCKS, left to right, in the order they will
 * be drawn -- and each block is as wide as the column span it claims, so the
 * arrangement on this screen is the arrangement on the report. A plus at the
 * end adds another; each block's Edit opens a modal holding everything about
 * that one widget.
 *
 * WHERE THE STATE LIVES
 *
 * In the `widgets` array, which the canvas is a rendering OF. The first version
 * kept it in the form controls and read it back out on every change, so every
 * add and remove had to round-trip through the DOM and the DOM was the only
 * record of what had been configured. Here the array is the record and the
 * canvas is drawn from it, so a redraw cannot lose anything.
 *
 * The definition is still posted as ONE field. The format is nested; a tree of
 * bracketed field names would be a second, subtly different encoding of it to
 * keep in step.
 *
 * Nothing here is trusted. Every name the author picks is validated against the
 * registry server-side before the report is stored, and again when it renders.
 */
$owa_id         = (string) $view->get('custom_report_id');
$owa_name       = (string) $view->get('custom_report_name');
$owa_definition = (array) $view->get('custom_report_definition');
$owa_error      = (string) $view->get('custom_report_error');
$owa_types      = (array) $view->get('widget_types');
$owa_max        = (int) $view->get('max_widgets');
?>

<?php if ( $owa_error ): ?>
<div class="notice error" role="alert"><?php $view->out( $owa_error ); ?></div>
<?php endif; ?>

<form id="customReportForm" action="<?php echo $view->makeLink( array( 'do' => 'base.customReportSave' ) ); ?>" method="post">

    <?php echo $view->createNonceFormField( 'base.customReportSave' ); ?>

    <input type="hidden" name="customReportId" value="<?php $view->out( $owa_id ); ?>" />

    <?php
        /*
         * The site travels through the POST so the saved report's URL names
         * one. Without it the author lands on -- and shares -- a link that only
         * an admin can open: view_reports is only satisfied against a site.
         */
    ?>
    <input type="hidden" name="siteId" value="<?php $view->out( $view->get('siteId') ); ?>" />

    <?php /* The assembled definition. Written by the script below on submit. */ ?>
    <input type="hidden" name="customReportDefinition" id="customReportDefinition" value="" />

    <div class="owa_builderHeader">
        <div class="owa_builderField">
            <label for="customReportName">Report name</label>
            <input type="text" id="customReportName" name="customReportName"
                   placeholder="Untitled report"
                   value="<?php $view->out( $owa_name ); ?>" />
        </div>

        <div class="owa_builderField">
            <label for="reportMetricSet">Report metric set</label>
            <select id="reportMetricSet" multiple="multiple" size="4"></select>
            <div class="owa_builderHelp">
                The metrics this report offers as a whole, independent of any one widget.
            </div>
        </div>
    </div>

    <div class="owa_builderSectionHeader">
        <span>Widgets</span>
        <span class="owa_builderBudget" id="widgetBudget"></span>
    </div>

    <?php
        /*
         * The canvas. Blocks are laid out left to right in the order the report
         * draws them, each as wide as the column span it claims -- the point
         * being that the layout is legible here rather than only after saving.
         */
    ?>
    <div id="customReportCanvas" class="owa_builderCanvas"></div>

    <div class="owa_builderActions">
        <input type="submit" class="owa_button" value="Save report" />

        <?php if ( $owa_id ): ?>
        <a class="owa_button owa_buttonQuiet" href="<?php echo $view->makeLink( array(
            'do'       => 'base.report',
            'reportId' => 'custom-' . $owa_id,
        ), true ); ?>">View</a>

        <?php // 5th arg = $add_nonce. base.customReportDelete is setNonceRequired(),
              // so the link has to carry one or the check refuses it. ?>
        <a class="owa_builderDelete" href="<?php echo $view->makeLink( array(
            'do'             => 'base.customReportDelete',
            'customReportId' => $owa_id,
        ), false, '', false, true ); ?>"
           onclick="return confirm('Delete this report? There is no other copy of it.');">Delete</a>
        <?php endif; ?>
    </div>
</form>

<?php /* The modal body. Hidden here; jQuery UI lifts it into a dialog. */ ?>
<div id="widgetDialog" class="owa_widgetDialog" style="display:none;">

    <div class="owa_builderField">
        <label for="dlgTitle">Widget name</label>
        <input type="text" id="dlgTitle" />
    </div>

    <div class="owa_builderField">
        <label for="dlgType">Type</label>
        <select id="dlgType"></select>
    </div>

    <div class="owa_builderFieldRow">
        <div class="owa_builderField">
            <label for="dlgColspan">Column span</label>
            <select id="dlgColspan"></select>
            <div class="owa_builderHelp">Out of 12. Half the width is 6.</div>
        </div>

        <div class="owa_builderField">
            <label for="dlgRowspan">Row span</label>
            <select id="dlgRowspan"></select>
            <div class="owa_builderHelp">How many rows tall.</div>
        </div>
    </div>

    <div class="owa_builderFieldRow">
        <div class="owa_builderField">
            <label for="dlgMetrics">Metrics</label>
            <select id="dlgMetrics" multiple="multiple" size="8"></select>
        </div>

        <div class="owa_builderField">
            <label for="dlgDimensions">Dimensions</label>
            <select id="dlgDimensions" multiple="multiple" size="8"></select>
        </div>
    </div>

    <div class="owa_builderField">
        <label for="dlgSort">Sort</label>
        <input type="text" id="dlgSort" placeholder="visits-" />
        <div class="owa_builderHelp">
            A metric or dimension name. Add a trailing <code>-</code> for descending.
        </div>
    </div>

    <div class="owa_builderField">
        <label for="dlgConstraints">Constraints</label>
        <input type="text" id="dlgConstraints" placeholder="medium==organic-search" />
        <div class="owa_builderHelp">
            Comma-separated, e.g. <code>medium==organic-search,browserType==Chrome</code>
        </div>
    </div>
</div>

<script>
(function () {

    // Everything the author may choose, read from the reporting registry rather
    // than written here -- a list of our own would eventually offer a name the
    // validator refuses, and a typo would be indistinguishable from a name that
    // was never real.
    var METRICS    = <?php echo json_encode( (array) $view->get('metric_choices') ); ?>;
    var DIMENSIONS = <?php echo json_encode( (array) $view->get('dimension_choices') ); ?>;
    var TYPES      = <?php echo json_encode( $owa_types ); ?>;
    var MAX        = <?php echo (int) $owa_max; ?>;

    /*
     * The grid the report is drawn on. These mirror Core\ReportGrid, which
     * clamps to the same numbers server-side -- bounding the PICKER means an
     * author is never offered a span that would be silently reduced.
     */
    var COLUMNS     = 12;
    var MAX_ROWSPAN = 6;

    var definition = <?php echo json_encode( $owa_definition ) ?: '{}'; ?>;

    /*
     * The state. The canvas is a rendering of this array, not the reverse: a
     * redraw reads from here and the dialog writes to here.
     */
    var widgets = ( definition && definition.widgets ) ? definition.widgets.slice() : [];

    // A new report starts from one block. A report with no widgets cannot be
    // saved, and an empty canvas gives the author nothing to press.
    if ( ! widgets.length ) {
        widgets = [ newWidget( 0 ) ];
    }

    var editing = null;   // index of the widget the dialog is open on

    function newWidget( index ) {

        return {
            type: 'grid',
            title: 'Widget ' + ( index + 1 ),
            colspan: 6,
            rowspan: 1,
            query: {}
        };
    }

    /** A comma string from the definition, as an array of names. */
    function names( value ) {

        if ( ! value ) {
            return [];
        }

        return ( Array.isArray( value ) ? value : String( value ).split( ',' ) )
            .map( function ( n ) { return String( n ).trim(); } )
            .filter( Boolean );
    }

    function fillChoices( $select, choices, selected ) {

        selected = selected || [];

        $select.empty();

        choices.forEach( function ( choice ) {
            $select.append( jQuery( '<option>' )
                .attr( 'value', choice.name )
                .prop( 'selected', selected.indexOf( choice.name ) !== -1 )
                .text( choice.label + ' (' + choice.name + ')' ) );
        } );
    }

    function fillRange( $select, from, to, selected ) {

        $select.empty();

        for ( var i = from; i <= to; i++ ) {
            $select.append( jQuery( '<option>' ).attr( 'value', i )
                .prop( 'selected', Number( selected ) === i ).text( i ) );
        }
    }

    // ------------------------------------------------------------------
    // The canvas
    // ------------------------------------------------------------------

    function draw() {

        var $canvas = jQuery( '#customReportCanvas' ).empty();

        widgets.forEach( function ( widget, i ) {

            var colspan = Number( widget.colspan ) || COLUMNS;
            var rowspan = Number( widget.rowspan ) || 1;

            var $block = jQuery( '<div class="owa_builderBlock">' )
                .attr( 'data-index', i )
                // As wide as the span it claims, so the canvas reads as the
                // layout rather than as a list.
                .addClass( 'owa_builderSpan-' + colspan );

            $block.append( jQuery( '<div class="owa_builderBlockHead">' )
                .append( jQuery( '<span class="owa_builderBlockName">' )
                    .text( widget.title || ( 'Widget ' + ( i + 1 ) ) ) )
                .append( jQuery( '<a href="#" class="owa_builderRemove" title="Remove this widget">' )
                    .text( '×' ) ) );

            $block.append( jQuery( '<div class="owa_builderBlockMeta">' )
                .append( jQuery( '<span class="owa_builderBlockType">' )
                    .text( TYPES[ widget.type ] || widget.type ) )
                .append( jQuery( '<span class="owa_builderBlockSpan">' )
                    .text( colspan + ' × ' + rowspan ) ) );

            var summary = names( widget.query && widget.query.metrics )
                .concat( names( widget.query && widget.query.dimensions ) );

            $block.append( jQuery( '<div class="owa_builderBlockSummary">' )
                .text( summary.length ? summary.join( ', ' ) : 'Nothing configured yet' ) );

            $block.append( jQuery( '<a href="#" class="owa_builderEdit">' ).text( 'Edit' ) );

            $canvas.append( $block );
        } );

        if ( widgets.length < MAX ) {

            $canvas.append(
                jQuery( '<button type="button" id="addWidget" class="owa_builderAdd" title="Add a widget">' )
                    .append( jQuery( '<span class="owa_builderAddPlus">' ).text( '+' ) )
                    .append( jQuery( '<span>' ).text( 'Add widget' ) ) );
        }

        jQuery( '#widgetBudget' ).text( widgets.length + ' of ' + MAX + ' widgets' );
    }

    // ------------------------------------------------------------------
    // The dialog
    // ------------------------------------------------------------------

    function openDialog( index ) {

        editing = index;

        var widget = widgets[ index ];
        var query  = widget.query || {};

        jQuery( '#dlgTitle' ).val( widget.title || ( 'Widget ' + ( index + 1 ) ) );

        var $type = jQuery( '#dlgType' ).empty();

        Object.keys( TYPES ).forEach( function ( key ) {
            $type.append( jQuery( '<option>' ).attr( 'value', key )
                .prop( 'selected', widget.type === key ).text( TYPES[ key ] ) );
        } );

        fillRange( jQuery( '#dlgColspan' ), 1, COLUMNS, widget.colspan || COLUMNS );
        fillRange( jQuery( '#dlgRowspan' ), 1, MAX_ROWSPAN, widget.rowspan || 1 );

        fillChoices( jQuery( '#dlgMetrics' ), METRICS, names( query.metrics ) );
        fillChoices( jQuery( '#dlgDimensions' ), DIMENSIONS, names( query.dimensions ) );

        jQuery( '#dlgSort' ).val( query.sort || '' );
        jQuery( '#dlgConstraints' ).val( widget.constraints || '' );

        jQuery( '#widgetDialog' )
            .dialog( 'option', 'title', widget.title || ( 'Widget ' + ( index + 1 ) ) )
            .dialog( 'open' );
    }

    /** Read the dialog back into the widget it was opened on. */
    function applyDialog() {

        if ( editing === null ) {
            return;
        }

        var widget = widgets[ editing ];
        var query  = {};

        widget.title   = jQuery( '#dlgTitle' ).val() || ( 'Widget ' + ( editing + 1 ) );
        widget.type    = jQuery( '#dlgType' ).val();
        widget.colspan = Number( jQuery( '#dlgColspan' ).val() ) || COLUMNS;
        widget.rowspan = Number( jQuery( '#dlgRowspan' ).val() ) || 1;

        var metrics    = jQuery( '#dlgMetrics' ).val() || [];
        var dimensions = jQuery( '#dlgDimensions' ).val() || [];
        var sort       = jQuery.trim( jQuery( '#dlgSort' ).val() || '' );
        var cons       = jQuery.trim( jQuery( '#dlgConstraints' ).val() || '' );

        if ( metrics.length ) {
            query.metrics = metrics.join( ',' );
        }

        if ( dimensions.length ) {
            query.dimensions = dimensions.join( ',' );
        }

        if ( sort ) {
            query.sort = sort;
        }

        widget.query = query;

        if ( cons ) {
            widget.constraints = cons;
        } else {
            delete widget.constraints;
        }

        // A trend chart draws ONE metric, and the renderer reads which from
        // chartMetric rather than guessing at the first in the list.
        if ( widget.type === 'trend' && metrics.length ) {
            widget.chartMetric = metrics[0];
        } else {
            delete widget.chartMetric;
        }

        editing = null;

        draw();
    }

    jQuery( '#widgetDialog' ).dialog( {
        autoOpen: false,
        modal: true,
        width: Math.min( 760, jQuery( window ).width() - 40 ),
        buttons: [
            { text: 'Done', click: function () { applyDialog(); jQuery( this ).dialog( 'close' ); } },
            { text: 'Cancel', click: function () { editing = null; jQuery( this ).dialog( 'close' ); } }
        ]
    } );

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------

    jQuery( '#customReportCanvas' )
        .on( 'click', '.owa_builderEdit', function ( e ) {
            e.preventDefault();
            openDialog( Number( jQuery( this ).closest( '.owa_builderBlock' ).attr( 'data-index' ) ) );
        } )
        .on( 'click', '.owa_builderRemove', function ( e ) {
            e.preventDefault();

            var index = Number( jQuery( this ).closest( '.owa_builderBlock' ).attr( 'data-index' ) );

            widgets.splice( index, 1 );

            // A report with no widgets cannot be saved, so removing the last
            // one leaves a fresh block rather than an empty canvas.
            if ( ! widgets.length ) {
                widgets = [ newWidget( 0 ) ];
            }

            draw();
        } )
        .on( 'click', '#addWidget', function ( e ) {
            e.preventDefault();

            if ( widgets.length >= MAX ) {
                return;
            }

            widgets.push( newWidget( widgets.length ) );
            draw();
        } );

    fillChoices( jQuery( '#reportMetricSet' ), METRICS, names( definition.metrics ) );

    // The definition is assembled at submit rather than kept in step with every
    // keystroke: one place it is built means one place it can be wrong.
    jQuery( '#customReportForm' ).on( 'submit', function () {

        var built = { title: jQuery( '#customReportName' ).val(), widgets: [] };

        var metricSet = jQuery( '#reportMetricSet' ).val() || [];

        if ( metricSet.length ) {
            built.metrics = metricSet.join( ',' );
        }

        widgets.forEach( function ( widget, i ) {

            // An id and a container are what the renderer addresses a widget
            // by; the author never needs to see them.
            built.widgets.push( jQuery.extend( {}, widget, {
                id: 'w' + ( i + 1 ),
                container: 'w' + ( i + 1 )
            } ) );
        } );

        jQuery( '#customReportDefinition' ).val( JSON.stringify( built ) );
    } );

    draw();

}());
</script>
