<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * The custom report editor.
 *
 * The layout shows the report as CARDS on its twelve columns, in the order
 * they will be drawn, each as wide as it will be -- so the arrangement on this
 * screen is the arrangement on the report. Add a widget asks what kind; a
 * card's pencil opens a panel at the right holding everything about that one
 * widget, and its handle moves it.
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
$owa_is_shared  = (bool) $view->get('custom_report_is_shared');
$owa_error      = (string) $view->get('custom_report_error');
$owa_types      = (array) $view->get('widget_types');
$owa_max        = (int) $view->get('max_widgets');
?>

<?php if ( $owa_error ): ?>
<div class="notice error" role="alert"><?php $view->out( $owa_error ); ?></div>
<?php endif; ?>

<form id="customReportForm" class="owa_editor" action="<?php echo $view->makeLink( array( 'do' => 'base.customReportSave' ) ); ?>" method="post">

    <?php echo $view->createNonceFormField( 'base.customReportSave' ); ?>

    <input type="hidden" name="customReportId" value="<?php $view->out( $owa_id ); ?>" />

    <?php
        /*
         * The Property travels through the POST so the saved report's URL names
         * one. Without it the author lands on -- and shares -- a link that
         * opens on whichever Property its reader looked at last.
         */
    ?>
    <input type="hidden" name="propertyId" value="<?php $view->out( $view->get('propertyId') ); ?>" />

    <?php /* The assembled definition. Written by the script below on submit. */ ?>
    <input type="hidden" name="customReportDefinition" id="customReportDefinition" value="" />

    <?php
        /*
         * The screen's header: the report's name, whether it is listed for
         * everyone, and Cancel / Save. There is no page title above it; the
         * name is the title.
         */
    ?>
    <div class="owa_editorHead">
        <div class="owa_editorName">
            <?php
                /*
                 * MARKED REQUIRED, because the placeholder makes an empty one
                 * look filled in -- "Untitled report" greyed out reads as a
                 * default already supplied, and Save with it untouched was the
                 * most ordinary mistake there is.
                 */
            ?>
            <div class="owa_builderFieldLabel">
                <label for="customReportName">Report name</label>
                <span class="owa_builderRequired">Required</span>
            </div>
            <input type="text" id="customReportName" name="customReportName"
                   placeholder="Untitled report"
                   value="<?php $view->out( $owa_name ); ?>" />
        </div>

        <?php
            /*
             * Listing, not access. Anyone with view_reports can already open
             * this report from its link; this decides whose LIST it is on.
             */
        ?>
        <label class="owa_switch" for="isShared">
            <input type="checkbox" role="switch" id="isShared" name="isShared" value="1"
                   aria-describedby="isSharedHint"
                   <?php echo $owa_is_shared ? 'checked="checked"' : ''; ?> />
            <span class="owa_switchTrack" aria-hidden="true"></span>
            Shared in everyone's list
        </label>
        <span id="isSharedHint" class="owa_srOnly">Anyone you send the link to can open it either way.</span>

        <div class="owa_editorActions">
            <a class="owa_button" id="customReportCancel" href="<?php echo $owa_id
                ? $view->makeLink( array( 'do' => 'base.report', 'reportId' => 'custom-' . $owa_id ), true )
                : $view->makeLink( array( 'do' => 'base.customReports' ), true ); ?>">Cancel</a>
            <?php
                /*
                 * Disabled in the MARKUP as well as by draw(), so a new report
                 * has no window before the script runs in which Save posts an
                 * empty definition.
                 */
            ?>
            <input type="submit" id="customReportSubmit" class="owa_button is-primary" value="Save report"
                   <?php echo empty( $owa_definition['widgets'] ) ? 'disabled="disabled"' : ''; ?> />
        </div>
    </div>

    <?php
        /*
         * WHY Save will not go, under the button that will not go. Written by
         * refreshSaveState() from the same rules the server applies.
         */
    ?>
    <p id="customReportBlocker" class="owa_builderBlocker" role="status" style="display:none;"></p>

    <div class="owa_editorSectionHead">
        <h2>Layout</h2>
        <span class="owa_builderBudget" id="widgetBudget"></span>
    </div>

    <?php
        /*
         * Shown only while the canvas is empty, which for a NEW report is what
         * it opens as. Server-rendered so its words live with the rest of them.
         */
    ?>
    <div id="customReportEmpty" class="owa_builderEmpty" style="display:none;">
        <span class="owa_builderEmptyTitle">Nothing in this report yet.</span>
        <span class="owa_builderEmptyBody">Add a table, a chart, or a row of totals.
            A report cannot be saved empty.</span>
    </div>

    <?php
        /*
         * The layout: a card per widget on the report's twelve columns, in the
         * order the report draws them, each as wide as it will be. Drag a card
         * by its handle, or focus the handle and use the arrow keys, to move it.
         */
    ?>
    <div id="customReportCanvas" class="owa_builderCanvas"></div>
    <p id="customReportMoved" class="owa_srOnly" aria-live="polite"></p>

    <?php if ( $owa_id ): ?>
    <div class="owa_editorFoot">
        <?php // 5th arg = $add_nonce: base.customReportDelete is setNonceRequired(). ?>
        <a class="owa_builderDelete" href="<?php echo $view->makeLink( array(
            'do'             => 'base.customReportDelete',
            'customReportId' => $owa_id,
        ), false, '', false, true ); ?>"
           data-owa-confirm
           data-owa-confirm-title="Delete this report?"
           data-owa-confirm-body="This is final. Unlike a Profile, a custom report is not archived."
           data-owa-confirm-proceed="Delete report">Delete this report</a>
    </div>
    <?php endif; ?>
</form>

<?php
    /*
     * What kind of widget, asked FIRST.
     *
     * The type decides what the rest of the questions are -- a trend is always
     * by date and has no dimension to pick, a card takes one metric where a
     * table takes four, only a card's rows can be links. Asked here, the panel
     * is built for a type that is already known, and a widget's type is fixed
     * once it is added: to change it, remove the card and add the kind you
     * meant.
     */
?>
<div id="typeDialog" class="owa_typeDialog" style="display:none;">
    <ul class="owa_typeChoices owa_typeChoicesTwo">
    <?php foreach ( $owa_types as $owa_key => $owa_label ): ?>
        <li>
            <button type="button" class="owa_typeChoice" data-type="<?php $view->out( $owa_key ); ?>">
                <?php // Decorative: the name beside it says the same thing. ?>
                <span class="owa_typeChoiceArt" aria-hidden="true">
                    <i class="owa_typeChoiceIcon <?php $view->out(
                        \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPE_ICONS[ $owa_key ] ?? '' ); ?>"></i>
                </span>
                <span class="owa_typeChoiceText">
                    <span class="owa_typeChoiceName"><?php $view->out( $owa_label ); ?></span>
                    <span class="owa_typeChoiceHint"><?php
                        $view->out( \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPE_HINTS[ $owa_key ] ?? '' ); ?></span>
                </span>
            </button>
        </li>
    <?php endforeach; ?>
    </ul>
</div>

<?php
    /*
     * One widget's settings, in a panel at the right of the screen, opened by
     * a card's pencil or by choosing a type. The layout stays in view beside
     * it, with the card being edited marked.
     */
?>
<aside id="widgetDialog" class="owa_widgetPanel" role="dialog" aria-labelledby="dlgHeading">

    <div class="owa_widgetPanelHead">
        <div>
            <div class="owa_widgetPanelKind" id="dlgTypePill"></div>
            <h2 id="dlgHeading">Widget settings</h2>
        </div>
        <button type="button" class="owa_iconButton" id="dlgClose" aria-label="Close" title="Close"><i class="fas fa-times" aria-hidden="true"></i></button>
    </div>

    <div class="owa_widgetPanelBody">

        <div class="owa_builderField">
            <label for="dlgTitle">Name</label>
            <input type="text" id="dlgTitle" />
        </div>

        <div class="owa_builderFieldRow">
            <?php // Filled by renderSpanChoices(), which adds the widget's own width if it is not one of these. ?>
            <fieldset class="owa_builderField" id="dlgColspanField">
                <legend>Width</legend>
                <div class="owa_seg" id="dlgWidth"></div>
            </fieldset>

            <div class="owa_builderField owa_builderNote" id="dlgWidthNote" style="display:none;"></div>

            <fieldset class="owa_builderField">
                <legend>Height</legend>
                <div class="owa_seg" id="dlgHeight"></div>
            </fieldset>
        </div>

        <div class="owa_builderField" id="dlgMetricsField">
            <div class="owa_builderFieldLabel">
                <label for="dlgMetrics" id="dlgMetricsLabel">Metrics</label>
                <span class="owa_builderRequired" id="dlgMetricsRequired">Required</span>
            </div>
            <select id="dlgMetrics" class="owa_builderChosen" multiple="multiple"></select>
            <div class="owa_builderHelp" id="dlgMetricsHelp"></div>
        </div>

        <div class="owa_builderField" id="dlgDimensionsField">
            <div class="owa_builderFieldLabel">
                <label for="dlgDimensions" id="dlgDimensionsLabel">Dimensions</label>
                <span class="owa_builderRequired" id="dlgDimensionsRequired">Required</span>
            </div>
            <select id="dlgDimensions" class="owa_builderChosen" multiple="multiple"></select>
            <div class="owa_builderHelp" id="dlgDimensionsHelp"></div>
        </div>

        <?php /* ...or the sentence saying the type has already decided. */ ?>
        <div class="owa_builderField owa_builderNote" id="dlgDimensionNote" style="display:none;"></div>

        <?php
            /*
             * What the rows are ordered by, from the metrics and dimensions the
             * widget shows, and which way.
             */
        ?>
        <div class="owa_builderField" id="dlgSortField">
            <label for="dlgSortBy">Sort</label>
            <div class="owa_builderSort">
                <select id="dlgSortBy"></select>
                <select id="dlgSortDir" aria-label="Sort direction"></select>
            </div>
        </div>

        <?php
            /*
             * Filters as pills. Filter opens a small form under the pills;
             * Add filter turns it into a pill, and a pill's X removes it.
             * Every pill applies: they are combined with "and".
             */
        ?>
        <div class="owa_builderField" id="dlgFiltersField">
            <span class="owa_builderLabel" id="dlgFiltersLabel">Filters</span>
            <div class="owa_filterPills" id="dlgFilters" role="group" aria-labelledby="dlgFiltersLabel">
                <button type="button" class="owa_button owa_filterAdd" id="dlgAddFilter" aria-expanded="false"
                        aria-controls="dlgFilterForm"><i class="fas fa-filter" aria-hidden="true"></i> Filter</button>
            </div>
            <div class="owa_filterForm" id="dlgFilterForm" style="display:none;">
                <label for="dlgFilterDimension" class="owa_srOnly">Dimension</label>
                <select id="dlgFilterDimension"></select>
                <label for="dlgFilterOperator" class="owa_srOnly">Condition</label>
                <select id="dlgFilterOperator"></select>
                <label for="dlgFilterValue" class="owa_srOnly">Value</label>
                <input type="text" id="dlgFilterValue" placeholder="Value" />
                <div class="owa_filterFormActions">
                    <button type="button" class="owa_button" id="dlgFilterCancel">Cancel</button>
                    <button type="button" class="owa_button is-primary" id="dlgFilterAdd" disabled="disabled">Add filter</button>
                </div>
            </div>
        </div>

        <?php
            /*
             * Where a card's rows go when clicked. Only a card: its single
             * dimension is the unambiguous link column. Hidden when the
             * dimension has no detail report.
             */
        ?>
        <div class="owa_builderField" id="dlgLinkField" style="display:none;">
            <label for="dlgLinkReport">Rows link to</label>
            <select id="dlgLinkReport"></select>
            <div class="owa_builderHelp" id="dlgLinkHelp"></div>
        </div>

        <?php
            /*
             * A link from the widget to the built-in report that shows the
             * whole thing, offered where one of its dimensions has such a report.
             */
        ?>
        <div class="owa_builderFieldRow" id="dlgMoreField" style="display:none;">
            <div class="owa_builderField">
                <label for="dlgMoreReport">Full report link</label>
                <select id="dlgMoreReport"></select>
                <div class="owa_builderHelp">Shown beside the widget's name.</div>
            </div>

            <div class="owa_builderField">
                <label for="dlgMoreLabel">Link text</label>
                <input type="text" id="dlgMoreLabel" placeholder="<?php $view->out( \OWA\Module\Base\Classes\CustomReports::MORE_LABEL ); ?>" />
            </div>
        </div>

        <div id="dlgError" class="owa_builderRowError" style="display:none;" role="alert"></div>
    </div>

    <div class="owa_widgetPanelFoot">
        <button type="button" class="owa_button" id="dlgCancel">Cancel</button>
        <button type="button" class="owa_button is-primary" id="dlgApply">Apply</button>
    </div>
</aside>

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

    // The width a NEW widget of each type is added at; see CustomReports::NEW_WIDGET_COLSPANS.
    var NEW_COLSPANS = <?php echo json_encode( (object) \OWA\Module\Base\Classes\CustomReports::NEW_WIDGET_COLSPANS ); ?>;

    // The widths and heights the panel offers, and how a card says them.
    var WIDTHS       = [ [ 4, 'Third' ], [ 6, 'Half' ], [ 8, 'Two thirds' ], [ 12, 'Full' ] ];
    var HEIGHTS      = [ [ 1, 'Short' ], [ 2, 'Tall' ] ];
    var WIDTH_WORDS  = { 12: 'Full width', 8: 'Two thirds', 6: 'Half width', 4: 'One third', 3: 'One quarter' };
    var HEIGHT_WORDS = { 1: 'short', 2: 'tall' };

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
     * separately; a share chart's Other row is no one value to link to.
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

    /** The type the panel is open on. It cannot change while it is open. */
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
     * OWN_METRIC_TYPES used to be read here, to say on the form which types
     * could not fall back to the report metric set. Nothing falls back any
     * more -- every widget names its own -- so the distinction has nothing left
     * to tell an author, and the list is not sent. The constant still exists
     * server-side, where it is what makes the refusal for those types say the
     * more precise thing.
     */

    // The grid the report is drawn on, as Core\ReportGrid has it.
    var COLUMNS = <?php echo (int) $view->get('grid_columns') ?: 12; ?>;

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
     * The caps for the type the panel is open on.
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
     * redraw reads from here and the panel writes to here.
     */
    var widgets = ( definition && definition.widgets ) ? definition.widgets.slice() : [];

    /*
     * A NEW REPORT STARTS EMPTY.
     *
     * It used to start from one table block, so the canvas was never blank --
     * and the cost of that was a report you could save without having decided
     * anything. The block was already named, already a table, already the right
     * width; pressing Save produced a report with a table of nothing in it, and
     * the author had answered no question to get there.
     *
     * Empty, the first thing on the screen is the choice that actually starts a
     * report: what kind of widget. Save is refused until one exists, here and
     * on the server both.
     */

    /*
     * The report-level metric set, as it was stored.
     *
     * Not editable any more -- see the note where the control used to be -- but
     * carried so that saving a report built before it was withdrawn does not
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
    var dialogFilters  = [];

    // The widget whose pencil opened the panel, to go back to when it closes.
    // The widget rather than the button: closing redraws the cards.
    var returnFocus = null;

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
            widget.colspan = NEW_COLSPANS[ type ] || defaultColspan( type );
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
    function narrowMetrics() {

        var $select  = jQuery( '#dlgMetrics' );
        var selected = $select.val() || [];

        // The widget's own cap, which depends on its type. It used to also
        // serve the report metric set, whose cap was MAX_METRICS flat; that
        // control is gone, so there is one caller and one cap.
        var full = selected.length >= maxMetrics();

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

    // ------------------------------------------------------------------
    // The canvas
    // ------------------------------------------------------------------

    function draw() {

        var $canvas = jQuery( '#customReportCanvas' ).empty();

        widgets.forEach( function ( widget, i ) {

            var colspan = colspanOf( widget );
            var rowspan = Number( widget.rowspan ) || 1;
            var title   = widget.title || ( 'Widget ' + ( i + 1 ) );

            var $block = jQuery( '<div class="owa_builderBlock" draggable="true">' )
                .attr( 'data-index', i )
                // As wide as the span it claims, so the canvas reads as the
                // layout rather than as a list.
                .addClass( 'owa_builderSpan-' + colspan )
                .toggleClass( 'is-editing', editing === i );

            $block.append( jQuery( '<div class="owa_builderBlockKind">' )
                .append( jQuery( '<button type="button" class="owa_builderMove">' )
                    .attr( { 'aria-label': 'Move ' + title, title: 'Drag to move, or use the arrow keys' } )
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
                    ( WIDTH_WORDS[ colspan ] || ( colspan + ' of ' + COLUMNS ) )
                    + ' · ' + ( HEIGHT_WORDS[ rowspan ] || ( rowspan + ' rows tall' ) ) ) )
                .append( jQuery( '<span class="owa_builderBlockTools">' )
                    .append( jQuery( '<button type="button" class="owa_iconButton owa_builderEdit">' )
                        .attr( { 'aria-label': 'Edit ' + title, title: 'Edit' } )
                        .append( '<i class="fas fa-pencil-alt" aria-hidden="true"></i>' ) )
                    .append( jQuery( '<button type="button" class="owa_iconButton owa_builderRemove">' )
                        .attr( { 'aria-label': 'Remove ' + title, title: 'Remove' } )
                        .append( '<i class="fas fa-times" aria-hidden="true"></i>' ) ) ) );

            $canvas.append( $block );
        } );

        if ( widgets.length < MAX ) {

            $canvas.append(
                jQuery( '<button type="button" id="addWidget" class="owa_builderAdd">' )
                    .append( '<i class="fas fa-plus" aria-hidden="true"></i>' )
                    .append( jQuery( '<span>' ).text( 'Add a widget' ) ) );
        }

        $canvas.toggleClass( 'owa_builderCanvasEmpty', ! widgets.length );

        jQuery( '#customReportEmpty' ).toggle( ! widgets.length );

        jQuery( '#widgetBudget' ).text( widgets.length + ' of ' + MAX + ' widgets'
            + ( widgets.length > 1 ? ' · drag to reorder' : '' ) );

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
            return 'No metrics chosen yet';
        }

        var parts = [ metrics.join( ', ' ) + ( dims.length ? ' by ' + dims.join( ', ' ) : '' ) ];

        var filters = parseFilters( widget.constraints || '' ).map( filterWords );

        if ( filters.length ) {
            parts.push( 'filtered: ' + filters.join( ', ' ) );
        }

        var linkTo = ( ( widget.link || {} ).template || {} ).reportId;

        if ( linkTo ) {

            var target = ( LINK_TARGETS[ names( query.dimensions )[0] ] || [] ).filter(
                function ( t ) { return t.id === linkTo; } )[0];

            parts.push( 'rows link to ' + ( target ? target.label : linkTo ) );
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
            ( moved.title || 'Widget' ) + ', position ' + ( to + 1 ) + ' of ' + widgets.length + '.' );

        return true;
    }

    /**
     * Why this report cannot be saved yet, or '' if it can.
     *
     * The same rules the server applies, checked before the round trip rather
     * than instead of it: CustomReports::validate() and the save controller's
     * validate() still refuse all of these.
     *
     * Ordered as an author would fix them: a widget, a name, then each widget's
     * metrics.
     */
    function saveBlocker() {

        if ( ! widgets.length ) {
            return 'Add at least one widget before saving.';
        }

        /*
         * Required by CustomReportSave::validate(). The field's "Untitled
         * report" placeholder makes an empty one look filled in.
         */
        if ( ! jQuery.trim( jQuery( '#customReportName' ).val() || '' ) ) {
            return 'Give the report a name before saving.';
        }

        /*
         * ...and every widget names its own metrics.
         *
         * Unless the report carries a metric set from before that control was
         * withdrawn, which is the one case the server still lets inherit -- the
         * builder has to allow exactly what the server allows, or it refuses an
         * old report its author cannot fix.
         */
        if ( ! reportMetrics ) {

            for ( var i = 0; i < widgets.length; i++ ) {

                if ( ! widgetMetrics( widgets[ i ] ).length ) {

                    return ( widgets[ i ].title || ( 'Widget ' + ( i + 1 ) ) )
                         + ' has no metrics.';
                }
            }
        }

        return '';
    }

    /** The metric names one widget asks for. */
    function widgetMetrics( widget ) {

        return names( widget && widget.query && widget.query.metrics );
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

        jQuery( '#dlgTitle' ).val( widget.title || ( 'Widget ' + ( index + 1 ) ) );

        jQuery( '#dlgTypePill' )
            .empty()
            .append( jQuery( '<i aria-hidden="true">' )
                .attr( 'class', TYPE_ICONS[ widget.type ] || '' ) )
            .append( document.createTextNode( ' ' + ( TYPES[ widget.type ] || widget.type ) ) );

        dialogLink = String( ( ( widget.link || {} ).template || {} ).reportId || '' );
        dialogMore = String( ( widget.more || {} ).reportId || '' );

        dialogColspan = colspanOf( widget );
        dialogRowspan = Number( widget.rowspan ) || 1;
        renderSpanChoices();

        var sort = String( query.sort || '' );

        dialogSortDesc = /-$/.test( sort );
        dialogSort     = sort.replace( /-$/, '' );
        sortTouched    = false;

        dialogFilters = parseFilters( widget.constraints || '' );
        closeFilterForm();
        renderFilters();

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
        closeFilterForm();

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
                    ? ( WIDTH_WORDS[ current ] || ( current + ' of ' + COLUMNS ) )
                    : ( current + ' rows' ) ] );
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
         * THERE IS NO LONGER ANYTHING TO LEAVE THIS EMPTY FOR.
         *
         * The sentence here used to be "Leave empty to use the report metric
         * set" on every type that could inherit one, and a contradiction of it
         * on the types that could not. With the set withdrawn from the builder
         * the first half is an offer nothing fulfils -- an author who took it
         * would be refused on save -- so both halves go and the field says the
         * one thing that is now true of every type: it has to be answered.
         */
        var required = ' Required.';

        /*
         * ...and the mark beside the label says the same thing at a glance.
         *
         * Metrics are required on every type UNLESS the report carries a metric
         * set from before that control was withdrawn -- the one case the server
         * still lets a widget inherit. Marking it required there would be
         * telling an author to fill a field they can legitimately leave alone.
         */
        jQuery( '#dlgMetricsRequired' ).toggle( ! reportMetrics );

        jQuery( '#dlgMetricsHelp' ).text( oneMetric
            ? 'The one metric this ' + name.toLowerCase() + ' draws.' + required
            : ( type === 'trend' || type === 'trend-card'
                ? 'Up to ' + MAX_METRICS + '. The first is charted; all of them are '
                  + 'drawn as boxes ' + ( type === 'trend-card' ? 'above' : 'under' ) + ' it.'
                  + required
                : 'Up to ' + MAX_METRICS + '.' + required ) );

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

    /*
     * ------------------------------------------------------------------
     * Filters, as pills
     * ------------------------------------------------------------------
     *
     * The STORED format is unchanged -- `name==value`, comma separated, which
     * is what CustomReports::constraintDimensions() parses. parseFilters() and
     * filtersString() are the only place that string is read or written here,
     * so the syntax is not something an author has to know.
     */
    var CONSTRAINT_OPERATORS = {
        '==': 'is',
        '!=': 'is not',
        '=@': 'contains',
        '>':  'is greater than',
        '<':  'is less than'
    };

    /*
     * The two tests on "(not set)", which the server reads as empty and has a
     * value. Stored as the operator plus that value; offered as their own
     * conditions, with no value to type.
     */
    var EMPTY_TESTS = {
        '!=(not set)': 'is set',
        '==(not set)': 'is not set'
    };

    /** One stored clause as { name, op, value }. */
    function splitConstraint( clause ) {

        // Longest first, so `!=` is not read as `=`.
        var ops = Object.keys( CONSTRAINT_OPERATORS ).sort( function ( a, b ) {
            return b.length - a.length;
        } );

        for ( var i = 0; i < ops.length; i++ ) {

            var at = clause.indexOf( ops[ i ] );

            if ( at > 0 ) {

                var part = {
                    name:  jQuery.trim( clause.slice( 0, at ) ),
                    op:    ops[ i ],
                    value: jQuery.trim( clause.slice( at + ops[ i ].length ) )
                };

                if ( EMPTY_TESTS.hasOwnProperty( part.op + part.value ) ) {
                    part.op += part.value;
                    part.value = '';
                }

                return part;
            }
        }

        return { name: jQuery.trim( clause ), op: '==', value: '' };
    }

    function parseFilters( str ) {

        return String( str || '' ).split( ',' )
            .filter( function ( c ) { return jQuery.trim( c ) !== ''; } )
            .map( splitConstraint );
    }

    function filtersString( filters ) {

        return filters.map( function ( f ) {
            return f.name + f.op + ( EMPTY_TESTS.hasOwnProperty( f.op ) ? '' : f.value );
        } ).join( ',' );
    }

    /** "Page path contains /blog/" */
    function filterWords( f ) {

        var words = CONSTRAINT_OPERATORS[ f.op ] || EMPTY_TESTS[ f.op ] || f.op;

        return labelOf( f.name ) + ' ' + words + ( EMPTY_TESTS.hasOwnProperty( f.op ) ? '' : ' ' + f.value );
    }

    function renderFilters() {

        var $holder = jQuery( '#dlgFilters' );

        $holder.children( '.owa_filterPill' ).remove();

        dialogFilters.forEach( function ( f, i ) {

            var $pill = jQuery( '<span class="owa_filterPill">' ).attr( 'data-index', i )
                .append( jQuery( '<span>' ).text( labelOf( f.name ) + ' '
                    + ( CONSTRAINT_OPERATORS[ f.op ] || EMPTY_TESTS[ f.op ] || f.op ) + ' ' ) );

            if ( ! EMPTY_TESTS.hasOwnProperty( f.op ) ) {
                $pill.append( jQuery( '<strong>' ).text( f.value ) );
            }

            $pill.append( jQuery( '<button type="button" class="owa_filterPillRemove">' )
                .attr( { 'aria-label': 'Remove the filter ' + filterWords( f ), title: 'Remove this filter' } )
                .append( '<i class="fas fa-times" aria-hidden="true"></i>' ) );

            $holder.append( $pill );
        } );
    }

    function openFilterForm() {

        var $dim = jQuery( '#dlgFilterDimension' );

        fillChoices( $dim, DIMENSIONS, [] );
        $dim.prepend( jQuery( '<option value="">' ).text( 'Choose a dimension' ) ).val( '' );
        chosenSync( '#dlgFilterDimension' );

        jQuery( '#dlgFilterOperator' ).val( '==' );
        jQuery( '#dlgFilterValue' ).val( '' );

        jQuery( '#dlgFilterForm' ).show();
        jQuery( '#dlgAddFilter' ).attr( 'aria-expanded', 'true' );

        syncFilterForm();

        jQuery( '#dlgFilterDimension' ).trigger( 'chosen:activate' );
    }

    function closeFilterForm() {

        jQuery( '#dlgFilterForm' ).hide();
        jQuery( '#dlgAddFilter' ).attr( 'aria-expanded', 'false' );
    }

    function filterFormOpen() {
        return jQuery( '#dlgFilterForm' ).is( ':visible' );
    }

    /** The filter being written, or null if it is not finished. */
    function readFilterForm() {

        var f = {
            name:  jQuery( '#dlgFilterDimension' ).val() || '',
            op:    jQuery( '#dlgFilterOperator' ).val() || '==',
            value: jQuery.trim( jQuery( '#dlgFilterValue' ).val() || '' )
        };

        if ( ! f.name || ( ! f.value && ! EMPTY_TESTS.hasOwnProperty( f.op ) ) ) {
            return null;
        }

        // A comma would split the stored list into two clauses.
        if ( f.value.indexOf( ',' ) !== -1 ) {
            return null;
        }

        return f;
    }

    function syncFilterForm() {

        var empty = EMPTY_TESTS.hasOwnProperty( jQuery( '#dlgFilterOperator' ).val() );

        jQuery( '#dlgFilterValue' ).toggle( ! empty );
        jQuery( '#dlgFilterAdd' ).prop( 'disabled', ! readFilterForm() );
    }

    /**
     * Why Apply cannot go because of a filter: one half written. The stored
     * list cannot hold a comma in a value, so that is refused too.
     */
    function filterProblem() {

        if ( ! filterFormOpen() ) {
            return '';
        }

        if ( jQuery( '#dlgFilterValue' ).val().indexOf( ',' ) !== -1 ) {
            return 'A filter value cannot contain a comma.';
        }

        return 'Add the filter you started, or cancel it.';
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
            .append( jQuery( '<option value="">' ).text( 'Unsorted' ) );

        options.forEach( function ( name ) {
            $by.append( jQuery( '<option>' ).attr( 'value', name ).text( labelOf( name ) ) );
        } );

        $by.val( dialogSort );

        var isDimension = dialogSort !== '' && metrics.indexOf( dialogSort ) === -1
            && DIMENSIONS.some( function ( c ) { return c.name === dialogSort; } );

        jQuery( '#dlgSortDir' ).empty()
            .append( jQuery( '<option value="desc">' ).text( isDimension ? 'Z to A' : 'Highest first' ) )
            .append( jQuery( '<option value="asc">' ).text( isDimension ? 'A to Z' : 'Lowest first' ) )
            .val( dialogSortDesc ? 'desc' : 'asc' )
            .prop( 'disabled', dialogSort === '' );

        jQuery( '#dlgSortField' ).toggle( picksDimensions( type ) && fixedDimension( type ) === null );
    }

    /**
     * EVERYTHING WRONG WITH THIS WIDGET, or '' if it is ready.
     *
     * Apply used to close on anything: the block landed on the canvas marked
     * unfinished and Save reported it, which meant finding the widget again
     * two screens later. Checked here, the fields are still open.
     *
     * The canvas marking and the Save gate remain for definitions that arrive
     * already broken, from a report stored before these rules or from a
     * refusal round-trip.
     *
     * Ordered as the fields appear in the form.
     *
     * @return string
     */
    function dialogProblem() {

        var type = editingType();

        /*
         * Metrics, unless the report carries a set from before that control was
         * withdrawn -- the one case the server still lets a widget inherit.
         */
        if ( ! reportMetrics && ! ( jQuery( '#dlgMetrics' ).val() || [] ).length ) {

            return isSingleMetric( type ) ? 'Choose a metric.'
                                          : 'Choose at least one metric.';
        }

        /*
         * A dimension, on the types that draw exactly one and render nothing
         * without it. SINGLE_FIELD_TYPES is the server's list, so this cannot
         * come to disagree with the rule that enforces it.
         */
        if ( isSingleField( type ) && picksDimensions( type )
             && ! ( jQuery( '#dlgDimensions' ).val() || [] ).length ) {

            return 'Choose a dimension.';
        }

        return filterProblem();
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

        var widget = widgets[ editing ];
        var query  = {};

        // The type is not read back: it was chosen when the widget was added
        // and the panel was built for it.
        widget.title = jQuery( '#dlgTitle' ).val() || ( 'Widget ' + ( editing + 1 ) );

        if ( isFullWidth( widget.type ) ) {

            // Not the author's to choose, so nothing is recorded -- see
            // newWidget(). The width it draws at stays ReportGrid's answer.
            delete widget.colspan;

        } else {

            widget.colspan = dialogColspan || defaultColspan( widget.type );
        }

        widget.rowspan = dialogRowspan || 1;

        var metrics = jQuery( '#dlgMetrics' ).val() || [];
        var cons    = filtersString( dialogFilters );

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
            : ( picksDimensions( widget.type ) && dialogSort
                ? dialogSort + ( dialogSortDesc ? '-' : '' )
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

        editing    = null;
        pendingNew = null;

        draw();

        return true;
    }

    chosenify( '#dlgMetrics' );
    chosenify( '#dlgDimensions' );

    jQuery( '#dlgFilterDimension' ).chosen( {
        width: '100%',
        no_results_text: 'Name not found.',
        placeholder_text_single: 'Choose a dimension'
    } );

    jQuery.each( jQuery.extend( {}, CONSTRAINT_OPERATORS, EMPTY_TESTS ), function ( value, label ) {
        jQuery( '#dlgFilterOperator' ).append( jQuery( '<option>' ).attr( 'value', value ).text( label ) );
    } );

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

    // Filters.
    jQuery( '#dlgAddFilter' ).on( 'click', function () {
        if ( filterFormOpen() ) {
            closeFilterForm();
        } else {
            openFilterForm();
        }
        refreshDialogState();
    } );

    jQuery( '#dlgFilterDimension, #dlgFilterOperator' ).on( 'change', syncFilterForm );
    jQuery( '#dlgFilterValue' ).on( 'input', function () {
        syncFilterForm();
        refreshDialogState();
    } );

    jQuery( '#dlgFilterValue' ).on( 'keydown', function ( e ) {
        if ( e.which === 13 ) {
            e.preventDefault();
            jQuery( '#dlgFilterAdd' ).trigger( 'click' );
        }
    } );

    jQuery( '#dlgFilterAdd' ).on( 'click', function () {

        var f = readFilterForm();

        if ( ! f ) {
            return;
        }

        dialogFilters.push( f );
        renderFilters();
        closeFilterForm();
        refreshDialogState();
        jQuery( '#dlgAddFilter' ).trigger( 'focus' );
    } );

    jQuery( '#dlgFilterCancel' ).on( 'click', function () {
        closeFilterForm();
        refreshDialogState();
        jQuery( '#dlgAddFilter' ).trigger( 'focus' );
    } );

    jQuery( '#dlgFilters' ).on( 'click', '.owa_filterPillRemove', function () {
        dialogFilters.splice( Number( jQuery( this ).closest( '.owa_filterPill' ).attr( 'data-index' ) ), 1 );
        renderFilters();
        refreshDialogState();
        jQuery( '#dlgAddFilter' ).trigger( 'focus' );
    } );

    /*
     * The gallery: a jQuery UI dialog, as New on the list is. Choosing a type
     * adds the widget and opens its panel.
     */
    jQuery( '#typeDialog' ).dialog( {
        autoOpen: false,
        modal: true,
        width: Math.min( 760, jQuery( window ).width() - 32 ),
        title: 'Add a widget',
        appendTo: '.owa',
        dialogClass: 'owa_typeDialogFrame'
    } );

    // The panel.
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

        if ( e.which !== 27 || jQuery( e.target ).closest( '.chosen-container' ).length ) {
            return;
        }

        e.preventDefault();

        if ( filterFormOpen() ) {
            closeFilterForm();
            refreshDialogState();
            jQuery( '#dlgAddFilter' ).trigger( 'focus' );
        } else {
            closePanel();
        }
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

            jQuery( '#typeDialog' ).dialog( 'open' );
        } )

        // Moving by keyboard: the handle and the arrow keys.
        .on( 'keydown', '.owa_builderMove', function ( e ) {

            var step = { 37: -1, 38: -1, 39: 1, 40: 1 }[ e.which ];

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

        if ( widgets.length >= MAX ) {
            return;
        }

        // A panel open on an unapplied widget is abandoned first.
        if ( editing !== null ) {
            closePanel();
        }

        widgets.push( newWidget( widgets.length, jQuery( this ).attr( 'data-type' ) ) );

        jQuery( '#typeDialog' ).dialog( 'close' );

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

        var built = { title: jQuery( '#customReportName' ).val(), widgets: [] };

        /*
         * Whatever set the report already had, unchanged. There is no control
         * for it, so there is nothing to read -- the stored value is simply
         * passed through, which is what stops a rename from stripping it.
         */
        if ( reportMetrics ) {
            built.metrics = reportMetrics;
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
