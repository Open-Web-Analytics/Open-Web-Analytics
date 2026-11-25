<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * Filters as pills: the markup OWA.filterPills (owa.filterpills.js) drives.
 * Included by the report editor's widget panel, the funnel builder, and the
 * segment filters of the funnel and recordings reports.
 *
 *   $owa_fp  the element ids -- pills, add, form, dimension, operator, value,
 *            addFilter, cancel -- and label, the id of the field's label
 */
$owa_fp = $owa_fp ?? [];
?>
<div class="owa_filterPills" id="<?php $view->out( $owa_fp['pills'] ); ?>" role="group" data-ui="filter:pills"
     aria-labelledby="<?php $view->out( $owa_fp['label'] ); ?>">
    <button type="button" class="owa_button owa_filterAdd" id="<?php $view->out( $owa_fp['add'] ); ?>"
            aria-expanded="false" aria-controls="<?php $view->out( $owa_fp['form'] ); ?>"><i
            class="fas fa-filter" aria-hidden="true"></i> <?php $view->out( \OWA\Core\CoreAPI::t( 'Filter' ) ); ?></button>
</div>
<div class="owa_filterForm" id="<?php $view->out( $owa_fp['form'] ); ?>" data-ui="filter:form" style="display:none;">
    <?php /* The field is picked with OWA.fieldChooser, which this button opens. */ ?>
    <span id="<?php $view->out( $owa_fp['dimension'] ); ?>Label" class="owa_srOnly"><?php $view->out( \OWA\Core\CoreAPI::t( 'Field' ) ); ?></span>
    <button type="button" id="<?php $view->out( $owa_fp['dimension'] ); ?>" data-ui="filter:dimension"
            aria-labelledby="<?php $view->out( $owa_fp['dimension'] ); ?>Label <?php $view->out( $owa_fp['dimension'] ); ?>"></button>
    <label for="<?php $view->out( $owa_fp['operator'] ); ?>" class="owa_srOnly"><?php $view->out( \OWA\Core\CoreAPI::t( 'Condition' ) ); ?></label>
    <select id="<?php $view->out( $owa_fp['operator'] ); ?>"></select>
    <label for="<?php $view->out( $owa_fp['value'] ); ?>" class="owa_srOnly"><?php $view->out( \OWA\Core\CoreAPI::t( 'Value' ) ); ?></label>
    <input type="text" id="<?php $view->out( $owa_fp['value'] ); ?>" placeholder="<?php $view->out( \OWA\Core\CoreAPI::t( 'Value' ) ); ?>" />
    <div class="owa_filterFormActions">
        <button type="button" class="owa_button" id="<?php $view->out( $owa_fp['cancel'] ); ?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Cancel' ) ); ?></button>
        <button type="button" class="owa_button is-primary" id="<?php $view->out( $owa_fp['addFilter'] ); ?>"
                disabled="disabled"><?php $view->out( \OWA\Core\CoreAPI::t( 'Add filter' ) ); ?></button>
    </div>
</div>
