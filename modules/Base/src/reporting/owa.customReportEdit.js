import jQuery from 'jquery';
import { OWA } from './owa.format.js';

/**
 * The custom report editor (custom_report_edit.php).
 *
 * The layout shows the report as CARDS on its twelve columns, in the order
 * they will be drawn, each as wide as it will be. Add a widget asks what kind;
 * a card's pencil opens a panel at the right holding everything about that
 * one widget, and its handle moves it.
 *
 * The template hands its data over as JSON in
 *
 *   <script type="application/json" data-ui="custom-report-editor:config">
 *
 * and this module starts the editor on DOM ready when that element is on the
 * page. Everything the author may choose is read from it -- the reporting
 * registry's names and the server's per-type rules -- rather than written
 * here: the same lists validate the definition on save, and a second copy is
 * how the two come to disagree about a type added later.
 *
 * WHERE THE STATE LIVES
 *
 * In the `widgets` array, which the canvas is a rendering OF: a redraw reads
 * from it and the panel writes to it, so a redraw cannot lose anything. The
 * definition is posted as ONE field, assembled at submit (buildDefinition()).
 *
 * Nothing here is trusted. Every name the author picks is validated against
 * the registry server-side before the report is stored, and again when it
 * renders.
 */

/** The element holding the template's data. */
export const CONFIG_SELECTOR = '[data-ui="custom-report-editor:config"]';

/*
 * The types whose link column is unambiguous.
 *
 * A card shows one dimension, so the column a link comes from is the one it
 * has. A full-width grid can show several and would need that choice asked
 * separately; a share chart's Other row is no one value to link to.
 */
export const LINKABLE_TYPES = [ 'grid-card' ];

/** A pattern with its %s, %d or %1$s placeholders filled, in order. */
export function say( pattern ) {

    var values = Array.prototype.slice.call( arguments, 1 );
    var next   = 0;

    return String( pattern ).replace( /%(?:(\d+)\$)?([sd%])/g, function ( match, position, kind ) {
        return kind === '%' ? '%' : String( values[ position ? position - 1 : next++ ] );
    } );
}

/** A comma string from the definition, as an array of names. */
export function names( value ) {

    if ( ! value ) {
        return [];
    }

    return ( Array.isArray( value ) ? value : String( value ).split( ',' ) )
        .map( function ( n ) { return String( n ).trim(); } )
        .filter( Boolean );
}

/** The metric names one widget asks for. */
export function widgetMetrics( widget ) {

    return names( widget && widget.query && widget.query.metrics );
}

/** How a card says a width, where it has a word for it. */
export function widthWord( colspan ) {

    return {
        12: OWA.l( 'Full width' ),
        8: OWA.l( 'Two thirds' ),
        6: OWA.l( 'Half width' ),
        4: OWA.l( 'One third' ),
        3: OWA.l( 'One quarter' )
    }[ colspan ] || '';
}

/** ...and a height. */
export function heightWord( rowspan ) {

    return { 1: OWA.l( 'short' ), 2: OWA.l( 'tall' ) }[ rowspan ] || '';
}

/**
 * The rules each widget type brings, from the template's data.
 *
 * A full-width type gets no column span control; a single-field type takes
 * one metric and one dimension; a fixed-dimension type (a trend) is always
 * over its date and may add FIXED_DIMENSION_EXTRA more; an ungrouped type
 * shows totals and picks no dimensions.
 *
 * @param  {Object} config  the template's data
 * @return {Object}
 */
export function typeRules( config ) {

    var columns = Number( config.columns ) || 12;

    var rules = {

        columns: columns,

        isFullWidth: function ( type ) {
            return ( config.fullWidthTypes || [] ).indexOf( type ) !== -1;
        },

        isSingleField: function ( type ) {
            return ( config.singleFieldTypes || [] ).indexOf( type ) !== -1;
        },

        /* Draws one metric, and cannot fall back to the report metric set for it. */
        isSingleMetric: function ( type ) {
            return ( config.singleMetricTypes || [] ).indexOf( type ) !== -1;
        },

        /* The dimension a type is fixed to, or null where the author picks. */
        fixedDimension: function ( type ) {

            var fixed = config.fixedDimensions || {};

            return Object.prototype.hasOwnProperty.call( fixed, type ) ? fixed[ type ] : null;
        },

        /* How many dimensions a fixed-dimension type may add beyond its first. */
        fixedExtra: function ( type ) {
            return ( config.fixedDimensionExtra || {} )[ type ] || 0;
        },

        /**
         * Whether the author picks any dimensions for this type at all.
         *
         * A trend does: its first is fixed to a date, but the one after it --
         * the dimension whose values become its lines -- is the author's. Only
         * a type with nothing to group by has no picker.
         */
        picksDimensions: function ( type ) {

            if ( ( config.ungroupedTypes || [] ).indexOf( type ) !== -1 ) {

                return false;
            }

            return rules.fixedDimension( type ) === null || rules.fixedExtra( type ) > 0;
        },

        /** The width a widget of this type gets when it names none. */
        defaultColspan: function ( type ) {
            return ( config.defaultColspans || {} )[ type ] || columns;
        },

        /** The width a NEW widget of this type is added at. */
        newColspan: function ( type ) {
            return ( config.newColspans || {} )[ type ] || rules.defaultColspan( type );
        },

        /** The width a widget actually draws at. */
        colspanOf: function ( widget ) {

            if ( rules.isFullWidth( widget.type ) ) {
                return columns;
            }

            return Number( widget.colspan ) || rules.defaultColspan( widget.type );
        },

        /** The metric cap for a type: a card or a chart draws one. */
        maxMetrics: function ( type ) {
            return rules.isSingleMetric( type ) ? 1 : Number( config.maxMetrics );
        },

        /**
         * How many dimensions the AUTHOR picks.
         *
         * A fixed-dimension type does not pick its first one, so this is what
         * it may add beyond it: a trend picks the one whose values become its
         * lines, and nothing else.
         */
        maxDimensions: function ( type ) {

            if ( rules.fixedDimension( type ) !== null ) {

                return rules.fixedExtra( type );
            }

            return rules.isSingleField( type ) ? 1 : Number( config.maxDimensions );
        },

        isChart: function ( type ) {
            return ( config.chartTypes || [] ).indexOf( type ) !== -1;
        },

        isTimeDimension: function ( name ) {
            return ( config.timeDimensions || [] ).indexOf( name ) !== -1;
        }
    };

    return rules;
}

/**
 * A widget the type chooser adds.
 *
 * A colspan only where the author has one to choose. A full-width type
 * records none, so the width it draws at stays ReportGrid's answer rather
 * than a number copied into every definition.
 */
export function newWidget( rules, index, type ) {

    type = type || 'grid';

    var widget = {
        type: type,
        title: say( OWA.l( 'Widget %s' ), index + 1 ),
        rowspan: 1,
        query: {}
    };

    if ( ! rules.isFullWidth( type ) ) {
        widget.colspan = rules.newColspan( type );
    }

    var fixed = rules.fixedDimension( type );

    if ( fixed !== null ) {
        widget.query.dimensions = fixed;
        widget.query.sort       = fixed;
    }

    return widget;
}

/**
 * Why this report cannot be saved yet, or '' if it can.
 *
 * The same rules the server applies, checked before the round trip rather
 * than instead of it: CustomReports::validate() and the save controller's
 * validate() still refuse all of these. Ordered as an author would fix them:
 * a widget, a name, then each widget's metrics.
 *
 * Every widget names its own metrics unless the report carries a stored
 * report-level metric set, the one case the server lets inherit -- the builder allows exactly what the server allows, or it refuses
 * an old report its author cannot fix.
 *
 * @param  {Object[]} widgets
 * @param  {string}   name           the report name as typed
 * @param  {string}   reportMetrics  the stored report-level metric set
 * @return {string}
 */
export function reportProblem( widgets, name, reportMetrics ) {

    if ( ! widgets.length ) {
        return OWA.l( 'Add at least one widget before saving.' );
    }

    // Required by CustomReportSave::validate().
    if ( ! String( name || '' ).trim() ) {
        return OWA.l( 'Give the report a name before saving.' );
    }

    if ( ! reportMetrics ) {

        for ( var i = 0; i < widgets.length; i++ ) {

            if ( ! widgetMetrics( widgets[ i ] ).length ) {

                return say( OWA.l( '%s has no metrics.' ), widgets[ i ].title || say( OWA.l( 'Widget %s' ), i + 1 ) );
            }
        }
    }

    return '';
}

/**
 * What is wrong with the widget the panel is open on, short of its filters,
 * or '' if it is ready. Ordered as the fields appear in the form.
 *
 * Metrics, unless the report carries a set from before that control was
 * withdrawn. A dimension on the types that draw exactly one and render
 * nothing without it (the server's SINGLE_FIELD_TYPES).
 *
 * @param  {Object}   rules          typeRules()
 * @param  {string}   type
 * @param  {string[]} metrics        chosen in the panel
 * @param  {string[]} dimensions     chosen in the panel
 * @param  {string}   reportMetrics
 * @return {string}
 */
export function panelProblem( rules, type, metrics, dimensions, reportMetrics ) {

    if ( ! reportMetrics && ! metrics.length ) {

        return rules.isSingleMetric( type ) ? OWA.l( 'Choose a metric.' ) : OWA.l( 'Choose at least one metric.' );
    }

    if ( rules.isSingleField( type ) && rules.picksDimensions( type ) && ! dimensions.length ) {

        return OWA.l( 'Choose a dimension.' );
    }

    return '';
}

/**
 * Write what the panel holds onto the widget.
 *
 * The type is not read back: it was chosen when the widget was added and the
 * panel was built for it.
 *
 * @param {Object} rules   typeRules()
 * @param {Object} config  the template's data (linkTargets, moreLabel)
 * @param {Object} widget  changed in place
 * @param {Object} panel   { index, title, colspan, rowspan, metrics, dimensions
 *                         (as picked), sort, sortDesc, constraints, linkTo,
 *                         moreTo, moreLabel }
 * @return {Object} the widget
 */
export function applyPanel( rules, config, widget, panel ) {

    var query = {};

    widget.title = panel.title || say( OWA.l( 'Widget %s' ), panel.index + 1 );

    if ( rules.isFullWidth( widget.type ) ) {

        // Not the author's to choose, so nothing is recorded -- see newWidget().
        delete widget.colspan;

    } else {

        widget.colspan = panel.colspan || rules.defaultColspan( widget.type );
    }

    widget.rowspan = panel.rowspan || 1;

    var metrics = panel.metrics || [];

    /*
     * The dimensions, from whichever of the three this type is. Fixed: written
     * from the type, not from a control. Ungrouped: none at all. Otherwise:
     * what the author picked. The axis first, then the breakdown -- the chart
     * reads the first dimension as what it plots against and the second as
     * what it breaks out into lines.
     */
    var fixed      = rules.fixedDimension( widget.type );
    var picked     = rules.picksDimensions( widget.type ) ? ( panel.dimensions || [] ) : [];
    var dimensions = fixed !== null ? [ fixed ].concat( picked ) : picked;

    // A fixed-dimension type orders by that dimension; an ungrouped one has no rows to order.
    var sort = fixed !== null
        ? fixed
        : ( rules.picksDimensions( widget.type ) && panel.sort
            ? panel.sort + ( panel.sortDesc ? '-' : '' )
            : '' );

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

    if ( panel.constraints ) {
        widget.constraints = panel.constraints;
    } else {
        delete widget.constraints;
    }

    // A chart draws ONE metric, and the renderer reads which from chartMetric.
    if ( rules.isChart( widget.type ) && metrics.length ) {

        widget.chartMetric = metrics[0];

    } else {
        delete widget.chartMetric;
    }

    /*
     * Where a row leads. Everything except the destination is derived: a card
     * shows one dimension, so that is the column the link comes from and the
     * value it carries, and the parameter name is the one the destination
     * declared it is read under. A link the type or dimension no longer
     * justifies is dropped.
     */
    var linkable = LINKABLE_TYPES.indexOf( widget.type ) !== -1 && dimensions.length === 1;
    var target   = linkable && ( ( config.linkTargets || {} )[ dimensions[0] ] || [] ).filter(
        function ( t ) { return t.id === panel.linkTo; } )[0];

    if ( target ) {

        var template = { 'do': 'base.report', reportId: target.id };

        template[ target.param ] = '%s';

        widget.link = {
            linkColumn:   dimensions[0],
            template:     template,
            valueColumns: dimensions[0]
        };

    } else {

        delete widget.link;
    }

    if ( panel.moreTo ) {

        widget.more = { reportId: panel.moreTo, label: panel.moreLabel || config.moreLabel };

    } else {

        delete widget.more;
    }

    return widget;
}

/**
 * The definition the form posts: the name, the widgets in order with the id
 * and container the renderer addresses them by, and a stored report-level
 * metric set passed through unchanged (there is no control for it, so a
 * rename must not strip it).
 *
 * @param  {string}   name
 * @param  {Object[]} widgets
 * @param  {string}   reportMetrics
 * @return {Object}
 */
export function buildDefinition( name, widgets, reportMetrics ) {

    var built = { title: name, widgets: [] };

    if ( reportMetrics ) {
        built.metrics = reportMetrics;
    }

    widgets.forEach( function ( widget, i ) {

        built.widgets.push( jQuery.extend( {}, widget, {
            id: 'w' + ( i + 1 ),
            container: 'w' + ( i + 1 )
        } ) );
    } );

    return built;
}

/**
 * Start the editor on the page's markup.
 *
 * @param {Object} config  the template's data
 */
export function start( config ) {

    var METRICS        = config.metrics || [];
    var DIMENSIONS     = config.dimensions || [];
    var TYPES          = config.types || {};
    var MAX            = Number( config.maxWidgets );
    var MAX_METRICS    = Number( config.maxMetrics );
    var MAX_DIMENSIONS = Number( config.maxDimensions );
    var WIDTHS         = config.widths || [];
    var HEIGHTS        = config.heights || [];
    var LINK_TARGETS   = config.linkTargets || {};
    var MORE_TARGETS   = config.moreTargets || {};
    var MORE_LABEL     = config.moreLabel;
    var TYPE_ICONS     = config.typeIcons || {};

    var rules   = typeRules( config );
    var COLUMNS = rules.columns;

    var isFullWidth     = rules.isFullWidth;
    var isSingleField   = rules.isSingleField;
    var isSingleMetric  = rules.isSingleMetric;
    var fixedDimension  = rules.fixedDimension;
    var picksDimensions = rules.picksDimensions;
    var defaultColspan  = rules.defaultColspan;
    var colspanOf       = rules.colspanOf;

    /** The type the panel is open on. It cannot change while it is open. */
    function editingType() {
        return editing === null ? '' : widgets[ editing ].type;
    }

    /*
     * The caps for the type the panel is open on. A card is one metric against
     * one dimension, so its pickers stop offering after the first -- rather
     * than offering a second and refusing it on save.
     */
    function maxMetrics() {
        return rules.maxMetrics( editingType() );
    }

    function maxDimensions() {
        return rules.maxDimensions( editingType() );
    }

    var definition = config.definition || {};

    /*
     * The state. The canvas is a rendering of this array, not the reverse: a
     * redraw reads from here and the panel writes to here.
     */
    var widgets = ( definition && definition.widgets ) ? definition.widgets.slice() : [];

    /*
     * A NEW REPORT STARTS EMPTY.
     *
     * The first thing on the screen is the choice that starts a report: what
     * kind of widget. Save is refused until one exists, here and on the server
     * both.
     */

    /*
     * The report-level metric set, as it was stored.
     *
     * Not editable, but carried so that saving a report that has one does not
     * silently strip it. Read once, written back on submit, never looked at in
     * between.
     */
    var reportMetrics = ( definition && definition.metrics ) ? definition.metrics : '';

    var editing = null;   // index of the widget the panel is open on

    /*
     * Index of a widget the type chooser added that has not been applied yet.
     *
     * Held here rather than as a flag on the widget: the submit handler copies
     * the widget object into the posted definition, so a flag would be stored.
     */
    var pendingNew = null;

    /*
     * The report the widget's rows link to, as the panel was opened.
     *
     * Held apart from the select because the select is rebuilt whenever the
     * dimension changes -- without this, changing the dimension and changing it
     * back would silently drop a link the author had set.
     */
    var dialogLink = '';

    /** ...and the same for the link below the widget, for the same reason. */
    var dialogMore = '';

    // The panel's span, sort and filters while it is open.
    var dialogColspan  = 0;
    var dialogRowspan  = 1;
    var dialogSort     = '';
    var dialogSortDesc = true;
    var sortTouched    = false;

    // The type the panel opened on, which Cancel puts back after a change.
    var dialogOriginalType = null;

    // Whether the gallery is open to change the type rather than to add.
    var changingType = false;

    // The widget whose pencil opened the panel, to go back to when it closes.
    // The widget rather than the button: closing redraws the cards.
    var returnFocus = null;

    /**
     * Fill a picker with the choices, the ones already CHOSEN first, in the order they were chosen.
     *
     * ORDER IS MEANING HERE, not presentation. The first metric is the one a
     * trend charts; the first dimension is what a grid is grouped by and what
     * a report is about. So the list has to come back in the author's order.
     *
     * narrowMetrics() refills the select each time one is picked, and
     * `$select.val()` answers in OPTION order. Putting the selected block first
     * covers both halves: `.val()` answers in that order, and chosen renders its pills from the options, so
     * the pills read in the author's order too. It costs nothing in the drop --
     * chosen hides an already-selected option from the list.
     */
    function fillChoices( $select, choices, selected ) {

        selected = selected || [];

        $select.empty();

        var byName = {};

        choices.forEach( function ( choice ) { byName[ choice.name ] = choice; } );

        var ordered = [];

        selected.forEach( function ( name ) {

            if ( byName[ name ] ) {

                ordered.push( byName[ name ] );
            }
        } );

        choices.forEach( function ( choice ) {

            if ( selected.indexOf( choice.name ) === -1 ) {

                ordered.push( choice );
            }
        } );

        ordered.forEach( function ( choice ) {
            $select.append( jQuery( '<option>' )
                .attr( 'value', choice.name )
                .prop( 'selected', selected.indexOf( choice.name ) !== -1 )
                .text( choice.label + ' (' + choice.name + ')' ) );
        } );
    }

    /**
     * Narrow the DIMENSION picker to what can still be added: nothing at the
     * cap, and no time dimension on a type whose axis is time.
     */
    function narrowDimensions() {

        var $select  = jQuery( '#dlgDimensions' );
        var selected = $select.val() || [];

        var full = selected.length >= maxDimensions();

        /*
         * A trend is drawn AGAINST time, so time cannot also be what it is
         * broken out by -- visits by month, over months, is not a chart anybody
         * meant to ask for. Only for a type whose axis is a fixed date; every
         * other widget may group by a date like any other dimension.
         */
        var axisIsTime = fixedDimension( editingType() ) !== null;

        var allowed = DIMENSIONS.filter( function ( choice ) {

            if ( selected.indexOf( choice.name ) !== -1 ) {
                return true;
            }

            if ( full ) {
                return false;
            }

            return ! ( axisIsTime && rules.isTimeDimension( choice.name ) );
        } );

        fillChoices( $select, allowed, selected );

        chosenSync( '#dlgDimensions' );
    }

    /**
     * Narrow a metric picker to what can still be added: nothing at the cap.
     *
     * Rebuilt rather than disabled item by item, because chosen renders its own
     * list from the select and only re-reads it on chosen:updated. An option
     * left in place but unusable would still appear in the search results.
     */
    function narrowMetrics() {

        var $select  = jQuery( '#dlgMetrics' );
        var selected = $select.val() || [];

        // The widget's own cap, which depends on its type.
        var full = selected.length >= maxMetrics();

        var allowed = METRICS.filter( function ( choice ) {

            if ( selected.indexOf( choice.name ) !== -1 ) {
                return true;   // already chosen: never remove it under the author
            }

            // At the cap nothing more is offered, rather than offered and then
            // refused on save.
            return ! full;
        } );

        fillChoices( $select, allowed, selected );

        chosenSync( '#dlgMetrics' );
    }

    /**
     * Enhance a <select multiple> into the searchable pill control.
     *
     * CHOSEN, the same widget the grid's secondary dimension picker uses.
     *
     * The explicit width is not decoration. chosen-js 1.x sizes its container
     * from the select's offsetWidth AT ENHANCEMENT TIME, which is 0 inside a
     * display:none parent -- and the widget panel is hidden until it is
     * opened, so without this its two pickers enhance to a couple of pixels
     * wide and are unusable. The same trap is documented on the constraint
     * builder's dimension picker, which enhances inside a hidden .builder.
     */
    function chosenify( selector ) {

        jQuery( selector ).chosen( {
            width: '100%',
            no_results_text: OWA.l( 'Name not found.' ),
            placeholder_text_multiple: OWA.l( 'Type to search…' ),
        } );
    }

    /**
     * Re-sync a chosen control to its select after setting values in code.
     *
     * chosen-js 1.x ignores a programmatic .val() until told with
     * chosen:updated.
     */
    function chosenSync( selector ) {

        jQuery( selector ).trigger( 'chosen:updated' );
    }

    // ------------------------------------------------------------------
    // The canvas
    // ------------------------------------------------------------------

    function draw() {

        var $canvas = jQuery( '#customReportCanvas' ).empty();

        widgets.forEach( function ( widget, i ) {

            var colspan = colspanOf( widget );
            var rowspan = Number( widget.rowspan ) || 1;
            var title   = widget.title || say( OWA.l( 'Widget %s' ), i + 1 );

            var $block = jQuery( '<div class="owa_builderBlock" draggable="true">' )
                .attr( 'data-index', i )
                // As wide as the span it claims, so the canvas reads as the
                // layout rather than as a list.
                .addClass( 'owa_builderSpan-' + colspan )
                .toggleClass( 'is-editing', editing === i );

            $block.append( jQuery( '<div class="owa_builderBlockKind">' )
                .append( jQuery( '<button type="button" class="owa_builderMove">' )
                    .attr( { 'aria-label': say( OWA.l( 'Move %s' ), title ), title: OWA.l( 'Drag to move, or use the arrow keys' ) } )
                    .append( '<i class="fas fa-grip-vertical" aria-hidden="true"></i>' ) )
                .append( jQuery( '<i aria-hidden="true">' ).attr( 'class', TYPE_ICONS[ widget.type ] || '' ) )
                .append( jQuery( '<span class="owa_builderBlockType">' )
                    .text( TYPES[ widget.type ] || widget.type ) ) );

            $block.append( jQuery( '<div class="owa_builderBlockName">' ).text( title ) );

            /*
             * A card that names no metrics is marked ON THE CANVAS, not only
             * in the sentence under Save -- the canvas is where an author is
             * looking. Not marked where a report metric set exists, because
             * there the widget is inheriting rather than unfinished, the same
             * exception the server makes.
             */
            if ( ! reportMetrics && ! widgetMetrics( widget ).length ) {

                $block.addClass( 'owa_builderBlockIncomplete' );
            }

            $block.append( jQuery( '<div class="owa_builderBlockSummary">' ).text( summaryOf( widget ) ) );

            $block.append( jQuery( '<div class="owa_builderBlockFoot">' )
                .append( jQuery( '<span class="owa_builderBlockSpan">' ).text(
                    ( widthWord( colspan ) || say( OWA.l( '%1$s of %2$s' ), colspan, COLUMNS ) )
                    + ' · ' + ( heightWord( rowspan ) || say( OWA.ln( '%d row tall', '%d rows tall', rowspan ), rowspan ) ) ) )
                .append( jQuery( '<span class="owa_builderBlockTools">' )
                    .append( jQuery( '<button type="button" class="owa_iconButton owa_builderEdit">' )
                        .attr( { 'aria-label': say( OWA.l( 'Edit %s' ), title ), title: OWA.l( 'Edit' ) } )
                        .append( '<i class="fas fa-pencil-alt" aria-hidden="true"></i>' ) )
                    .append( jQuery( '<button type="button" class="owa_iconButton owa_builderRemove">' )
                        .attr( { 'aria-label': say( OWA.l( 'Remove %s' ), title ), title: OWA.l( 'Remove' ) } )
                        .append( '<i class="fas fa-times" aria-hidden="true"></i>' ) ) ) );

            $canvas.append( $block );
        } );

        if ( widgets.length < MAX ) {

            $canvas.append(
                jQuery( '<button type="button" id="addWidget" class="owa_builderAdd">' )
                    .append( '<i class="fas fa-plus" aria-hidden="true"></i>' )
                    .append( jQuery( '<span>' ).text( OWA.l( 'Add a widget' ) ) ) );
        }

        $canvas.toggleClass( 'owa_builderCanvasEmpty', ! widgets.length );

        jQuery( '#customReportEmpty' ).toggle( ! widgets.length );

        jQuery( '#widgetBudget' ).text( say( OWA.ln( '%1$s of %2$s widget', '%1$s of %2$s widgets', MAX ), widgets.length, MAX )
            + ( widgets.length > 1 ? ' · ' + OWA.l( 'drag to reorder' ) : '' ) );

        refreshSaveState();
    }

    /** A name's label, from the registry's choices. */
    function labelOf( name ) {

        var all = METRICS.concat( DIMENSIONS );

        for ( var i = 0; i < all.length; i++ ) {
            if ( all[ i ].name === name ) {
                return all[ i ].label;
            }
        }

        return name;
    }

    /**
     * What a card says the widget shows: "Page views by Page path · filtered:
     * Page path contains /blog/ · rows link to Pages".
     */
    function summaryOf( widget ) {

        var query   = widget.query || {};
        var metrics = widgetMetrics( widget ).map( labelOf );
        var dims    = names( query.dimensions ).map( labelOf );

        if ( ! metrics.length ) {
            return OWA.l( 'No metrics chosen yet' );
        }

        var parts = [ dims.length ? say( OWA.l( '%1$s by %2$s' ), metrics.join( ', ' ), dims.join( ', ' ) ) : metrics.join( ', ' ) ];

        var filters = OWA.filterPills.parse( widget.constraints || '' ).map( filterWords );

        if ( filters.length ) {
            parts.push( say( OWA.l( 'filtered: %s' ), filters.join( ', ' ) ) );
        }

        var linkTo = ( ( widget.link || {} ).template || {} ).reportId;

        if ( linkTo ) {

            var target = ( LINK_TARGETS[ names( query.dimensions )[0] ] || [] ).filter(
                function ( t ) { return t.id === linkTo; } )[0];

            parts.push( say( OWA.l( 'rows link to %s' ), target ? target.label : linkTo ) );
        }

        return parts.join( ' · ' );
    }

    /**
     * Move a widget to another place in the order.
     *
     * The panel may be open on one of them, so the index it is editing and
     * the index of an unsaved new widget follow the widget, not the slot.
     */
    function moveWidget( from, to ) {

        if ( from === to || to < 0 || to >= widgets.length ) {
            return false;
        }

        var edited  = editing === null ? null : widgets[ editing ];
        var pending = pendingNew === null ? null : widgets[ pendingNew ];
        var moved   = widgets.splice( from, 1 )[0];

        widgets.splice( to, 0, moved );

        editing    = edited === null ? null : widgets.indexOf( edited );
        pendingNew = pending === null ? null : widgets.indexOf( pending );

        draw();

        jQuery( '#customReportMoved' ).text(
            say( OWA.l( '%1$s, position %2$s of %3$s.' ), moved.title || OWA.l( 'Widget' ), to + 1, widgets.length ) );

        return true;
    }

    /** Why this report cannot be saved yet, or '' if it can; see reportProblem(). */
    function saveBlocker() {

        return reportProblem( widgets, jQuery( '#customReportName' ).val(), reportMetrics );
    }

    /**
     * Save is enabled exactly when the report could be stored. Disabled
     * rather than hidden, with the reason beside it.
     */
    function refreshSaveState() {

        var blocker = saveBlocker();

        jQuery( '#customReportSubmit' )
            .prop( 'disabled', !! blocker )
            .attr( 'title', blocker );

        jQuery( '#customReportBlocker' ).text( blocker ).toggle( !! blocker );
    }

    // ------------------------------------------------------------------
    // The panel
    // ------------------------------------------------------------------

    function openDialog( index, opener ) {

        // Another widget's panel was open: that edit is abandoned, as Cancel.
        if ( editing !== null && editing !== index ) {
            index = closePanel( index );
        }

        editing = index;

        returnFocus = opener ? widgets[ index ] : null;

        // A refusal from last time is not about this widget.
        jQuery( '#dlgError' ).text( '' ).hide();

        var widget = widgets[ index ];
        var query  = widget.query || {};

        jQuery( '#dlgTitle' ).val( widget.title || say( OWA.l( 'Widget %s' ), index + 1 ) );

        dialogOriginalType = widget.type;
        renderTypePill( widget.type );

        dialogLink = String( ( ( widget.link || {} ).template || {} ).reportId || '' );
        dialogMore = String( ( widget.more || {} ).reportId || '' );

        dialogColspan = colspanOf( widget );
        dialogRowspan = Number( widget.rowspan ) || 1;
        renderSpanChoices();

        var sort = String( query.sort || '' );

        dialogSortDesc = /-$/.test( sort );
        dialogSort     = sort.replace( /-$/, '' );
        sortTouched    = false;

        filters.set( widget.constraints || '' );

        fillChoices( jQuery( '#dlgMetrics' ), METRICS, names( query.metrics ) );
        /*
         * The dimensions the AUTHOR chose.
         *
         * A trend stores its axis first and its breakdown after it, and the
         * axis is not a choice -- showing it in the picker would offer to
         * remove the thing that makes a trend a trend.
         */
        var chosenDimensions = names( query.dimensions );

        if ( fixedDimension( widget.type ) !== null ) {

            chosenDimensions = chosenDimensions.slice( 1 );
        }

        fillChoices( jQuery( '#dlgDimensions' ), DIMENSIONS, chosenDimensions );

        chosenSync( '#dlgMetrics' );
        chosenSync( '#dlgDimensions' );

        // After the selections are loaded, not before: applyTypeRules() trims
        // to the type's cap, and run any earlier it would be trimming the
        // widget the panel was open on last time.
        applyTypeRules();

        var more = widget.more || {};

        // Blank when it is the default, so the placeholder shows what the link
        // will say rather than the field repeating it back.
        jQuery( '#dlgMoreLabel' ).val(
            more.label && more.label !== MORE_LABEL ? more.label : '' );

        jQuery( '#widgetDialog' ).addClass( 'is-open' ).scrollTop( 0 );
        jQuery( '.owa_widgetPanelBody' ).scrollTop( 0 );

        // The card being edited is marked.
        draw();

        jQuery( '#dlgTitle' ).trigger( 'focus' );
    }

    /**
     * Close the panel without applying it. A widget that was added and never
     * applied is taken off the layout again.
     *
     * @param  keep  an index to follow through the removal
     * @return       where that index is afterwards
     */
    function closePanel( keep ) {

        jQuery( '#widgetDialog' ).removeClass( 'is-open' );
        filters.close();

        // A type changed and not applied goes back.
        if ( editing !== null && editing !== pendingNew && dialogOriginalType ) {
            widgets[ editing ].type = dialogOriginalType;
        }

        if ( pendingNew !== null ) {

            widgets.splice( pendingNew, 1 );

            if ( keep !== undefined && keep > pendingNew ) {
                keep--;
            }
        }

        pendingNew = null;
        editing    = null;

        draw();

        restoreFocus();

        return keep;
    }

    function renderTypePill( type ) {

        jQuery( '#dlgTypePill' )
            .empty()
            .append( jQuery( '<i aria-hidden="true">' ).attr( 'class', TYPE_ICONS[ type ] || '' ) )
            .append( document.createTextNode( ' ' + ( TYPES[ type ] || type ) ) );
    }

    /**
     * Make the widget being edited another type, keeping what the new type
     * can use: its name, filters, height and link targets; its metrics and
     * dimensions up to the new type's caps (a trend's breakdown cannot be a
     * date); its width if the new type may be narrowed. Applied with the rest
     * of the panel, and undone by Cancel.
     */
    function changeType( type ) {

        var widget  = widgets[ editing ];
        var metrics = jQuery( '#dlgMetrics' ).val() || [];

        // A trend's picker holds only its breakdown; its axis is not carried.
        var dims = jQuery( '#dlgDimensions' ).val() || [];

        var wasFullWidth = isFullWidth( widget.type );

        widget.type = type;

        metrics = metrics.slice( 0, isSingleMetric( type ) ? 1 : MAX_METRICS );

        if ( ! picksDimensions( type ) ) {
            dims = [];
        } else if ( fixedDimension( type ) !== null ) {
            dims = dims.filter( function ( d ) { return ! rules.isTimeDimension( d ); } )
                .slice( 0, rules.fixedExtra( type ) );
        } else {
            dims = dims.slice( 0, isSingleField( type ) ? 1 : MAX_DIMENSIONS );
        }

        if ( isFullWidth( type ) ) {
            dialogColspan = COLUMNS;
        } else if ( wasFullWidth ) {
            dialogColspan = rules.newColspan( type );
        }

        fillChoices( jQuery( '#dlgMetrics' ), METRICS, metrics );
        fillChoices( jQuery( '#dlgDimensions' ), DIMENSIONS, dims );
        chosenSync( '#dlgMetrics' );
        chosenSync( '#dlgDimensions' );

        renderTypePill( type );
        renderSpanChoices();
        applyTypeRules();
        draw();
    }

    /** Back to the pencil that opened the panel, or to Add a widget. */
    function restoreFocus() {

        var at  = returnFocus === null ? -1 : widgets.indexOf( returnFocus );
        var $to = at === -1 ? jQuery( '#addWidget' )
            : jQuery( '#customReportCanvas .owa_builderBlock' ).eq( at ).find( '.owa_builderEdit' );

        returnFocus = null;
        $to.trigger( 'focus' );
    }

    /**
     * Width and Height, as segmented choices. A widget whose span is not one of
     * them -- a quarter-width card from before this screen, say -- gets its own
     * as an extra choice, so opening the panel does not change it.
     */
    function renderSpanChoices() {

        var seg = function ( $holder, name, choices, current ) {

            var list = choices.slice();

            if ( ! list.some( function ( c ) { return c[0] === current; } ) ) {
                list.push( [ current, name === 'dlgWidth'
                    ? ( widthWord( current ) || say( OWA.l( '%1$s of %2$s' ), current, COLUMNS ) )
                    : say( OWA.ln( '%d row', '%d rows', current ), current ) ] );
                list.sort( function ( a, b ) { return a[0] - b[0]; } );
            }

            $holder.empty();

            list.forEach( function ( c ) {

                var id = name + '-' + c[0];

                $holder.append( jQuery( '<input type="radio">' )
                        .attr( { id: id, name: name, value: c[0] } )
                        .prop( 'checked', c[0] === current ) )
                    .append( jQuery( '<label>' ).attr( 'for', id ).text( c[1] ) );
            } );
        };

        seg( jQuery( '#dlgWidth' ), 'dlgWidth', WIDTHS, dialogColspan );
        seg( jQuery( '#dlgHeight' ), 'dlgHeight', HEIGHTS, dialogRowspan );
    }

    /**
     * What the chosen TYPE decides, applied to the panel.
     *
     * Run when the panel opens and on every type change, because both are the
     * same event as far as the fields are concerned: the type is what says
     * whether there is a width to choose and how many fields may be picked.
     *
     * Nothing here is a substitute for the server's rules -- the definition is
     * validated on save whatever this does. This is so an author is never
     * offered something that would then be refused.
     */
    function applyTypeRules() {

        var type   = editingType();
        var single = isSingleField( type );
        var name   = TYPES[ type ] || type;


        jQuery( '#dlgColspanField' ).toggle( ! isFullWidth( type ) );

        jQuery( '#dlgWidthNote' )
            .toggle( isFullWidth( type ) )
            .text( say( OWA.l( '%s is always full width, so it has room for its own filter and dimension controls.' ), name ) );

        /*
         * A metric SET, or one metric.
         *
         * A table or a row of boxes draws every metric it is given, and takes
         * the report's own set when it names none. A card ranks its rows by one
         * and a pie is a share of one, so neither can use a set at all -- which
         * is why the field is singular for them and says so.
         */
        var oneMetric = isSingleMetric( type );

        jQuery( '#dlgMetricsLabel' ).text( oneMetric ? OWA.l( 'Metric' ) : OWA.l( 'Metrics' ) );

        /*
         * Every type has to answer this field: a widget that names no metrics is
         * refused on save.
         */
        var required = ' ' + OWA.l( 'Required.' );

        /*
         * ...and the mark beside the label says the same thing at a glance.
         *
         * Metrics are required on every type UNLESS the report carries a stored
         * report-level metric set -- the one case the server lets a widget
         * inherit. Marking it required there would be
         * telling an author to fill a field they can legitimately leave alone.
         */
        jQuery( '#dlgMetricsRequired' ).toggle( ! reportMetrics );

        jQuery( '#dlgMetricsHelp' ).text( oneMetric
            ? say( OWA.l( 'The one metric this %s draws.' ), name.toLowerCase() ) + required
            : ( type === 'trend' || type === 'trend-card'
                ? say( type === 'trend-card' ? OWA.l( 'Up to %s. The first is charted; all of them are drawn as boxes above it.' ) : OWA.l( 'Up to %s. The first is charted; all of them are drawn as boxes under it.' ), MAX_METRICS )
                  + required
                : say( OWA.l( 'Up to %s.' ), MAX_METRICS ) + required ) );

        var fixed = fixedDimension( type );

        jQuery( '#dlgDimensionsField' ).toggle( picksDimensions( type ) );

        /*
         * A dimension is REQUIRED exactly where the type draws exactly one --
         * a card ranks rows by it, a pie divides by it, and neither renders
         * without it. SINGLE_FIELD_TYPES is the server's own list, so the mark
         * cannot come to disagree with the rule that enforces it.
         *
         * Everywhere else it is optional: a grid may be a row of totals, and a
         * trend's breakdown is an addition to a chart that draws without one.
         */
        jQuery( '#dlgDimensionsRequired' ).toggle( single );

        if ( fixed !== null ) {

            // A trend: the axis is settled, and what is offered is the
            // breakdown whose values become the lines.
            jQuery( '#dlgDimensionsLabel' ).text( OWA.l( 'Break out by' ) );

            jQuery( '#dlgDimensionsHelp' ).text( say( OWA.l( 'Optional. Each value becomes its own line over the total; the six largest are drawn. %1$s is always over %2$s.' ), name, fixed ) );

            /*
             * ...unless it may add none, in which case the field is hidden by
             * picksDimensions() above and this text is never read. Left alone
             * rather than branched: a card has nothing to say here.
             */

        } else {

            jQuery( '#dlgDimensionsLabel' ).text( single ? OWA.l( 'Dimension' ) : OWA.l( 'Dimensions' ) );

            jQuery( '#dlgDimensionsHelp' ).text( single
                ? OWA.l( 'The one dimension its rows are grouped by.' )
                : say( OWA.l( 'Up to %s. Each one is another column.' ), MAX_DIMENSIONS ) );
        }

        jQuery( '#dlgDimensionNote' )
            .toggle( ! picksDimensions( type ) )
            .text( say( OWA.l( '%s shows totals for the period, so there is nothing to group by.' ), name ) );

        // A sort orders rows, and these types have none to order.

        narrowMetrics();

        if ( picksDimensions( type ) ) {
            narrowDimensions();
        }

        refreshLinkControl();
        refreshMoreControl();
        refreshSortControl();
        refreshDialogState();
    }

    /**
     * The destinations for whatever dimension the widget is grouped by now.
     *
     * Rebuilt on every type and dimension change, because the list IS per
     * dimension: a card grouped by browserType can lead to the report that
     * reads a browserType and to nothing else. A dimension with no detail
     * report leaves the control hidden rather than showing an empty select.
     */
    function refreshLinkControl() {

        var type       = editingType();
        var dimensions = jQuery( '#dlgDimensions' ).val() || [];
        var dimension  = dimensions.length === 1 ? dimensions[ 0 ] : '';
        var targets    = ( dimension && LINK_TARGETS[ dimension ] ) || [];

        var linkable = LINKABLE_TYPES.indexOf( type ) !== -1 && targets.length > 0;

        jQuery( '#dlgLinkField' ).toggle( linkable );

        if ( ! linkable ) {

            // Emptied, not just hidden: applyDialog reads this select, and a
            // stale value would attach a link to a widget that cannot carry it.
            jQuery( '#dlgLinkReport' ).empty();

            return;
        }

        var $select = jQuery( '#dlgLinkReport' ).empty();

        // No link is the default, and has to stay reachable -- it is how one
        // already set is taken off again.
        $select.append( jQuery( '<option>' ).attr( 'value', '' ).text( OWA.l( 'Nothing — rows are not links' ) ) );

        targets.forEach( function ( target ) {

            $select.append( jQuery( '<option>' ).attr( 'value', target.id )
                .prop( 'selected', target.id === dialogLink )
                .text( target.label ) );
        } );

        jQuery( '#dlgLinkHelp' ).text( say( OWA.l( 'Each %s becomes a link to the report that details it.' ), dimension ) );
    }

    /**
     * The "View Full Report" destinations for what this widget shows.
     *
     * Per dimension like the row link, and rebuilt on the same events. A widget
     * grouped by more than one offers the union: content's top pages is grouped
     * by pageTitle AND pagePath and leads to the one Pages report.
     */
    function refreshMoreControl() {

        var dimensions = jQuery( '#dlgDimensions' ).val() || [];

        var targets = [];
        var seen    = {};

        dimensions.forEach( function ( dimension ) {

            ( MORE_TARGETS[ dimension ] || [] ).forEach( function ( target ) {

                if ( ! seen[ target.id ] ) {
                    seen[ target.id ] = true;
                    targets.push( target );
                }
            } );
        } );

        jQuery( '#dlgMoreField' ).toggle( targets.length > 0 );

        var $select = jQuery( '#dlgMoreReport' ).empty();

        if ( ! targets.length ) {

            // Emptied as well as hidden: applyDialog reads this select, and a
            // stale value would attach a link the widget can no longer justify.
            return;
        }

        $select.append( jQuery( '<option>' ).attr( 'value', '' ).text( OWA.l( 'No link' ) ) );

        targets.forEach( function ( target ) {

            $select.append( jQuery( '<option>' ).attr( 'value', target.id )
                .prop( 'selected', target.id === dialogMore )
                .text( target.label ) );
        } );
    }

    /*
     * Filters, as pills: OWA.filterPills, over the markup in filter_pills.php.
     * The stored format is the constraints string; see owa.filterpills.js.
     */
    var filters = null;   // made once the script below has the dimensions

    function filterWords( f ) {
        return OWA.filterPills.words( f, labelOf );
    }

    /*
     * ------------------------------------------------------------------
     * Sort: one of the widget's metrics or dimensions, and a direction
     * ------------------------------------------------------------------
     */
    function refreshSortControl() {

        var type = editingType();

        var metrics = jQuery( '#dlgMetrics' ).val() || [];
        var dims    = jQuery( '#dlgDimensions' ).val() || [];

        // A new widget is sorted by its first metric, largest first, as soon
        // as it has one -- a ranked card is what most of them are for.
        if ( dialogSort === '' && pendingNew === editing && metrics.length && ! sortTouched ) {
            dialogSort     = metrics[0];
            dialogSortDesc = true;
        }

        var options = metrics.concat( dims );

        // A stored sort on a field no longer shown stays until it is changed.
        if ( dialogSort && options.indexOf( dialogSort ) === -1 ) {
            options.push( dialogSort );
        }

        var $by = jQuery( '#dlgSortBy' ).empty()
            .append( jQuery( '<option value="">' ).text( OWA.l( 'Unsorted' ) ) );

        options.forEach( function ( name ) {
            $by.append( jQuery( '<option>' ).attr( 'value', name ).text( labelOf( name ) ) );
        } );

        $by.val( dialogSort );

        var isDimension = dialogSort !== '' && metrics.indexOf( dialogSort ) === -1
            && DIMENSIONS.some( function ( c ) { return c.name === dialogSort; } );

        jQuery( '#dlgSortDir' ).empty()
            .append( jQuery( '<option value="desc">' ).text( isDimension ? OWA.l( 'Z to A' ) : OWA.l( 'Highest first' ) ) )
            .append( jQuery( '<option value="asc">' ).text( isDimension ? OWA.l( 'A to Z' ) : OWA.l( 'Lowest first' ) ) )
            .val( dialogSortDesc ? 'desc' : 'asc' )
            .prop( 'disabled', dialogSort === '' );

        jQuery( '#dlgSortField' ).toggle( picksDimensions( type ) && fixedDimension( type ) === null );
    }

    /**
     * EVERYTHING WRONG WITH THIS WIDGET, or '' if it is ready: panelProblem(),
     * then the filters.
     *
     * Checked while the panel is open, so the fields that need filling are
     * still in front of the author. The canvas marking and the Save gate
     * remain for definitions that arrive already broken, from a report stored
     * before these rules or from a refusal round-trip.
     *
     * @return string
     */
    function dialogProblem() {

        return panelProblem( rules, editingType(), jQuery( '#dlgMetrics' ).val() || [],
            jQuery( '#dlgDimensions' ).val() || [], reportMetrics ) || filters.problem();
    }

    /**
     * Write the panel back onto the widget.
     *
     * Returns FALSE when it refused to, which is what stops Apply closing on a
     * widget that is not finished -- the fields that need filling are in this
     * panel, so closing would hide them.
     *
     * @return bool
     */
    function applyDialog() {

        if ( editing === null ) {
            return true;
        }

        var problem = dialogProblem();

        jQuery( '#dlgError' ).text( problem ).toggle( !! problem );

        if ( problem ) {
            return false;
        }

        applyPanel( rules, config, widgets[ editing ], {
            index:       editing,
            title:       jQuery( '#dlgTitle' ).val(),
            colspan:     dialogColspan,
            rowspan:     dialogRowspan,
            metrics:     jQuery( '#dlgMetrics' ).val() || [],
            dimensions:  jQuery( '#dlgDimensions' ).val() || [],
            sort:        dialogSort,
            sortDesc:    dialogSortDesc,
            constraints: filters.get(),
            linkTo:      jQuery( '#dlgLinkReport' ).val() || '',
            moreTo:      jQuery( '#dlgMoreReport' ).val() || '',
            moreLabel:   jQuery.trim( jQuery( '#dlgMoreLabel' ).val() || '' )
        } );

        editing    = null;
        pendingNew = null;

        draw();

        return true;
    }

    chosenify( '#dlgMetrics' );
    chosenify( '#dlgDimensions' );

    filters = new OWA.filterPills( {
        pills: 'dlgFilters', add: 'dlgAddFilter', form: 'dlgFilterForm',
        dimension: 'dlgFilterDimension', operator: 'dlgFilterOperator', value: 'dlgFilterValue',
        addFilter: 'dlgFilterAdd', cancel: 'dlgFilterCancel'
    }, DIMENSIONS, function () { refreshDialogState(); } );

    jQuery( '#dlgMetrics' ).on( 'change', function () {
        narrowMetrics();
        narrowDimensions();
        refreshLinkControl();
        refreshMoreControl();
        refreshSortControl();
        refreshDialogState();
    } );

    jQuery( '#dlgDimensions' ).on( 'change', function () {
        narrowDimensions();
        refreshLinkControl();
        refreshMoreControl();
        refreshSortControl();
        refreshDialogState();
    } );

    jQuery( '#dlgSortBy' ).on( 'change', function () {
        sortTouched = true;
        dialogSort  = jQuery( this ).val() || '';
        refreshSortControl();
    } );

    jQuery( '#dlgSortDir' ).on( 'change', function () {
        dialogSortDesc = jQuery( this ).val() === 'desc';
    } );

    jQuery( '#dlgWidth' ).on( 'change', 'input', function () {
        dialogColspan = Number( this.value );
    } );

    jQuery( '#dlgHeight' ).on( 'change', 'input', function () {
        dialogRowspan = Number( this.value );
    } );

    jQuery( '#dlgLinkReport' ).on( 'change', function () {
        dialogLink = jQuery( this ).val() || '';
    } );

    jQuery( '#dlgMoreReport' ).on( 'change', function () {
        dialogMore = jQuery( this ).val() || '';
    } );

    /*
     * The gallery: a dialog, as New on the list is. Choosing a type adds the
     * widget and opens its panel. Closed, it is titled for adding again.
     */
    var typeDialog = OWA.dialog( document.getElementById( 'typeDialog' ), {
        title: OWA.l( 'Add a widget' ),
        width: 760,
        className: 'owa_typeDialogFrame',
        closeButton: true,
        onClose: function () {

            changingType = false;

            typeDialog.setTitle( OWA.l( 'Add a widget' ) );
            jQuery( '#typeDialog .owa_typeChoice' ).removeClass( 'is-current' ).removeAttr( 'aria-current' );
        }
    } );

    // The panel.

    // The gallery again, to change the type: titled for it, the current type marked.
    jQuery( '#dlgChangeType' ).on( 'click', function () {

        changingType = true;

        jQuery( '#typeDialog .owa_typeChoice' ).each( function () {
            var current = jQuery( this ).attr( 'data-type' ) === editingType();
            jQuery( this ).toggleClass( 'is-current', current ).attr( 'aria-current', current ? 'true' : null );
        } );

        typeDialog.setTitle( OWA.l( 'Change the widget type' ) );
        typeDialog.open( this );
    } );

    jQuery( '#dlgApply' ).on( 'click', function () {
        if ( applyDialog() ) {
            jQuery( '#widgetDialog' ).removeClass( 'is-open' );
            restoreFocus();
        }
    } );

    jQuery( '#dlgCancel, #dlgClose' ).on( 'click', function () {
        closePanel();
    } );

    jQuery( '#widgetDialog' ).on( 'keydown', function ( e ) {

        if ( e.key !== 'Escape' || jQuery( e.target ).closest( '.chosen-container' ).length ) {
            return;
        }

        // The filter form's own Escape is handled, and stopped, by OWA.filterPills.
        e.preventDefault();
        closePanel();
    } );

    function refreshDialogState() {

        if ( editing === null ) {
            return;
        }

        var problem = dialogProblem();

        jQuery( '#dlgError' ).text( problem ).toggle( !! problem );
        jQuery( '#dlgApply' ).prop( 'disabled', !! problem );
    }

    // The layout.
    jQuery( '#customReportCanvas' )
        .on( 'click', '.owa_builderEdit', function ( e ) {
            e.preventDefault();
            openDialog( Number( jQuery( this ).closest( '.owa_builderBlock' ).attr( 'data-index' ) ), this );
        } )
        .on( 'click', '.owa_builderRemove', function ( e ) {

            e.preventDefault();

            var index = Number( jQuery( this ).closest( '.owa_builderBlock' ).attr( 'data-index' ) );

            if ( editing === index ) {

                // Its panel closes; one never applied is removed by that alone.
                var wasPending = pendingNew === index;

                closePanel();

                if ( ! wasPending ) {
                    widgets.splice( index, 1 );
                }

            } else {

                // Another widget's panel stays open on that widget.
                var edited  = editing === null ? null : widgets[ editing ];
                var pending = pendingNew === null ? null : widgets[ pendingNew ];

                widgets.splice( index, 1 );

                editing    = edited === null ? null : widgets.indexOf( edited );
                pendingNew = pending === null ? null : widgets.indexOf( pending );
            }

            draw();
            jQuery( '#addWidget' ).trigger( 'focus' );
        } )
        .on( 'click', '#addWidget', function ( e ) {

            e.preventDefault();

            if ( widgets.length >= MAX ) {
                return;
            }

            typeDialog.open( this );
        } )

        // Moving by keyboard: the handle and the arrow keys.
        .on( 'keydown', '.owa_builderMove', function ( e ) {

            var step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[ e.key ];

            if ( ! step ) {
                return;
            }

            e.preventDefault();

            var from = Number( jQuery( this ).closest( '.owa_builderBlock' ).attr( 'data-index' ) );

            if ( moveWidget( from, from + step ) ) {
                jQuery( '#customReportCanvas .owa_builderBlock' ).eq( from + step )
                    .find( '.owa_builderMove' ).trigger( 'focus' );
            }
        } )

        // ...and by dragging a card onto another: it takes that card's place.
        .on( 'dragstart', '.owa_builderBlock', function ( e ) {

            dragFrom = Number( jQuery( this ).attr( 'data-index' ) );

            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData( 'text/plain', String( dragFrom ) );

            jQuery( this ).addClass( 'is-dragging' );
        } )
        .on( 'dragover', '.owa_builderBlock', function ( e ) {

            if ( dragFrom === null ) {
                return;
            }

            e.preventDefault();
            e.originalEvent.dataTransfer.dropEffect = 'move';

            jQuery( '#customReportCanvas .owa_builderBlock' ).removeClass( 'is-dropTarget' );
            jQuery( this ).not( '.is-dragging' ).addClass( 'is-dropTarget' );
        } )
        .on( 'drop', '.owa_builderBlock', function ( e ) {

            e.preventDefault();

            var to = Number( jQuery( this ).attr( 'data-index' ) );

            if ( dragFrom !== null ) {
                moveWidget( dragFrom, to );
            }

            dragFrom = null;
        } )
        .on( 'dragend', '.owa_builderBlock', function () {

            dragFrom = null;

            jQuery( '#customReportCanvas .owa_builderBlock' ).removeClass( 'is-dragging is-dropTarget' );
        } );

    var dragFrom = null;

    jQuery( '#typeDialog' ).on( 'click', '.owa_typeChoice', function ( e ) {

        e.preventDefault();

        if ( changingType ) {

            changeType( jQuery( this ).attr( 'data-type' ) );
            typeDialog.close();
            jQuery( '#dlgChangeType' ).trigger( 'focus' );

            return;
        }

        if ( widgets.length >= MAX ) {
            return;
        }

        // A panel open on an unapplied widget is abandoned first.
        if ( editing !== null ) {
            closePanel();
        }

        widgets.push( newWidget( rules, widgets.length, jQuery( this ).attr( 'data-type' ) ) );

        typeDialog.close();

        pendingNew = widgets.length - 1;

        openDialog( widgets.length - 1, null );
    } );

    /*
     * The name is one of the things Save waits for, so it is re-checked as it
     * is typed rather than only when a widget changes.
     */
    jQuery( '#customReportName' ).on( 'input', refreshSaveState );

    // The definition is assembled at submit rather than kept in step with every
    // keystroke: one place it is built means one place it can be wrong.
    jQuery( '#customReportForm' ).on( 'submit', function ( e ) {

        /*
         * A disabled Save is not the whole guard.
         *
         * Pressing Enter in a text field submits the form whether or not there
         * is a submit button to press, so an unsaveable report could still be
         * posted. Stopped here, the reason stays on the screen beside the
         * button instead of the author being sent to a refusal page.
         */
        if ( saveBlocker() ) {

            e.preventDefault();

            refreshSaveState();

            return false;
        }

        jQuery( '#customReportDefinition' ).val(
            JSON.stringify( buildDefinition( jQuery( '#customReportName' ).val(), widgets, reportMetrics ) ) );
    } );

    draw();
}

/** The editor starts where the template has handed its data over. */
jQuery( function () {

    var holder = document.querySelector( CONFIG_SELECTOR );

    if ( holder ) {

        start( JSON.parse( holder.textContent || '{}' ) );
    }
} );

export { OWA };
