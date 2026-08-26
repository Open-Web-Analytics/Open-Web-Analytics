<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * The custom report builder.
 *
 * The form assembles a report DEFINITION -- the same JSON a shipped report
 * holds -- and posts it as one field. Building it client-side rather than as a
 * tree of named inputs is what keeps the definition one thing: the format is
 * nested and versioned, and a hundred bracketed field names would be a second,
 * subtly different encoding of it to keep in step.
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

    <table class="management">
        <tr>
            <td class="label_cell"><label for="customReportName">Report name</label></td>
            <td>
                <input type="text" id="customReportName" name="customReportName" size="50"
                       value="<?php $view->out( $owa_name ); ?>" />
                <div class="secondaryText">Shown on the roster, and as the report's heading.</div>
            </td>
        </tr>
        <tr>
            <td class="label_cell"><label for="reportMetricSet">Report metric set</label></td>
            <td>
                <select id="reportMetricSet" multiple="multiple" size="6" style="min-width:320px;"></select>
                <div class="secondaryText">
                    The metrics this report offers as a whole, independent of any one widget.
                </div>
            </td>
        </tr>
    </table>

    <div class="owa_reportSectionHeader">Widgets</div>

    <div id="customReportWidgets"></div>

    <div class="owa_reportSectionContent">
        <button type="button" id="addWidget" class="owa_button">Add a widget</button>
        <span class="secondaryText" id="widgetBudget"></span>
    </div>

    <div class="owa_reportSectionContent">
        <input type="submit" class="owa_button" value="Save report" />

        <?php if ( $owa_id ): ?>
        <a class="owa_button" href="<?php echo $view->makeLink( array(
            'do'             => 'base.report',
            'reportId'       => 'custom-' . $owa_id,
        ), true ); ?>">View</a>

        <?php // 5th arg = $add_nonce. base.customReportDelete is setNonceRequired(),
              // so the link has to carry one or the check refuses it. ?>
        <a href="<?php echo $view->makeLink( array(
            'do'             => 'base.customReportDelete',
            'customReportId' => $owa_id,
        ), false, '', false, true ); ?>"
           onclick="return confirm('Delete this report? There is no other copy of it.');">Delete</a>
        <?php endif; ?>
    </div>
</form>

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

    var definition = <?php echo json_encode( $owa_definition ) ?: '{}'; ?>;

    var widgets = ( definition && definition.widgets ) ? definition.widgets : [];

    // A new report starts from one empty widget: a report with no widgets
    // cannot be saved, and an empty form gives the author nothing to react to.
    if ( ! widgets.length ) {
        widgets = [ { type: 'grid', query: {} } ];
    }

    var $list = jQuery( '#customReportWidgets' );

    function optionsFor( choices, selected ) {

        selected = selected || [];

        return choices.map( function ( choice ) {

            var isSelected = selected.indexOf( choice.name ) !== -1;

            return jQuery( '<option>' )
                .attr( 'value', choice.name )
                .prop( 'selected', isSelected )
                .text( choice.label + ' (' + choice.name + ')' );
        } );
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

    function widgetRow( widget, index ) {

        var query = widget.query || {};

        var $row = jQuery( '<div class="owa_reportSectionContent owa_customWidget">' );

        $row.append( jQuery( '<div class="owa_customWidgetHeader">' )
            .append( jQuery( '<strong>' ).text( 'Widget ' + ( index + 1 ) ) )
            .append( jQuery( '<a href="#" class="removeWidget">' ).text( 'Remove' ) ) );

        var $type = jQuery( '<select class="widgetType">' );

        Object.keys( TYPES ).forEach( function ( key ) {
            $type.append( jQuery( '<option>' ).attr( 'value', key )
                .prop( 'selected', widget.type === key ).text( TYPES[ key ] ) );
        } );

        var $metrics = jQuery( '<select class="widgetMetrics" multiple size="6">' )
            .append( optionsFor( METRICS, names( query.metrics ) ) );

        var $dimensions = jQuery( '<select class="widgetDimensions" multiple size="6">' )
            .append( optionsFor( DIMENSIONS, names( query.dimensions ) ) );

        var $sort = jQuery( '<input type="text" class="widgetSort" size="30">' )
            .val( query.sort || '' );

        var $constraints = jQuery( '<input type="text" class="widgetConstraints" size="40">' )
            .val( widget.constraints || '' );

        $row.append( jQuery( '<table class="management">' )
            .append( field( 'Type', $type ) )
            .append( field( 'Metrics', $metrics ) )
            .append( field( 'Dimensions', $dimensions ) )
            .append( field( 'Sort', $sort,
                'A metric or dimension name. Add a trailing - for descending, e.g. visits-' ) )
            .append( field( 'Constraints', $constraints,
                'e.g. medium==organic-search,browserType==Chrome' ) ) );

        return $row;
    }

    function field( label, $control, help ) {

        var $cell = jQuery( '<td>' ).append( $control );

        if ( help ) {
            $cell.append( jQuery( '<div class="secondaryText">' ).text( help ) );
        }

        return jQuery( '<tr>' )
            .append( jQuery( '<td class="label_cell">' ).text( label ) )
            .append( $cell );
    }

    function draw() {

        $list.empty();

        widgets.forEach( function ( widget, i ) {
            $list.append( widgetRow( widget, i ) );
        } );

        jQuery( '#addWidget' ).prop( 'disabled', widgets.length >= MAX );

        jQuery( '#widgetBudget' ).text(
            widgets.length + ' of ' + MAX + ' widgets used' );
    }

    /** Read the form back into a definition. */
    function collect() {

        var built = { title: jQuery( '#customReportName' ).val(), widgets: [] };

        var metricSet = jQuery( '#reportMetricSet' ).val() || [];

        if ( metricSet.length ) {
            built.metrics = metricSet.join( ',' );
        }

        $list.find( '.owa_customWidget' ).each( function ( i ) {

            var $w = jQuery( this );

            var widget = {
                type: $w.find( '.widgetType' ).val(),
                // An id and a container are what the renderer addresses a
                // widget by; the author never needs to see them.
                id: 'w' + ( i + 1 ),
                container: 'w' + ( i + 1 ),
                query: {}
            };

            var metrics    = $w.find( '.widgetMetrics' ).val() || [];
            var dimensions = $w.find( '.widgetDimensions' ).val() || [];
            var sort       = jQuery.trim( $w.find( '.widgetSort' ).val() || '' );
            var cons       = jQuery.trim( $w.find( '.widgetConstraints' ).val() || '' );

            if ( metrics.length ) {
                widget.query.metrics = metrics.join( ',' );
            }

            if ( dimensions.length ) {
                widget.query.dimensions = dimensions.join( ',' );
            }

            if ( sort ) {
                widget.query.sort = sort;
            }

            if ( cons ) {
                widget.constraints = cons;
            }

            // A trend chart draws one metric; the renderer reads which from
            // chartMetric rather than guessing at the first in the list.
            if ( widget.type === 'trend' && metrics.length ) {
                widget.chartMetric = metrics[0];
            }

            built.widgets.push( widget );
        } );

        return built;
    }

    jQuery( '#reportMetricSet' ).append(
        optionsFor( METRICS, names( definition.metrics ) ) );

    jQuery( '#addWidget' ).on( 'click', function () {

        if ( widgets.length >= MAX ) {
            return;
        }

        widgets = collect().widgets;
        widgets.push( { type: 'grid', query: {} } );
        draw();
    } );

    $list.on( 'click', '.removeWidget', function ( e ) {

        e.preventDefault();

        var index = $list.find( '.owa_customWidget' ).index( jQuery( this ).closest( '.owa_customWidget' ) );

        widgets = collect().widgets;
        widgets.splice( index, 1 );

        if ( ! widgets.length ) {
            widgets = [ { type: 'grid', query: {} } ];
        }

        draw();
    } );

    // The definition is assembled at submit rather than kept in step with every
    // keystroke: one place it is built means one place it can be wrong.
    jQuery( '#customReportForm' ).on( 'submit', function () {

        jQuery( '#customReportDefinition' ).val( JSON.stringify( collect() ) );
    } );

    draw();

}());
</script>
