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
            <?php
                /*
                 * MARKED REQUIRED, because the placeholder makes an empty one
                 * look filled in.
                 *
                 * "Untitled report" sits in the field greyed out, which reads
                 * as a default that has already been supplied rather than as an
                 * example of what to type -- so pressing Save with it untouched
                 * was the most ordinary mistake there is, and the refusal it
                 * produced was the least informative page in the application.
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
             * THE REPORT METRIC SET IS NOT ASKED FOR HERE ANY MORE.
             *
             * It was a second place to answer "what does this report measure",
             * beside the one inside every widget, and the two did not compose:
             * a widget that named nothing inherited the set, so the same
             * report drew different things depending on a field further up the
             * page that said nothing about which widget it would land in. An
             * author reading a widget could not tell what it would show.
             *
             * So a widget names its own metrics, always -- see
             * CustomReports::validateQuery(), which now refuses one that names
             * none. The key is still READ: a report saved while the control
             * existed keeps its set, keeps rendering, and keeps validating, and
             * the script below carries the stored value back out untouched
             * rather than dropping it on the next save.
             */
        ?>
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
    <?php
        /*
         * Shown only while the canvas is empty, which for a NEW report is what
         * it opens as. Server-rendered and hidden rather than built in script,
         * so it is in the page for a reader whose stylesheet or script is slow
         * -- and so its words live in the template with the rest of them.
         */
    ?>
    <div id="customReportEmpty" class="owa_builderEmpty" style="display:none;">
        <span class="owa_builderEmptyTitle">Nothing in this report yet.</span>
        <span class="owa_builderEmptyBody">A report is its widgets &mdash; a table,
            a chart, a row of totals. Add at least one; it cannot be saved empty.</span>
    </div>

    <div id="customReportCanvas" class="owa_builderCanvas"></div>

    <div class="owa_builderActions">
        <?php
            /*
             * An id, because this button is no longer the only submit on the
             * page: the builder renders inside the report chrome now, and the
             * site filter brings a form of its own.
             */
        ?>
        <?php
            /*
             * owa-button, the class every other save form on the installation
             * uses -- the sibling visualization builder included.
             *
             * This said `owa_button`, with an underscore, and NOTHING defines
             * that: not owa.css, not owa.report.css, not the combined bundle.
             * So the one affirmative control on the screen rendered as a bare
             * browser submit while Save Visualization two screens over was the
             * orange primary, and the difference was invisible in review
             * because a class name reads as styling whether or not a rule
             * exists for it.
             */
        ?>
        <?php
            /*
             * Disabled in the MARKUP, not only by draw().
             *
             * draw() sets this on every canvas change and is the thing that
             * keeps it right, but it does not run until the script at the foot
             * of the page does -- so a new report drew an enabled Save for as
             * long as that took, and pressing it in that window posted an empty
             * definition. Rendered from the same fact the canvas is rendered
             * from, there is no window.
             */
        ?>
        <input type="submit" id="customReportSubmit" class="owa-button" value="Save report"
               <?php echo empty( $owa_definition['widgets'] ) ? 'disabled="disabled"' : ''; ?> />

        <?php
            /*
             * WHY Save will not go, next to the button that will not go.
             *
             * A disabled control with no explanation is the worst of both: it
             * says something is wrong and not what. The text is written by
             * refreshSaveState() from the same rules the server applies.
             */
        ?>
        <span id="customReportBlocker" class="owa_builderBlocker" style="display:none;"></span>

        <?php if ( $owa_id ): ?>
        <a class="owa-button owa-button-quiet" href="<?php echo $view->makeLink( array(
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
        <?php
            /*
             * The "Required" marks are TOGGLED, not written once.
             *
             * Which of these must be answered depends on the widget's type --
             * a card and a pie draw exactly one dimension and are refused
             * without it, while a grid may name none -- so a mark painted into
             * the markup would be wrong on half the types. applyTypeRules()
             * sets them from the same lists the server validates against.
             *
             * Kept in a row beside the label rather than inside it, because
             * that label's text is rewritten per type and would take the mark
             * with it.
             */
        ?>
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

    <?php
        /*
         * WHY DONE WILL NOT CLOSE.
         *
         * One message for the whole dialog rather than one per field. It names
         * the field it is about -- "Choose at least one metric", "Constraint 1
         * ... gives no value" -- and it sits at the foot, next to the button
         * that just refused, which is where the eye is at that moment.
         */
    ?>
    <div id="dlgError" class="owa_builderRowError" style="display:none;" role="alert"></div>
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
     * OWN_METRIC_TYPES used to be read here, to say on the form which types
     * could not fall back to the report metric set. Nothing falls back any
     * more -- every widget names its own -- so the distinction has nothing left
     * to tell an author, and the list is not sent. The constant still exists
     * server-side, where it is what makes the refusal for those types say the
     * more precise thing.
     */

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

    var editing = null;   // index of the widget the dialog is open on

    /*
     * The widget the type chooser just added, which has never been applied.
     *
     * The chooser pushes a widget and draws it BEFORE the dialog opens, so
     * backing out of that dialog has to undo the add -- otherwise Cancel is the
     * way to create exactly the unconfigured block Done refuses to make, and
     * the rule is decoration.
     *
     * Held here rather than as a flag ON the widget, because a flag would be
     * copied into the posted definition by the submit handler and stored.
     */
    var pendingNew = null;

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

            var summary = widgetMetrics( widget )
                .concat( names( widget.query && widget.query.dimensions ) );

            /*
             * A block that names no metrics is marked ON THE CANVAS, not only
             * in the sentence under the Save button. The canvas is where an
             * author is looking, and "Widget 3 does not say what it measures"
             * is only useful if Widget 3 can be picked out of a row of blocks.
             *
             * Not marked at all where a report metric set exists, because there
             * the widget is inheriting rather than unfinished -- the same
             * exception the server makes.
             */
            if ( ! reportMetrics && ! widgetMetrics( widget ).length ) {

                $block.addClass( 'owa_builderBlockIncomplete' );
            }

            $block.append( jQuery( '<div class="owa_builderBlockSummary">' )
                .text( summary.length ? summary.join( ', ' ) : 'No metrics chosen yet' ) );

            $block.append( jQuery( '<a href="#" class="owa_builderEdit">' ).text( 'Edit' ) );

            $canvas.append( $block );
        } );

        if ( widgets.length < MAX ) {

            $canvas.append(
                jQuery( '<button type="button" id="addWidget" class="owa_builderAdd" title="Add a widget">' )
                    .append( jQuery( '<span class="owa_builderAddPlus">' ).text( '+' ) )
                    .append( jQuery( '<span>' ).text( 'Add widget' ) ) );
        }

        /*
         * WHAT AN EMPTY CANVAS SAYS.
         *
         * The plus alone is a control, not an instruction: on a blank screen it
         * reads as one option among others rather than as the only thing there
         * is to do. So an empty canvas says what a report is made of and that
         * it needs at least one, beside a plus that is now the obvious target.
         *
         * Drawn as a sibling of the plus rather than replacing it, because the
         * plus is where the widget will appear -- moving it for the empty case
         * would make the first widget land somewhere other than where it was
         * added.
         */
        $canvas.toggleClass( 'owa_builderCanvasEmpty', ! widgets.length );

        jQuery( '#customReportEmpty' ).toggle( ! widgets.length );

        jQuery( '#widgetBudget' ).text( widgets.length + ' of ' + MAX + ' widgets' );

        refreshSaveState();
    }

    /**
     * Why this report cannot be saved yet, or '' if it can.
     *
     * THE SAME RULES THE SERVER APPLIES, asked before the round trip rather
     * than instead of it. Every one of these is refused by
     * CustomReports::validate() or by the save controller's own validate(); the
     * point of asking here is that the author is told while the thing they have
     * to fix is still in front of them.
     *
     * In the order an author would fix them: something to save, a name for it,
     * then whether each widget says what it measures.
     */
    function saveBlocker() {

        if ( ! widgets.length ) {
            return 'Add at least one widget before saving.';
        }

        /*
         * The NAME. Required by CustomReportSave::validate(), and the field
         * carries a placeholder -- "Untitled report" -- which reads as a value
         * that is already there. Leaving it empty was the one refusal an author
         * could hit without having done anything they would recognise as wrong.
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
                         + ' does not say what it measures. Open it and choose a metric.';
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
     * Save is available exactly when the report could actually be stored.
     *
     * Disabled rather than hidden: the button is where an author expects it,
     * and the sentence beside it says why it will not go yet. A control that
     * vanishes reads as a page that has not finished loading.
     */
    function refreshSaveState() {

        var blocker = saveBlocker();

        jQuery( '#customReportSubmit' )
            .prop( 'disabled', !! blocker )
            .attr( 'title', blocker );

        jQuery( '#customReportBlocker' ).text( blocker ).toggle( !! blocker );
    }

    // ------------------------------------------------------------------
    // The dialog
    // ------------------------------------------------------------------

    function openDialog( index ) {

        editing = index;

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
         * THERE IS NO LONGER ANYTHING TO LEAVE THIS EMPTY FOR.
         *
         * The sentence here used to be "Leave empty to use the report metric
         * set" on every type that could inherit one, and a contradiction of it
         * on the types that could not. With the set withdrawn from the builder
         * the first half is an offer nothing fulfils -- an author who took it
         * would be refused on save -- so both halves go and the field says the
         * one thing that is now true of every type: it has to be answered.
         */
        var required = ' Required: a widget draws what it names, and nothing else.';

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
        jQuery( '#dlgSortField' ).toggle( picksDimensions( type ) );

        narrowMetrics();

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

    /** One constraint row read off its controls. */
    function readConstraintRow( row ) {

        return {
            name:  jQuery( row ).find( 'select.dim-list' ).val() || '',
            op:    jQuery( row ).find( 'select.operator-list' ).val() || '==',
            value: jQuery.trim( jQuery( row ).children( '.constraintValueField' ).val() || '' )
        };
    }

    /**
     * The rows, back as the stored string.
     *
     * A COMPLETELY empty row contributes nothing, which is what lets the form
     * always carry a blank one without it meaning anything. A HALF-filled row
     * is a different thing and is not silently dropped -- see
     * constraintRowProblem(), which stops the dialog closing on one.
     */
    function readConstraintRows() {

        var out = [];

        jQuery( '#dlgConstraintRows > ul > li' ).each( function () {

            var part = readConstraintRow( this );

            if ( part.name && part.value ) {
                out.push( part.name + part.op + part.value );
            }
        } );

        return out.join( ',' );
    }

    /**
     * What is wrong with the constraint rows, or '' if nothing is.
     *
     * A HALF-FILLED ROW USED TO VANISH.
     *
     * readConstraintRows() keeps a row only when it has both a dimension and a
     * value, and dropped anything else without a word. So an author who chose
     * `medium`, chose an operator and then tabbed past the value -- or typed a
     * value and never picked the dimension -- got a widget with no filter on
     * it, no message, and no way to tell it apart from one they had never
     * filtered. The query engine treats exactly this as an error worth refusing
     * ("a missing value is not a request for everything"); the builder treated
     * it as nothing.
     *
     * An entirely blank row is still nothing, because there is always one on
     * the form and it does not mean the author left something out.
     */
    function constraintRowProblem() {

        var problem = '';

        jQuery( '#dlgConstraintRows > ul > li' ).each( function ( i ) {

            if ( problem ) {
                return;
            }

            var part = readConstraintRow( this );

            // Untouched. There is always one of these.
            if ( ! part.name && ! part.value ) {
                return;
            }

            if ( ! part.name ) {
                problem = 'Constraint ' + ( i + 1 ) + ' has a value but no dimension. '
                        + 'Choose what to constrain on, or clear the value.';
                return;
            }

            if ( ! part.value ) {
                problem = 'Constraint ' + ( i + 1 ) + ' constrains on ' + part.name
                        + ' but gives no value. An empty value is not a request for '
                        + 'everything -- fill it in, or remove the row.';
            }
        } );

        return problem;
    }

    /** Whether a name is one the reporting registry knows. */
    function isKnownName( name ) {

        var all = METRICS.concat( DIMENSIONS );

        for ( var i = 0; i < all.length; i++ ) {

            if ( all[ i ].name === name ) {
                return true;
            }
        }

        return false;
    }

    /**
     * What is wrong with the SORT, or '' if nothing is.
     *
     * The one field in this dialog an author types into freely, and so the one
     * that could still reach the server as a name that does not resolve -- a
     * refusal two steps later, on a page about a widget rather than showing
     * them one. The registry is already in the page for the pickers, so the
     * same answer is available here.
     *
     * A trailing '-' is the DIRECTION, not part of the name, and is stripped
     * before the lookup: without that, every descending sort would be reported
     * as unresolvable. Empty is fine -- a widget need not name a sort.
     */
    function sortProblem() {

        var sort = jQuery.trim( jQuery( '#dlgSort' ).val() || '' );

        if ( ! sort || ! picksDimensions( editingType() ) ) {
            return '';
        }

        var name = sort.replace( /-$/, '' );

        if ( ! name ) {
            return 'The sort is only a direction. Name the metric or dimension to sort by.';
        }

        if ( ! isKnownName( name ) ) {
            return '"' + name + '" is not a metric or a dimension, so the widget cannot '
                 + 'be sorted by it. Use a name from the pickers above.';
        }

        return '';
    }

    /**
     * EVERYTHING WRONG WITH THIS WIDGET, or '' if it is ready.
     *
     * WHY THE MODAL ENFORCES THIS AND NOT JUST THE CANVAS
     *
     * A widget used to be able to leave this dialog unconfigured: Done closed
     * on anything, the block landed on the canvas marked unfinished, and Save
     * explained it. That is a correct chain and a slow one -- the author is
     * told about a widget two steps after the screen that was about that
     * widget, and has to find it again to fix it.
     *
     * Asked here, the answer arrives while the fields are still open. The
     * canvas marking and the Save gate stay, because a definition can also
     * arrive already broken -- from a report saved before these rules, or from
     * a refusal round-trip -- and those have to say so too.
     *
     * In the order the fields appear, so the message points DOWN the form the
     * way the author reads it.
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

            return isSingleMetric( type )
                ? 'Choose the metric this ' + ( TYPES[ type ] || type ).toLowerCase()
                  + ' draws.'
                : 'Choose at least one metric. A widget draws what it names, and '
                  + 'nothing else.';
        }

        /*
         * A dimension, on the types that draw exactly one and render nothing
         * without it. SINGLE_FIELD_TYPES is the server's list, so this cannot
         * come to disagree with the rule that enforces it.
         */
        if ( isSingleField( type ) && picksDimensions( type )
             && ! ( jQuery( '#dlgDimensions' ).val() || [] ).length ) {

            return 'Choose the dimension its rows are grouped by. A '
                 + ( TYPES[ type ] || type ).toLowerCase() + ' draws one, and draws '
                 + 'nothing without it.';
        }

        return sortProblem() || constraintRowProblem();
    }

    /**
     * Write the dialog back onto the widget.
     *
     * Returns FALSE when it refused to, which is what stops Done closing on a
     * widget that is not finished -- the fields that need filling are in this
     * dialog, so closing would hide them.
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

        editing    = null;
        pendingNew = null;

        draw();

        return true;
    }

    chosenify( '#dlgMetrics' );
    chosenify( '#dlgDimensions' );

    // Choosing a metric changes what else is askable alongside it.
    jQuery( '#dlgMetrics' ).on( 'change', function () {
        narrowMetrics();
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
            /*
             * Closed only if the dialog could be applied. A refused apply
             * leaves the modal open with the reason on it: the fields that need
             * filling are in here, and closing would take the author away from
             * the controls the message is about.
             */
            { text: 'Done', click: function () {
                if ( applyDialog() ) { jQuery( this ).dialog( 'close' ); }
            } },
            { text: 'Cancel', click: function () { editing = null; jQuery( this ).dialog( 'close' ); } }
        ],

        /*
         * BACKING OUT OF A NEW WIDGET UNDOES THE ADD.
         *
         * The type chooser pushes the widget before this dialog opens, so
         * without this Cancel leaves an unconfigured block on the canvas -- the
         * very thing Done now refuses to produce, reachable by pressing the
         * other button. At the moment it is offered, Cancel means "I did not
         * mean to add this".
         *
         * On `close` rather than in the Cancel handler, so the titlebar X and
         * the Escape key do the same thing. A successful Done has already
         * cleared pendingNew by the time it closes, so this cannot undo a
         * widget that was actually configured -- and re-opening a widget that
         * WAS configured never sets pendingNew, so Cancel there reverts the
         * edit and keeps the widget, which is what Cancel means then.
         */
        close: function () {

            if ( pendingNew === null ) {
                return;
            }

            widgets.splice( pendingNew, 1 );

            pendingNew = null;
            editing    = null;

            draw();
        }
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

            /*
             * Removing the last one leaves the canvas EMPTY.
             *
             * It used to put a fresh block back, on the reasoning that a report
             * with no widgets cannot be saved and so an empty canvas is a dead
             * end. It is not a dead end -- the plus is right there -- and the
             * replacement was worse than the emptiness: an author who removed a
             * widget got another one, of a type they had not chosen, which then
             * had to be removed as well.
             */
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
        // build, not a step of its own. Recorded as pending, so backing out of
        // that dialog undoes the add rather than leaving a block nobody chose
        // to make.
        pendingNew = widgets.length - 1;

        openDialog( widgets.length - 1 );
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
