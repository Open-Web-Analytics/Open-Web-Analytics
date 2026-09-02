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
           data-owa-confirm
           data-owa-confirm-title="Delete this report?"
           data-owa-confirm-body="There is no other copy of it. Unlike a Profile, a custom report is not archived &mdash; deleting it is final."
           data-owa-confirm-proceed="Delete report">Delete</a>
        <?php endif; ?>
    </div>
</form>

<?php
    /*
     * What kind of widget, asked FIRST.
     *
     * The type decides what the rest of the questions are -- a trend is always
     * by date and has no dimension to pick, a card takes one metric where a
     * table takes four, only a card's rows can be links. Asking it inside the
     * widget modal meant one form that had to be every form at once, and every
     * answer already given had to be re-examined each time the type changed.
     *
     * Asked here, the modal is built for a type that is already known. The
     * consequence is that a widget's type is fixed once it is added: to change
     * it, remove the block and add the kind you meant. That is the honest
     * trade -- a card and a trend share a name and almost nothing else.
     */
?>
<?php
    /*
     * HOW MUCH OF A ROW EACH KIND TAKES, in the words an author thinks in.
     *
     * The number is real -- Core\ReportGrid decides it, and it is the width the
     * widget will actually be added at -- but "3 of 12 columns" is a fact about
     * the grid rather than about the report. A reader choosing between a table
     * and a table card is asking "how much room does this take", and the answer
     * is a fraction of a row.
     */
    $owa_widthWords = array(
        12 => 'Full width',
        8  => 'Two thirds',
        6  => 'Half width',
        4  => 'One third',
        3  => 'One quarter',
    );
?>
<div id="typeDialog" class="owa_typeDialog" style="display:none;">
    <ul class="owa_typeChoices">
    <?php foreach ( (array) $view->get('widget_types') as $owa_key => $owa_label ): ?>
    <?php
        $owa_span = \OWA\Core\ReportGrid::defaultColspan( array( 'type' => $owa_key ) );
        $owa_pct  = round( $owa_span / \OWA\Core\ReportGrid::COLUMNS * 100 );
    ?>
        <li>
            <button type="button" class="owa_typeChoice" data-type="<?php $view->out( $owa_key ); ?>"
                    data-colspan="<?php $view->out( (string) $owa_span ); ?>">
                <?php
                    /*
                     * The drawing, at the full height of the tile.
                     *
                     * Decorative: the name beside it says the same thing, so it
                     * is hidden from assistive technology rather than read out
                     * as a second, worse label. It earns its size anyway --
                     * "Table" and "Table card" are a word apart, and the shape
                     * is what separates them at a glance.
                     */
                ?>
                <span class="owa_typeChoiceArt" aria-hidden="true">
                    <i class="owa_typeChoiceIcon <?php $view->out(
                        \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPE_ICONS[ $owa_key ] ?? '' ); ?>"></i>
                </span>
                <span class="owa_typeChoiceText">
                    <span class="owa_typeChoiceName"><?php $view->out( $owa_label ); ?></span>
                    <span class="owa_typeChoiceHint"><?php
                        $view->out( \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPE_HINTS[ $owa_key ] ?? '' ); ?></span>
                    <?php
                        /*
                         * A bar showing the share of a row this kind takes, at
                         * the proportion it really takes. The words are the
                         * label; the bar is what makes two kinds comparable
                         * without reading either.
                         */
                    ?>
                    <span class="owa_typeChoiceWidth">
                        <span class="owa_typeChoiceWidthBar">
                            <span class="owa_typeChoiceWidthFill"
                                  style="width:<?php $view->out( (string) $owa_pct, false ); ?>%"></span>
                        </span>
                        <span class="owa_typeChoiceWidthLabel"><?php
                            $view->out( $owa_widthWords[ $owa_span ] ?? ( $owa_span . ' of 12' ) ); ?></span>
                    </span>
                </span>
            </button>
        </li>
    <?php endforeach; ?>
    </ul>
</div>

<?php /* The modal body. Hidden here; jQuery UI lifts it into a dialog. */ ?>
<div id="widgetDialog" class="owa_widgetDialog" style="display:none;">

    <div class="owa_builderField">
        <label for="dlgTitle">Widget name</label>
        <?php
            /*
             * The name, and what KIND of thing is being named.
             *
             * The type is chosen at the plus and cannot be changed here, so
             * without this the modal never says which of the five you are
             * editing -- and half its fields are missing or singular BECAUSE of
             * the type. A pill rather than a disabled control: there is no
             * choice being withheld, it is a label.
             */
        ?>
        <div class="owa_builderNameRow">
            <input type="text" id="dlgTitle" />
            <span class="owa_typePill" id="dlgTypePill"></span>
        </div>
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
        <?php
            /*
             * The label is singular or plural depending on the type, because
             * the two are different things. A table or a set of boxes takes a
             * METRIC SET -- several, and the report's own set if it names none.
             * A card or a pie draws one metric and cannot use a set at all, so
             * calling that field "Metrics" would be offering a set that the
             * widget has no way to draw.
             */
        ?>
        <div class="owa_builderField" id="dlgMetricsField">
            <label for="dlgMetrics" id="dlgMetricsLabel">Metrics</label>
            <select id="dlgMetrics" class="owa_builderChosen" multiple="multiple"></select>
            <div class="owa_builderHelp" id="dlgMetricsHelp"></div>
        </div>

        <div class="owa_builderField" id="dlgDimensionsField">
            <label for="dlgDimensions" id="dlgDimensionsLabel">Dimensions</label>
            <select id="dlgDimensions" class="owa_builderChosen" multiple="multiple"></select>
            <div class="owa_builderHelp" id="dlgDimensionsHelp"></div>
        </div>

        <?php /* ...or the sentence saying the type has already decided. */ ?>
        <div class="owa_builderField owa_builderNote" id="dlgDimensionNote" style="display:none;"></div>
    </div>

    <?php
        /*
         * Where a row leads.
         *
         * Offered only for the widget types whose link column is unambiguous --
         * a card shows one dimension, so the column a link comes from is the
         * one it has. A full-width grid can show several, and which of them is
         * the link would be another choice; it is not offered rather than
         * guessed.
         *
         * The destinations are per dimension and come from the reports
         * themselves, so this list changes as the dimension does.
         */
    ?>
    <div class="owa_builderField" id="dlgLinkField" style="display:none;">
        <label for="dlgLinkReport">Rows link to</label>
        <select id="dlgLinkReport"></select>
        <div class="owa_builderHelp" id="dlgLinkHelp"></div>
    </div>

    <?php
        /*
         * "View Full Report" -- a link BELOW the widget, to the report that
         * shows the whole thing. The dashboard's summary grids all carry one.
         *
         * Different from the row link above it, and offered for every type: it
         * carries no value from the widget, so it needs no column to come from
         * -- and for the same reason it can only reach reports that read no
         * parameter, which is the list it is given.
         */
    ?>
    <div class="owa_builderFieldRow" id="dlgMoreField" style="display:none;">
        <div class="owa_builderField">
            <label for="dlgMoreReport">Full report link</label>
            <select id="dlgMoreReport"></select>
            <div class="owa_builderHelp">Shown below the widget.</div>
        </div>

        <div class="owa_builderField">
            <label for="dlgMoreLabel">Link text</label>
            <input type="text" id="dlgMoreLabel" placeholder="<?php $view->out( \OWA\Module\Base\Classes\CustomReports::MORE_LABEL ); ?>" />
        </div>
    </div>

    <div class="owa_builderField" id="dlgSortField">
        <label for="dlgSort">Sort</label>
        <input type="text" id="dlgSort" placeholder="visits-" />
        <div class="owa_builderHelp">
            A metric or dimension name. Add a trailing <code>-</code> for descending.
        </div>
    </div>

    <div class="owa_builderField">
        <label>Constraints</label>
        <?php
            /*
             * ROWS, the same ones the grid's filter uses.
             *
             * This was a text field the author typed `medium==organic-search`
             * into -- a syntax with no discoverable operator list, no
             * dimension names, and nothing to catch a typo until the widget
             * came back empty. The rows carry the same classes as the filter
             * builder's, so they get its pills, and they serialise back to
             * exactly that string on the way out: the STORED format has not
             * changed, only the way it is written.
             */
        ?>
        <div id="dlgConstraintRows" class="owa_builderConstraints"><ul></ul></div>
        <div class="owa_builderHelp">
            Rows are combined, e.g. <code>medium</code> is <code>organic-search</code>
            <em>and</em> <code>browserType</code> contains <code>Chrome</code>.
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
     * dimension -> the reports a row of it can lead to.
     *
     * Derived server-side from what each detail report declares it is
     * constrained on, so every destination offered here is one that will
     * actually read the value the link carries.
     */
    var LINK_TARGETS = <?php echo json_encode( (object) (array) $view->get('link_targets') ); ?>;

    /*
     * Where a "View Full Report" link can go, per dimension.
     *
     * The same rule as LINK_TARGETS minus the constraint: scoped to the
     * widget's dimension, but the destination must read no parameter, because
     * this link carries no value from the row.
     */
    var MORE_TARGETS = <?php echo json_encode( (object) (array) $view->get('more_targets') ); ?>;

    var MORE_LABEL = <?php echo json_encode( \OWA\Module\Base\Classes\CustomReports::MORE_LABEL ); ?>;

    /* The same icons the type chooser shows, for the pill in the modal. */
    var TYPE_ICONS = <?php echo json_encode( (object) \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPE_ICONS ); ?>;

    /*
     * The types whose link column is unambiguous.
     *
     * A card shows one dimension, so the column a link comes from is the one it
     * has. A full-width grid can show several and would need that choice asked
     * separately; a pie has slices rather than rows.
     */
    var LINKABLE_TYPES = [ 'grid-card' ];

    /*
     * dimension -> the value it is fixed to, for the types that do not choose.
     * A trend is a metric over time; that is what makes it a trend.
     */
    var FIXED_DIMENSIONS = <?php echo json_encode( (object) (array) $view->get('fixed_dimensions') ); ?>;

    /*
     * How many dimensions a fixed-dimension type may add beyond its first.
     *
     * A trend is always over a date -- that is what makes it a trend -- and may
     * be broken out by ONE other dimension, whose values become its lines.
     */
    var FIXED_DIMENSION_EXTRA = <?php echo json_encode( (object) (array) $view->get('fixed_dimension_extra') ); ?>;

    /*
     * The dimensions that measure time. A trend's axis is one of these, so none
     * of them can also be what it is broken out BY.
     */
    var TIME_DIMENSIONS = <?php echo json_encode( array_values( (array) $view->get('time_dimensions') ) ); ?>;

    var SINGLE_METRIC_TYPES = <?php echo json_encode( array_values( (array) $view->get('single_metric_types') ) ); ?>;

    /*
     * Types that show totals rather than a breakdown, so there is nothing to
     * group by. Unlike FIXED_DIMENSIONS this is only about the FORM: a
     * hand-written metric-boxes widget with dimensions is not refused, it is
     * simply not something this screen asks for.
     */
    var UNGROUPED_TYPES = [ 'metric-boxes' ];

    function fixedDimension( type ) {
        return Object.prototype.hasOwnProperty.call( FIXED_DIMENSIONS, type )
            ? FIXED_DIMENSIONS[ type ]
            : null;
    }

    /**
     * Whether the author picks any dimensions for this type at all.
     *
     * A trend does: its first is fixed to a date, but the one after it -- the
     * dimension whose values become its lines -- is the author's. Only a type
     * with nothing to group by has no picker.
     */
    function picksDimensions( type ) {

        if ( UNGROUPED_TYPES.indexOf( type ) !== -1 ) {

            return false;
        }

        return fixedDimension( type ) === null || ( FIXED_DIMENSION_EXTRA[ type ] || 0 ) > 0;
    }

    /** The type the dialog is open on. It cannot change while it is open. */
    function editingType() {
        return editing === null ? '' : widgets[ editing ].type;
    }

    /*
     * Types that draw one metric as a chart and need to be told which.
     *
     * Read from the server, like the lists above and for the same reason: a
     * hard-coded copy here is the one that does not get updated when a type is
     * added, and the symptom of that is a chart with no line rather than an
     * error anybody would notice.
     */
    var CHART_TYPES = <?php echo json_encode( array_values( (array) $view->get('chart_types') ) ); ?>;

    /*
     * Types that name their own metrics and cannot fall back to the report
     * metric set. Used to say so on the form -- an author who leaves the
     * metrics of a card empty is not going to inherit anything, they are going
     * to be refused on save.
     */
    var OWN_METRIC_TYPES = <?php echo json_encode( array_values( (array) $view->get('own_metric_types') ) ); ?>;

    function needsOwnMetrics( type ) {
        return OWN_METRIC_TYPES.indexOf( type ) !== -1;
    }

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

    /* Draws one metric, and cannot fall back to the report metric set for it. */
    function isSingleMetric( type ) {
        return SINGLE_METRIC_TYPES.indexOf( type ) !== -1;
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
        return isSingleMetric( editingType() ) ? 1 : MAX_METRICS;
    }

    /**
     * How many dimensions the AUTHOR picks.
     *
     * A fixed-dimension type does not pick its first one, so this is what it
     * may add beyond it: a trend picks the one whose values become its lines,
     * and nothing else.
     */
    function maxDimensions() {

        var type = editingType();

        if ( fixedDimension( type ) !== null ) {

            return FIXED_DIMENSION_EXTRA[ type ] || 0;
        }

        return isSingleField( type ) ? 1 : MAX_DIMENSIONS;
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
        widgets = [ newWidget( 0, 'grid' ) ];
    }

    var editing = null;   // index of the widget the dialog is open on

    /*
     * The report the widget's rows link to, as the dialog was opened.
     *
     * Held apart from the select because the select is rebuilt whenever the
     * dimension changes -- without this, changing the dimension and changing it
     * back would silently drop a link the author had set.
     */
    var dialogLink = '';

    /** ...and the same for the link below the widget, for the same reason. */
    var dialogMore = '';

    function newWidget( index, type ) {

        type = type || 'grid';

        var widget = {
            type: type,
            title: 'Widget ' + ( index + 1 ),
            rowspan: 1,
            query: {}
        };

        /*
         * A colspan only where the author has one to choose. A full-width type
         * records none, so the width it draws at stays ReportGrid's answer
         * rather than a number copied into every definition.
         */
        if ( ! isFullWidth( type ) ) {
            widget.colspan = defaultColspan( type );
        }

        var fixed = fixedDimension( type );

        if ( fixed !== null ) {
            widget.query.dimensions = fixed;
            widget.query.sort       = fixed;
        }

        return widget;
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

    /**
     * ...with the ones already CHOSEN first, in the order they were chosen.
     *
     * ORDER IS MEANING HERE, not presentation. The first metric is the one a
     * trend charts; the first dimension is what a grid is grouped by and what
     * a report is about. So the list has to come back in the author's order.
     *
     * It did not. The options were rebuilt in registry order on every change --
     * narrowMetrics() refills the select each time one is picked -- and
     * `$select.val()` answers in OPTION order, so choosing visits, then unique
     * visitors, then page views stored `pageViews,uniqueVisitors,visits` and
     * charted pageViews. The author's first choice became the last box, and
     * the one they picked last was drawn.
     *
     * Putting the selected block first fixes both halves at once: `.val()`
     * answers in that order, and chosen renders its pills from the options, so
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

            if ( axisIsTime && TIME_DIMENSIONS.indexOf( choice.name ) !== -1 ) {
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

        jQuery( '#dlgTypePill' )
            .empty()
            .append( jQuery( '<i aria-hidden="true">' )
                .attr( 'class', TYPE_ICONS[ widget.type ] || '' ) )
            .append( document.createTextNode( ' ' + ( TYPES[ widget.type ] || widget.type ) ) );

        dialogLink = String( ( ( widget.link || {} ).template || {} ).reportId || '' );
        dialogMore = String( ( widget.more || {} ).reportId || '' );

        fillRange( jQuery( '#dlgColspan' ), 1, COLUMNS, colspanOf( widget ) );
        fillRange( jQuery( '#dlgRowspan' ), 1, MAX_ROWSPAN, widget.rowspan || 1 );

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

        // The options were just replaced, so chosen has to be told before it
        // will show them -- and again after the dialog is open, because that is
        // when it can finally measure itself.
        chosenSync( '#dlgMetrics' );
        chosenSync( '#dlgDimensions' );

        // After the selections are loaded, not before: applyTypeRules() trims
        // to the type's cap, and run any earlier it would be trimming the
        // widget the dialog was open on last time.
        applyTypeRules();

        var more = widget.more || {};

        // Blank when it is the default, so the placeholder shows what the link
        // will say rather than the field repeating it back.
        jQuery( '#dlgMoreLabel' ).val(
            more.label && more.label !== MORE_LABEL ? more.label : '' );

        jQuery( '#dlgSort' ).val( query.sort || '' );
        renderConstraintRows( widget.constraints || '' );

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

        var type   = editingType();
        var single = isSingleField( type );
        var name   = TYPES[ type ] || type;


        jQuery( '#dlgColspanField' ).toggle( ! isFullWidth( type ) );

        jQuery( '#dlgWidthNote' )
            .toggle( isFullWidth( type ) )
            .text( name + ' is always full width, so it has room for '
                 + 'its own filter and dimension controls.' );

        /*
         * A metric SET, or one metric.
         *
         * A table or a row of boxes draws every metric it is given, and takes
         * the report's own set when it names none. A card ranks its rows by one
         * and a pie is a share of one, so neither can use a set at all -- which
         * is why the field is singular for them and says so.
         */
        var oneMetric = isSingleMetric( type );

        jQuery( '#dlgMetricsLabel' ).text( oneMetric ? 'Metric' : 'Metrics' );

        /*
         * "Leave empty to use the report metric set" is only true where it IS
         * an option. A type that names its own metrics has no fallback -- an
         * author who left the field empty on the strength of that sentence
         * would be refused on save, by the rule the sentence contradicted.
         */
        var setFallback = needsOwnMetrics( type )
            ? ' A ' + name.toLowerCase() + ' names its own; it does not take the report metric set.'
            : ' Leave empty to use the report metric set.';

        jQuery( '#dlgMetricsHelp' ).text( oneMetric
            ? 'The one metric this ' + name.toLowerCase() + ' draws.'
            : ( type === 'trend' || type === 'trend-card'
                ? 'Up to ' + MAX_METRICS + '. The first is charted; all of them are '
                  + 'drawn as boxes ' + ( type === 'trend-card' ? 'above' : 'under' ) + ' it.'
                  + setFallback
                : 'Up to ' + MAX_METRICS + '.' + setFallback ) );

        var fixed = fixedDimension( type );

        jQuery( '#dlgDimensionsField' ).toggle( picksDimensions( type ) );

        if ( fixed !== null ) {

            // A trend: the axis is settled, and what is offered is the
            // breakdown whose values become the lines.
            jQuery( '#dlgDimensionsLabel' ).text( 'Break out by' );

            jQuery( '#dlgDimensionsHelp' ).text(
                'Optional. Each value becomes its own line over the total; the '
              + 'six largest are drawn. '
              + name + ' is always over ' + fixed + '.' );

            /*
             * ...unless it may add none, in which case the field is hidden by
             * picksDimensions() above and this text is never read. Left alone
             * rather than branched: a card has nothing to say here.
             */

        } else {

            jQuery( '#dlgDimensionsLabel' ).text( single ? 'Dimension' : 'Dimensions' );

            jQuery( '#dlgDimensionsHelp' ).text( single
                ? 'The one dimension its rows are grouped by.'
                : 'Up to ' + MAX_DIMENSIONS + '. Each one is another column.' );
        }

        jQuery( '#dlgDimensionNote' )
            .toggle( ! picksDimensions( type ) )
            .text( name + ' shows totals for the period, so there is nothing to group by.' );

        // A sort orders rows, and these types have none to order.
        jQuery( '#dlgSortField' ).toggle( picksDimensions( type ) );

        narrowMetrics( '#dlgMetrics' );

        if ( picksDimensions( type ) ) {
            narrowDimensions();
        }

        refreshLinkControl();
        refreshMoreControl();
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
        $select.append( jQuery( '<option>' ).attr( 'value', '' ).text( 'Nothing — rows are not links' ) );

        targets.forEach( function ( target ) {

            $select.append( jQuery( '<option>' ).attr( 'value', target.id )
                .prop( 'selected', target.id === dialogLink )
                .text( target.label ) );
        } );

        jQuery( '#dlgLinkHelp' ).text(
            'Each ' + dimension + ' becomes a link to the report that details it.' );
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

        $select.append( jQuery( '<option>' ).attr( 'value', '' ).text( 'No link' ) );

        targets.forEach( function ( target ) {

            $select.append( jQuery( '<option>' ).attr( 'value', target.id )
                .prop( 'selected', target.id === dialogMore )
                .text( target.label ) );
        } );
    }

    /** Read the dialog back into the widget it was opened on. */
    /*
     * ------------------------------------------------------------------
     * Constraints, as rows rather than as a string the author types
     * ------------------------------------------------------------------
     *
     * The STORED format is unchanged -- `name==value`, comma separated, which
     * is what CustomReports::constraintDimensions() parses. These two functions
     * are the only place that string is written or read here, so the syntax
     * stops being something an author has to know.
     *
     * The markup deliberately matches the grid's filter builder
     * (li.constraintRow > .constraintDimensionPicker / .constraintOperatorPicker
     * / .constraintValueField), because the pill styling is written against
     * those classes and a filter should look the same wherever it is built.
     */
    var CONSTRAINT_OPERATORS = {
        '==': 'Exactly Matching',
        '!=': 'Not Matching',
        '>':  'Greater than',
        '<':  'Less than',
        '=@': 'Contains'
    };

    /**
     * Split one clause into name / operator / value.
     *
     * LONGEST OPERATOR FIRST. '=@' and '!=' both contain a character that '='
     * would match, and '>=' starts with '>' -- testing in any order but longest
     * first splits `medium=@news` into name `medium=` with operator `@`.
     */
    function splitConstraint( clause ) {

        var ops = Object.keys( CONSTRAINT_OPERATORS ).sort( function ( a, b ) {
            return b.length - a.length;
        } );

        for ( var i = 0; i < ops.length; i++ ) {

            var at = clause.indexOf( ops[ i ] );

            if ( at > 0 ) {

                return {
                    name:     jQuery.trim( clause.slice( 0, at ) ),
                    operator: ops[ i ],
                    value:    jQuery.trim( clause.slice( at + ops[ i ].length ) )
                };
            }
        }

        return { name: jQuery.trim( clause ), operator: '==', value: '' };
    }

    function addConstraintRow( name, operator, value, after ) {

        var $row = jQuery(
              '<li class="constraintRow">'
            + '<span class="constraintDimensionPicker"></span>'
            + '<span class="constraintOperatorPicker"></span>'
            + '<input class="constraintValueField" type="text" />'
            + '<span class="constraintAddButton" role="button" tabindex="0"'
            + ' title="Add another filter" aria-label="Add another filter">+</span>'
            + '<span class="constraintRemoveButton" role="button" tabindex="0"'
            + ' title="Remove this filter" aria-label="Remove this filter">X</span>'
            + '</li>' );

        var $dim = jQuery( '<select class="dim-list"></select>' )
            .append( jQuery( '<option value=""></option>' ).text( 'Select...' ) );

        /*
         * The same list the dimension slots offer, labelled the same way -- see
         * fillChoices(). DIMENSIONS is a LIST of { name, label }, not a
         * name => label map: iterating it as a map yields the array index and
         * the object, so every option came out `[object Object]` with a numeric
         * value.
         */
        DIMENSIONS.forEach( function ( choice ) {
            $dim.append( jQuery( '<option>' )
                .attr( 'value', choice.name )
                .text( choice.label + ' (' + choice.name + ')' ) );
        } );

        var $op = jQuery( '<select class="operator-list"></select>' );

        jQuery.each( CONSTRAINT_OPERATORS, function ( value, label ) {
            $op.append( jQuery( '<option></option>' ).attr( 'value', value ).text( label ) );
        } );

        $row.children( '.constraintDimensionPicker' ).append( $dim );
        $row.children( '.constraintOperatorPicker' ).append( $op );

        if ( name )     { $dim.val( name ); }
        if ( operator ) { $op.val( operator ); }
        if ( value )    { $row.children( '.constraintValueField' ).val( value ); }

        if ( after && after.length ) {

            // Directly below the row whose plus was pressed -- the rows read
            // top to bottom as one sentence.
            $row.insertAfter( after );

        } else {

            jQuery( '#dlgConstraintRows > ul' ).append( $row );
        }

        /*
         * Enhanced AFTER the row is in the document, and with explicit widths.
         *
         * chosen-js 1.x measures the <select> at enhancement time and reads 0
         * inside a hidden parent -- this dialog is display:none until it opens,
         * which is exactly the case that once left the grid filter's dimension
         * picker a 2px sliver. The width option bypasses the measurement.
         */
        $dim.chosen( { no_results_text: 'Name not found.', width: '160px' } );
        $op.chosen( { disable_search: true, width: '150px' } );

        var addAfter = function () { addConstraintRow( '', '', '', $row ); };

        $row.children( '.constraintAddButton' )
            .on( 'click', addAfter )
            .on( 'keydown', function ( e ) {
                if ( e.which === 13 || e.which === 32 ) { e.preventDefault(); addAfter(); }
            } );

        var remove = function () {

            $row.remove();

            // Never zero rows: the plus lives IN a row, so removing the last
            // one would leave nothing to add from.
            if ( ! jQuery( '#dlgConstraintRows > ul > li' ).length ) {
                addConstraintRow();
            }
        };

        $row.children( '.constraintRemoveButton' )
            .on( 'click', remove )
            .on( 'keydown', function ( e ) {
                if ( e.which === 13 || e.which === 32 ) { e.preventDefault(); remove(); }
            } );
    }

    function renderConstraintRows( str ) {

        jQuery( '#dlgConstraintRows > ul' ).empty();

        var clauses = String( str || '' ).split( ',' ).filter( function ( c ) {
            return jQuery.trim( c ) !== '';
        } );

        if ( ! clauses.length ) {

            addConstraintRow();
            return;
        }

        clauses.forEach( function ( clause ) {
            var part = splitConstraint( clause );
            addConstraintRow( part.name, part.operator, part.value );
        } );
    }

    /**
     * The rows, back as the stored string.
     *
     * A row with no dimension or no value contributes NOTHING -- it filters
     * nothing, and writing `==` into the definition would be a clause the
     * server then has to reject. That is also what lets the form always carry
     * one empty row without it meaning anything.
     */
    function readConstraintRows() {

        var out = [];

        jQuery( '#dlgConstraintRows > ul > li' ).each( function () {

            var name  = jQuery( this ).find( 'select.dim-list' ).val();
            var op    = jQuery( this ).find( 'select.operator-list' ).val();
            var value = jQuery.trim( jQuery( this ).children( '.constraintValueField' ).val() || '' );

            if ( name && value ) {
                out.push( name + ( op || '==' ) + value );
            }
        } );

        return out.join( ',' );
    }

    function applyDialog() {

        if ( editing === null ) {
            return;
        }

        var widget = widgets[ editing ];
        var query  = {};

        // The type is not read back: it was chosen when the widget was added
        // and the dialog was built for it.
        widget.title = jQuery( '#dlgTitle' ).val() || ( 'Widget ' + ( editing + 1 ) );

        if ( isFullWidth( widget.type ) ) {

            // Not the author's to choose, so nothing is recorded -- see
            // newWidget(). The width it draws at stays ReportGrid's answer.
            delete widget.colspan;

        } else {

            widget.colspan = Number( jQuery( '#dlgColspan' ).val() ) || defaultColspan( widget.type );
        }

        widget.rowspan = Number( jQuery( '#dlgRowspan' ).val() ) || 1;

        var metrics = jQuery( '#dlgMetrics' ).val() || [];
        var cons    = readConstraintRows();

        /*
         * The dimensions, from whichever of the three this type is.
         *
         * Fixed: written from the type, not from a control -- a trend is always
         * by date, and its picker is a sentence saying so.
         * Ungrouped: none at all; a row of totals has nothing to group by.
         * Otherwise: what the author picked.
         */
        var fixed  = fixedDimension( widget.type );
        var picked = picksDimensions( widget.type ) ? ( jQuery( '#dlgDimensions' ).val() || [] ) : [];

        /*
         * The axis first, then the breakdown. Order is not decoration: the
         * chart reads the first dimension as what it plots against and the
         * second as what it breaks out into lines.
         */
        var dimensions = fixed !== null ? [ fixed ].concat( picked ) : picked;

        // A sort orders rows. A fixed-dimension type orders by that dimension;
        // an ungrouped one has no rows to order.
        var sort = fixed !== null
            ? fixed
            : ( picksDimensions( widget.type )
                ? jQuery.trim( jQuery( '#dlgSort' ).val() || '' )
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

        if ( cons ) {
            widget.constraints = cons;
        } else {
            delete widget.constraints;
        }

        /*
         * A chart draws ONE metric, and the renderer reads which from
         * chartMetric rather than guessing at the first in the list.
         *
         * A pie needs this as much as a trend does, and did not have it: a pie
         * built here carried no chartMetric, so options.pieChart.metric came
         * out empty and the chart drew nothing at all.
         */
        if ( CHART_TYPES.indexOf( widget.type ) !== -1 && metrics.length ) {

            /*
             * ONE metric. A trend charts a single metric over time -- what
             * varies is the dimension it is broken out by, not the measure.
             */
            widget.chartMetric = metrics[0];

        } else {
            delete widget.chartMetric;
        }

        /*
         * Where a row leads.
         *
         * Everything except the destination is derived: a card shows one
         * dimension, so that is the column the link comes from and the value it
         * carries, and the parameter name is the one the destination declared
         * it is read under.
         */
        var linkTo    = jQuery( '#dlgLinkReport' ).val() || '';
        var linkable  = LINKABLE_TYPES.indexOf( widget.type ) !== -1 && dimensions.length === 1;
        var target    = linkable && ( LINK_TARGETS[ dimensions[0] ] || [] ).filter(
            function ( t ) { return t.id === linkTo; } )[0];

        if ( target ) {

            var template = { 'do': 'base.report', reportId: target.id };

            template[ target.param ] = '%s';

            widget.link = {
                linkColumn:   dimensions[0],
                template:     template,
                valueColumns: dimensions[0]
            };

        } else {

            // Including the case where the type or the dimension changed out
            // from under a link that was set -- it cannot be carried over.
            delete widget.link;
        }

        var moreTo    = jQuery( '#dlgMoreReport' ).val() || '';
        var moreLabel = jQuery.trim( jQuery( '#dlgMoreLabel' ).val() || '' );

        if ( moreTo ) {

            widget.more = { reportId: moreTo, label: moreLabel || MORE_LABEL };

        } else {

            delete widget.more;
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
        refreshLinkControl();
        refreshMoreControl();
    } );
    jQuery( '#dlgDimensions' ).on( 'change', function () {
        narrowDimensions();
        // Both link lists are per dimension, so they change with it.
        refreshLinkControl();
        refreshMoreControl();
    } );

    // Remembered, so rebuilding the select on a dimension change does not lose
    // a choice the author has already made.
    jQuery( '#dlgLinkReport' ).on( 'change', function () {
        dialogLink = jQuery( this ).val() || '';
    } );

    jQuery( '#dlgMoreReport' ).on( 'change', function () {
        dialogMore = jQuery( this ).val() || '';
    } );

    jQuery( '#reportMetricSet' ).on( 'change', function () { narrowMetrics( '#reportMetricSet' ); } );

    jQuery( '#typeDialog' ).dialog( {
        autoOpen: false,
        modal: true,
        /*
         * Wide enough for three tiles across. The choices are a gallery now --
         * see .owa_typeChoices -- and the point of a gallery is comparing
         * things side by side, which a 520px column could not do.
         */
        width: Math.min( 820, jQuery( window ).width() - 40 ),
        title: 'Add a widget',

        /*
         * KEPT INSIDE .owa, and this is not cosmetic.
         *
         * jQuery UI lifts a dialog to <body> by default. Every rule styling
         * what is inside these two modals is written `.owa .owa_...`, the way
         * the rest of the reporting stylesheet is -- so the moment the dialog
         * was lifted out of that wrapper, none of them matched. Both modals
         * have been rendering with browser defaults: the type chooser's grid
         * was a block, its tiles were bare buttons, and the widget form's rows
         * had no layout at all.
         *
         * appendTo keeps them where their stylesheet expects them. `.owa` is a
         * plain div with no transform, so it creates no containing block and
         * the dialog still positions against the window.
         */
        appendTo: '.owa',
        // Its OWN frame class. Sharing the widget modal's would make a locator
        // for that modal match this one too -- jQuery UI builds both frames at
        // init, so the chooser's is already in the page, hidden.
        dialogClass: 'owa_typeDialogFrame'
    } );

    jQuery( '#widgetDialog' ).dialog( {
        autoOpen: false,
        modal: true,
        // Inside .owa, for the reason given on the chooser above.
        appendTo: '.owa',
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

            // What kind, first. The widget modal is built for a type, so there
            // has to be one before it can be opened.
            jQuery( '#typeDialog' ).dialog( 'open' );
        } );

    jQuery( '#typeDialog' ).on( 'click', '.owa_typeChoice', function ( e ) {

        e.preventDefault();

        if ( widgets.length >= MAX ) {
            return;
        }

        widgets.push( newWidget( widgets.length, jQuery( this ).attr( 'data-type' ) ) );

        draw();

        jQuery( '#typeDialog' ).dialog( 'close' );

        // Straight into configuring it: the type was a question about what to
        // build, not a step of its own.
        openDialog( widgets.length - 1 );
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
