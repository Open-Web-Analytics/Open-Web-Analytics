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
 * The behaviour is owa.customReportEdit.js, started from the data at the
 * foot of this template.
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

<form id="customReportForm" class="owa_editor" action="<?php echo $view->makeLink( [ 'do' => 'base.customReportSave' ] ); ?>" method="post">

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
                <label for="customReportName"><?php $view->out( \OWA\Core\CoreAPI::t( 'Report name' ) ); ?></label>
                <span class="owa_builderRequired"><?php $view->out( \OWA\Core\CoreAPI::t( 'Required' ) ); ?></span>
            </div>
            <input type="text" id="customReportName" name="customReportName"
                   placeholder="<?php $view->out( \OWA\Core\CoreAPI::t( 'Untitled report' ) ); ?>"
                   value="<?php $view->out( $owa_name ); ?>" />
        </div>

        <?php
            /*
             * Listed to and opened by others, or by its author alone
             * (CustomReports::mayView()).
             */
        ?>
        <label class="owa_switch" for="isShared">
            <input type="checkbox" role="switch" id="isShared" name="isShared" value="1"
                   aria-describedby="isSharedHint"
                   <?php echo $owa_is_shared ? 'checked="checked"' : ''; ?> />
            <span class="owa_switchTrack" aria-hidden="true"></span>
            <?php $view->out( \OWA\Core\CoreAPI::t( 'Shared in everyone\'s list' ) ); ?>
        </label>
        <span id="isSharedHint" class="owa_srOnly"><?php $view->out( \OWA\Core\CoreAPI::t( 'Off, it opens only for you and administrators, even from a link you have already sent.' ) ); ?></span>

        <div class="owa_editorActions">
            <a class="owa_button" id="customReportCancel" href="<?php echo $owa_id
                ? $view->makeLink( [ 'do' => 'base.report', 'reportId' => 'custom-' . $owa_id ], true )
                : $view->makeLink( [ 'do' => 'base.customReports' ], true ); ?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Cancel' ) ); ?></a>
            <?php
                /*
                 * Disabled in the MARKUP as well as by draw(), so a new report
                 * has no window before the script runs in which Save posts an
                 * empty definition.
                 */
            ?>
            <input type="submit" id="customReportSubmit" class="owa_button is-primary" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Save report' ) ); ?>"
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
        <h2><?php $view->out( \OWA\Core\CoreAPI::t( 'Layout' ) ); ?></h2>
        <span class="owa_builderBudget" id="widgetBudget"></span>
    </div>

    <?php
        /*
         * Shown only while the canvas is empty, which for a NEW report is what
         * it opens as. Server-rendered so its words live with the rest of them.
         */
    ?>
    <div id="customReportEmpty" class="owa_builderEmpty" style="display:none;">
        <span class="owa_builderEmptyTitle"><?php $view->out( \OWA\Core\CoreAPI::t( 'Nothing in this report yet.' ) ); ?></span>
        <span class="owa_builderEmptyBody"><?php $view->out( \OWA\Core\CoreAPI::t( 'Add a table, a chart, or a row of totals. A report cannot be saved empty.' ) ); ?></span>
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
        <a class="owa_builderDelete" href="<?php echo $view->makeLink( [
            'do'             => 'base.customReportDelete',
            'customReportId' => $owa_id,
        ], false, '', false, true ); ?>"
           data-owa-confirm
           data-owa-confirm-title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete this report?' ) ); ?>"
           data-owa-confirm-body="<?php $view->out( \OWA\Core\CoreAPI::t( 'This is final. Unlike a Profile, a custom report is not archived.' ) ); ?>"
           data-owa-confirm-proceed="<?php $view->out( \OWA\Core\CoreAPI::t( 'Delete report' ) ); ?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Delete this report' ) ); ?></a>
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
     * is built for a type that is already known.
     *
     * The same gallery changes a widget's type from its panel: what the new
     * type cannot use is dropped -- a card keeps one metric and one dimension,
     * a trend's breakdown cannot be a date -- and Cancel puts the old type back.
     */
?>
<div id="typeDialog" class="owa_typeDialog" data-ui="dialog:widget-type" hidden>
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
                        $view->out( \OWA\Core\Strings::translate( \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPE_HINTS[ $owa_key ] ?? '' ) ); ?></span>
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
            <div class="owa_widgetPanelKind"><span id="dlgTypePill"></span> &middot;
                <button type="button" class="owa_linkButton" id="dlgChangeType"
                        aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Change the widget type' ) ); ?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'change' ) ); ?></button></div>
            <h2 id="dlgHeading"><?php $view->out( \OWA\Core\CoreAPI::t( 'Widget settings' ) ); ?></h2>
        </div>
        <button type="button" class="owa_iconButton" id="dlgClose" aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Close' ) ); ?>" title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Close' ) ); ?>"><i class="fas fa-times" aria-hidden="true"></i></button>
    </div>

    <div class="owa_widgetPanelBody">

        <div class="owa_builderField">
            <label for="dlgTitle"><?php $view->out( \OWA\Core\CoreAPI::t( 'Name' ) ); ?></label>
            <input type="text" id="dlgTitle" />
        </div>

        <div class="owa_builderFieldRow">
            <?php // Filled by renderSpanChoices(), which adds the widget's own width if it is not one of these. ?>
            <fieldset class="owa_builderField" id="dlgColspanField">
                <legend><?php $view->out( \OWA\Core\CoreAPI::t( 'Width' ) ); ?></legend>
                <div class="owa_seg" id="dlgWidth"></div>
            </fieldset>

            <div class="owa_builderField owa_builderNote" id="dlgWidthNote" style="display:none;"></div>

            <fieldset class="owa_builderField">
                <legend><?php $view->out( \OWA\Core\CoreAPI::t( 'Height' ) ); ?></legend>
                <div class="owa_seg" id="dlgHeight"></div>
            </fieldset>
        </div>

        <div class="owa_builderField" id="dlgMetricsField">
            <div class="owa_builderFieldLabel">
                <?php /* Names the chips (aria-labelledby); "+ Add" is named by its own words. */ ?>
                <label id="dlgMetricsLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Metrics' ) ); ?></label>
                <span class="owa_builderRequired" id="dlgMetricsRequired"><?php $view->out( \OWA\Core\CoreAPI::t( 'Required' ) ); ?></span>
            </div>
            <?php /* Holds the choice; owa.customReportEdit.js draws its chips and "+ Add" after it. */ ?>
            <select id="dlgMetrics" multiple="multiple" hidden></select>
            <div class="owa_builderHelp" id="dlgMetricsHelp"></div>
        </div>

        <div class="owa_builderField" id="dlgDimensionsField">
            <div class="owa_builderFieldLabel">
                <label id="dlgDimensionsLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Dimensions' ) ); ?></label>
                <span class="owa_builderRequired" id="dlgDimensionsRequired"><?php $view->out( \OWA\Core\CoreAPI::t( 'Required' ) ); ?></span>
            </div>
            <select id="dlgDimensions" multiple="multiple" hidden></select>
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
            <label for="dlgSortBy"><?php $view->out( \OWA\Core\CoreAPI::t( 'Sort' ) ); ?></label>
            <div class="owa_builderSort">
                <select id="dlgSortBy"></select>
                <select id="dlgSortDir" aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Sort direction' ) ); ?>"></select>
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
            <span class="owa_builderLabel" id="dlgFiltersLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Filters' ) ); ?></span>
            <?php
                $owa_fp = [
                    'pills' => 'dlgFilters', 'add' => 'dlgAddFilter', 'form' => 'dlgFilterForm',
                    'dimension' => 'dlgFilterDimension', 'operator' => 'dlgFilterOperator',
                    'value' => 'dlgFilterValue', 'addFilter' => 'dlgFilterAdd', 'cancel' => 'dlgFilterCancel',
                    'label' => 'dlgFiltersLabel',
                ];
                include( 'filter_pills.php' );
            ?>
        </div>

        <?php
            /*
             * Where a card's rows go when clicked. Only a card: its single
             * dimension is the unambiguous link column. Hidden when the
             * dimension has no detail report.
             */
        ?>
        <div class="owa_builderField" id="dlgLinkField" style="display:none;">
            <label for="dlgLinkReport"><?php $view->out( \OWA\Core\CoreAPI::t( 'Rows link to' ) ); ?></label>
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
                <label for="dlgMoreReport"><?php $view->out( \OWA\Core\CoreAPI::t( 'Full report link' ) ); ?></label>
                <select id="dlgMoreReport"></select>
                <div class="owa_builderHelp"><?php $view->out( \OWA\Core\CoreAPI::t( 'Shown beside the widget\'s name, or at the foot of a card.' ) ); ?></div>
            </div>

            <div class="owa_builderField">
                <label for="dlgMoreLabel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Link text' ) ); ?></label>
                <input type="text" id="dlgMoreLabel" placeholder="<?php $view->out( \OWA\Module\Base\Classes\CustomReports::shownMoreLabel( \OWA\Module\Base\Classes\CustomReports::MORE_LABEL ) ); ?>" />
            </div>
        </div>

        <div id="dlgError" class="owa_builderRowError" style="display:none;" role="alert"></div>
    </div>

    <div class="owa_widgetPanelFoot">
        <button type="button" class="owa_button" id="dlgCancel"><?php $view->out( \OWA\Core\CoreAPI::t( 'Cancel' ) ); ?></button>
        <button type="button" class="owa_button is-primary" id="dlgApply"><?php $view->out( \OWA\Core\CoreAPI::t( 'Apply' ) ); ?></button>
    </div>
</aside>

<?php
    /*
     * The editor's data, for owa.customReportEdit.js: the reporting registry's
     * names and the server's per-type rules, read from the same lists that
     * validate the definition on save.
     */
?>
<script type="application/json" data-ui="custom-report-editor:config"><?php echo $view->scriptJson( [
    'metrics'             => (array) $view->get('metric_choices'),
    'dimensions'          => (array) $view->get('dimension_choices'),
    'types'               => (object) $owa_types,
    'maxWidgets'          => $owa_max,
    'maxMetrics'          => (int) $view->get('max_metrics'),
    'maxDimensions'       => (int) $view->get('max_dimensions'),
    'fullWidthMaxDimensions' => (int) $view->get('full_width_max_dimensions'),
    'fullWidthTypes'      => array_values( (array) $view->get('full_width_types') ),
    'singleFieldTypes'    => array_values( (array) $view->get('single_field_types') ),
    'singleMetricTypes'   => array_values( (array) $view->get('single_metric_types') ),
    'chartTypes'          => array_values( (array) $view->get('chart_types') ),
    'defaultColspans'     => (object) (array) $view->get('default_colspans'),
    'newColspans'         => (object) \OWA\Module\Base\Classes\CustomReports::NEW_WIDGET_COLSPANS,
    'widths'              => array_map( null, array_keys( \OWA\Module\Base\Classes\CustomReports::WIDTHS ), array_values( \OWA\Module\Base\Classes\CustomReports::translatedTable( \OWA\Module\Base\Classes\CustomReports::WIDTHS ) ) ),
    'heights'             => array_map( null, array_keys( \OWA\Module\Base\Classes\CustomReports::HEIGHTS ), array_values( \OWA\Module\Base\Classes\CustomReports::translatedTable( \OWA\Module\Base\Classes\CustomReports::HEIGHTS ) ) ),
    'linkTargets'         => (object) (array) $view->get('link_targets'),
    'moreTargets'         => (object) (array) $view->get('more_targets'),
    'moreLabel'           => \OWA\Module\Base\Classes\CustomReports::MORE_LABEL,
    'typeIcons'           => (object) \OWA\Module\Base\Classes\CustomReports::WIDGET_TYPE_ICONS,
    'fixedDimensions'     => (object) (array) $view->get('fixed_dimensions'),
    'fixedDimensionExtra' => (object) (array) $view->get('fixed_dimension_extra'),
    'timeDimensions'      => \OWA\Module\Base\Classes\CustomReports::timeDimensions(),
    'ungroupedTypes'      => \OWA\Module\Base\Classes\CustomReports::UNGROUPED_TYPES,
    'columns'             => (int) $view->get('grid_columns') ?: 12,
    'definition'          => (object) $owa_definition,
] ); ?></script>
