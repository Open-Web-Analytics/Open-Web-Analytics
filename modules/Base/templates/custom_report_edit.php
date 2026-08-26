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
            <?php
                /*
                 * Enhanced by CHOSEN, the same control the grid's secondary
                 * dimension picker uses: type to filter a long list, and each
                 * thing you pick becomes a pill with its own remove.
                 *
                 * The same control rather than one of our own, because a second
                 * searchable multi-select that behaved almost the same would be
                 * the kind of difference nobody can justify later.
                 */
            ?>
            <select id="reportMetricSet" class="owa_builderChosen" multiple="multiple"></select>
            <div class="owa_builderHelp">
                The metrics this report offers as a whole, independent of any one widget.
                At most <?php $view->out( (int) $view->get('max_metrics') ); ?>, and they
                have to be measured in the same place &mdash; the list narrows as you choose.
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
        <?php
            /*
             * An id, because this button is no longer the only submit on the
             * page: the builder renders inside the report chrome now, and the
             * site filter brings a form of its own.
             */
        ?>
        <input type="submit" id="customReportSubmit" class="owa_button" value="Save report" />

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
        <?php
            /*
             * Hidden, not disabled, for a type that decides its own width. A
             * disabled control still says the choice exists and was taken away;
             * a full-width table has no width to choose, so the sentence next
             * to it says which types do.
             */
        ?>
        <div class="owa_builderField" id="dlgColspanField">
            <label for="dlgColspan">Column span</label>
            <select id="dlgColspan"></select>
            <div class="owa_builderHelp">Out of <?php echo (int) $view->get('grid_columns') ?: 12; ?>. Half the width is 6.</div>
        </div>

        <div class="owa_builderField owa_builderNote" id="dlgWidthNote" style="display:none;"></div>

        <div class="owa_builderField">
            <label for="dlgRowspan">Row span</label>
            <select id="dlgRowspan"></select>
            <div class="owa_builderHelp">How many rows tall.</div>
        </div>
    </div>

    <div class="owa_builderFieldRow">
        <div class="owa_builderField">
            <label for="dlgMetrics">Metrics</label>
            <select id="dlgMetrics" class="owa_builderChosen" multiple="multiple"></select>
        </div>

        <div class="owa_builderField">
            <label for="dlgDimensions">Dimensions</label>
            <select id="dlgDimensions" class="owa_builderChosen" multiple="multiple"></select>
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
    // metric name -> the fact tables it can be measured in. Used to narrow the
    // metric pickers so an unaskable combination cannot be assembled.
    var METRIC_ENTITIES    = <?php echo json_encode( (array) $view->get('metric_entities') ); ?>;
    var DIMENSION_ENTITIES = <?php echo json_encode( (array) $view->get('dimension_entities') ); ?>;
    var TYPES      = <?php echo json_encode( $owa_types ); ?>;
    var MAX         = <?php echo (int) $owa_max; ?>;
    var MAX_METRICS    = <?php echo (int) $view->get('max_metrics'); ?>;
    var MAX_DIMENSIONS = <?php echo (int) $view->get('max_dimensions'); ?>;

    /*
     * The types whose LAYOUT is part of what they are, so the builder does not
     * offer a choice it would only overrule. A full-width type gets no column
     * span control; a single-field type takes one metric and one dimension.
     *
     * Read from the server rather than written here -- the same lists validate
     * the definition on save, and a second copy is how the two come to
     * disagree about a type added later.
     */
    var FULL_WIDTH_TYPES   = <?php echo json_encode( array_values( (array) $view->get('full_width_types') ) ); ?>;
    var SINGLE_FIELD_TYPES = <?php echo json_encode( array_values( (array) $view->get('single_field_types') ) ); ?>;
    var DEFAULT_COLSPANS   = <?php echo json_encode( (object) (array) $view->get('default_colspans') ); ?>;

    /*
     * The grid the report is drawn on. These mirror Core\ReportGrid, which
     * clamps to the same numbers server-side -- bounding the PICKER means an
     * author is never offered a span that would be silently reduced.
     */
    var COLUMNS     = <?php echo (int) $view->get('grid_columns') ?: 12; ?>;
    var MAX_ROWSPAN = 6;

    function isFullWidth( type ) {
        return FULL_WIDTH_TYPES.indexOf( type ) !== -1;
    }

    function isSingleField( type ) {
        return SINGLE_FIELD_TYPES.indexOf( type ) !== -1;
    }

    /** The width a widget of this type gets when it names none. */
    function defaultColspan( type ) {
        return DEFAULT_COLSPANS[ type ] || COLUMNS;
    }

    /** The width a widget actually draws at. */
    function colspanOf( widget ) {

        if ( isFullWidth( widget.type ) ) {
            return COLUMNS;
        }

        return Number( widget.colspan ) || defaultColspan( widget.type );
    }

    /*
     * The caps for the type the dialog is open on.
     *
     * A card is one metric against one dimension, so its pickers stop offering
     * after the first -- rather than offering a second and refusing it on save.
     */
    function maxMetrics() {
        return isSingleField( jQuery( '#dlgType' ).val() ) ? 1 : MAX_METRICS;
    }

    function maxDimensions() {
        return isSingleField( jQuery( '#dlgType' ).val() ) ? 1 : MAX_DIMENSIONS;
    }

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

    /*
     * The type the dialog was showing before the last change.
     *
     * Kept so a type change can tell an untouched width from a chosen one: the
     * span picker follows the new type's default only when it was still sitting
     * on the old type's default. Without it, switching a new grid to a card
     * left the picker on 12 and the card saved full width -- which is the one
     * layout the type exists to prevent.
     */
    var dialogTypeWas = null;

    function newWidget( index ) {

        /*
         * A table, and no colspan.
         *
         * A grid is full width by type, and the width it draws at is
         * ReportGrid's answer rather than a number copied into every
         * definition -- so an absent colspan is the honest record of "this is
         * not the author's to choose".
         */
        return {
            type: 'grid',
            title: 'Widget ' + ( index + 1 ),
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

    /**
     * The fact tables that could answer for all of these metrics at once.
     *
     * The same reduction the query engine performs when it picks a base entity:
     * intersect the tables each metric can come from. Empty means the set
     * cannot be queried -- not that it would return few rows, but that there is
     * no single table holding them, so it is not a question.
     */
    function compatibleEntities( names ) {

        var entities = null;

        for ( var i = 0; i < names.length; i++ ) {

            var mine = METRIC_ENTITIES[ names[ i ] ] || [];

            if ( entities === null ) {
                entities = mine.slice();
                continue;
            }

            entities = entities.filter( function ( e ) { return mine.indexOf( e ) !== -1; } );

            if ( ! entities.length ) {
                return [];
            }
        }

        return entities || [];
    }

    /**
     * Narrow the DIMENSION picker the same way.
     *
     * A dimension has to be related to a fact table that can also answer the
     * chosen metrics -- `pagePath` is on the request but not the session, so
     * asking for it beside a session-only metric is as impossible as mixing
     * clicks with visits. Same reduction, one step further on.
     */
    function narrowDimensions() {

        var $select  = jQuery( '#dlgDimensions' );
        var selected = $select.val() || [];
        var metrics  = jQuery( '#dlgMetrics' ).val() || [];

        var full = selected.length >= maxDimensions();

        var allowed = DIMENSIONS.filter( function ( choice ) {

            if ( selected.indexOf( choice.name ) !== -1 ) {
                return true;
            }

            if ( full ) {
                return false;
            }

            return allowedWith( metrics, selected.concat( [ choice.name ] ) );
        } );

        fillChoices( $select, allowed, selected );

        chosenSync( '#dlgDimensions' );
    }

    /**
     * Whether these metrics and dimensions could be answered by one fact table.
     *
     * DIMENSION_ENTITIES maps a dimension to the tables it is related to, the
     * same relation ResultSetManager::isDimensionRelated() reports.
     */
    function allowedWith( metrics, dimensions ) {

        var entities = compatibleEntities( metrics );

        if ( metrics.length && ! entities.length ) {
            return false;
        }

        for ( var i = 0; i < dimensions.length; i++ ) {

            var mine = DIMENSION_ENTITIES[ dimensions[ i ] ] || [];

            if ( ! entities.length ) {
                entities = mine.slice();
                continue;
            }

            entities = entities.filter( function ( e ) { return mine.indexOf( e ) !== -1; } );

            if ( ! entities.length ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Narrow a metric picker to what can still be combined with what is chosen.
     *
     * Rebuilt rather than disabled item by item, because chosen renders its own
     * list from the select and only re-reads it on chosen:updated. An option
     * left in place but unusable would still appear in the search results.
     */
    function narrowMetrics( selector ) {

        var $select  = jQuery( selector );
        var selected = $select.val() || [];

        var full = selected.length >= ( selector === '#dlgMetrics' ? maxMetrics() : MAX_METRICS );

        var allowed = METRICS.filter( function ( choice ) {

            if ( selected.indexOf( choice.name ) !== -1 ) {
                return true;   // already chosen: never remove it under the author
            }

            // At the cap nothing more is offered, rather than offered and then
            // refused on save.
            if ( full ) {
                return false;
            }

            return compatibleEntities( selected.concat( [ choice.name ] ) ).length > 0;
        } );

        fillChoices( $select, allowed, selected );

        chosenSync( selector );
    }

    /**
     * Enhance a <select multiple> into the searchable pill control.
     *
     * CHOSEN, the same widget the grid's secondary dimension picker uses.
     *
     * The explicit width is not decoration. chosen-js 1.x sizes its container
     * from the select's offsetWidth AT ENHANCEMENT TIME, which is 0 inside a
     * display:none parent -- and the widget dialog is hidden until it is
     * opened, so without this its two pickers enhance to a couple of pixels
     * wide and are unusable. The same trap is documented on the constraint
     * builder's dimension picker, which enhances inside a hidden .builder.
     */
    function chosenify( selector ) {

        jQuery( selector ).chosen( {
            width: '100%',
            no_results_text: 'Name not found.',
            placeholder_text_multiple: 'Type to search…',
        } );
    }

    /**
     * Re-sync a chosen control to its select after setting values in code.
     *
     * chosen-js 1.x ignores a programmatic .val() until told; the event was
     * renamed from liszt:updated in 0.9.x, which is why anything written
     * against the old name silently does nothing.
     */
    function chosenSync( selector ) {

        jQuery( selector ).trigger( 'chosen:updated' );
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

            var colspan = colspanOf( widget );
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

        // No previous type: the width shown is the widget's own, whatever the
        // dialog happened to be showing for the widget before it.
        dialogTypeWas = null;

        fillRange( jQuery( '#dlgColspan' ), 1, COLUMNS, colspanOf( widget ) );
        fillRange( jQuery( '#dlgRowspan' ), 1, MAX_ROWSPAN, widget.rowspan || 1 );

        fillChoices( jQuery( '#dlgMetrics' ), METRICS, names( query.metrics ) );
        fillChoices( jQuery( '#dlgDimensions' ), DIMENSIONS, names( query.dimensions ) );

        // The options were just replaced, so chosen has to be told before it
        // will show them -- and again after the dialog is open, because that is
        // when it can finally measure itself.
        chosenSync( '#dlgMetrics' );
        chosenSync( '#dlgDimensions' );

        // After the selections are loaded, not before: applyTypeRules() trims
        // to the type's cap, and run any earlier it would be trimming the
        // widget the dialog was open on last time.
        applyTypeRules();

        jQuery( '#dlgSort' ).val( query.sort || '' );
        jQuery( '#dlgConstraints' ).val( widget.constraints || '' );

        jQuery( '#widgetDialog' )
            .dialog( 'option', 'title', widget.title || ( 'Widget ' + ( index + 1 ) ) )
            .dialog( 'open' );

        chosenSync( '#dlgMetrics' );
        chosenSync( '#dlgDimensions' );
    }

    /**
     * What the chosen TYPE decides, applied to the dialog.
     *
     * Run when the dialog opens and on every type change, because both are the
     * same event as far as the fields are concerned: the type is what says
     * whether there is a width to choose and how many fields may be picked.
     *
     * Nothing here is a substitute for the server's rules -- the definition is
     * validated on save whatever this does. This is so an author is never
     * offered something that would then be refused.
     */
    function applyTypeRules() {

        var type = jQuery( '#dlgType' ).val();

        /*
         * The width follows the type, unless the author has moved it.
         *
         * Only when the picker is still on what the OLD type defaulted to --
         * an author who deliberately set a pie to 4 and then switched it to a
         * trend keeps their 4.
         */
        if ( dialogTypeWas !== null && dialogTypeWas !== type ) {

            var $span = jQuery( '#dlgColspan' );

            if ( Number( $span.val() ) === defaultColspan( dialogTypeWas ) ) {

                $span.val( String( defaultColspan( type ) ) );
            }
        }

        dialogTypeWas = type;

        jQuery( '#dlgColspanField' ).toggle( ! isFullWidth( type ) );

        jQuery( '#dlgWidthNote' )
            .toggle( isFullWidth( type ) )
            .text( ( TYPES[ type ] || type ) + ' is always full width, so it has room for '
                 + 'its own filter and dimension controls.' );

        /*
         * A type that takes one field keeps the FIRST of whatever was already
         * picked. Trimmed here rather than at save, so the dialog shows what
         * will be stored -- a picker still displaying three metrics beside a
         * type that allows one is a screen disagreeing with itself.
         */
        if ( isSingleField( type ) ) {

            trimTo( '#dlgMetrics', 1 );
            trimTo( '#dlgDimensions', 1 );
        }

        narrowMetrics( '#dlgMetrics' );
        narrowDimensions();
    }

    /** Keep at most `limit` of a multi-select's values, in the order shown. */
    function trimTo( selector, limit ) {

        var $select = jQuery( selector );
        var values  = $select.val() || [];

        if ( values.length <= limit ) {
            return;
        }

        $select.val( values.slice( 0, limit ) );

        chosenSync( selector );
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
        if ( isFullWidth( widget.type ) ) {

            // Not the author's to choose, so nothing is recorded -- see
            // newWidget(). Deleted rather than left behind, because a widget
            // whose type was CHANGED to a full-width one would otherwise keep
            // the width it had as a card.
            delete widget.colspan;

        } else {

            widget.colspan = Number( jQuery( '#dlgColspan' ).val() ) || defaultColspan( widget.type );
        }

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

    chosenify( '#dlgMetrics' );
    chosenify( '#dlgDimensions' );

    // Choosing a metric changes what else is askable alongside it.
    jQuery( '#dlgMetrics' ).on( 'change', function () {
        narrowMetrics( '#dlgMetrics' );
        // Choosing a metric can rule dimensions out, so both are redrawn.
        narrowDimensions();
    } );
    jQuery( '#dlgDimensions' ).on( 'change', narrowDimensions );

    // The type decides the width control and the field caps, so changing it
    // has to redraw both.
    jQuery( '#dlgType' ).on( 'change', applyTypeRules );
    jQuery( '#reportMetricSet' ).on( 'change', function () { narrowMetrics( '#reportMetricSet' ); } );

    jQuery( '#widgetDialog' ).dialog( {
        autoOpen: false,
        modal: true,
        width: Math.min( 760, jQuery( window ).width() - 40 ),
        // A class on the FRAME, which jQuery UI builds outside this element --
        // the frame is what carries the titlebar and the button pane, so the
        // dialog chrome cannot be styled through #widgetDialog alone.
        dialogClass: 'owa_widgetDialogFrame',
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

    chosenify( '#reportMetricSet' );

    narrowMetrics( '#reportMetricSet' );

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
