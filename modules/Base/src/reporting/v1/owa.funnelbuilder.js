import $ from 'jquery';
import { OWA } from './owa.js';
import { bindBuilder, readVocabulary, setText, CUSTOM_TRIGGER } from './owa.goalbuilder.js';

/**
 * The funnel builder's steps, and rows added and removed in a repeatable list
 * -- a goal event definition's conditions and a funnel step's.
 *
 * A STEP is an event and conditions on it (visualization_edit_step.php): an
 * event picker, the name field for "another event of yours", all/any, and the
 * condition rows. Its condition builder is the goal event definition form's
 * (owa.goalbuilder.js), bound once per step with the step's own class names
 * (STEP_CLASSES), so the property list follows the event the same way in both.
 *
 * A step's condition fields are named by the step's position --
 * stepConditionProperty[N][] and its Operator and Value siblings -- so the
 * server can tell whose conditions are whose. renumber() keeps N right as
 * steps are added, moved and removed.
 *
 * The rows are .constraintRow, the same markup the report builder uses, so they
 * arrive already styled -- including the + and X buttons. Those buttons are
 * bound PER ROW inside the report builder and the result set explorer, though,
 * so reusing the markup does not reuse the behaviour: the buttons rendered,
 * looked live, and did nothing.
 *
 * Delegated from the document rather than bound per row, because rows are added
 * at runtime and a per-row binding would have to be repeated for each new one --
 * which is the shape that left the originals unbound here in the first place.
 *
 * Progressive enhancement: with no JavaScript the form still saves whatever
 * steps were rendered. The server validates by TYPE and numbers the steps it
 * keeps, so nothing here decides anything.
 */

/*
 * Any list that opts in, not just the funnel.
 *
 * A goal event definition's conditions are a repeatable list for the same
 * reason a funnel's steps are, and they were built twice before this was
 * generalised. The list declares itself with data-owa-repeatable so a third one
 * needs no JS at all.
 */
var LIST_SELECTOR = '[data-owa-repeatable]';

/** A funnel step's parts, for owa.goalbuilder.js's bindBuilder(). */
export const STEP_CLASSES = {
    trigger: 'owa_funnelStepEvent',
    custom: 'owa_funnelStepCustomEvent',
    condition: 'owa_funnelCondition',
    property: 'owa_funnelConditionProperty',
    help: 'owa_funnelConditionHelp',
    warning: 'owa_funnelConditionWarning',
    optionName: 'owa_funnelOptionName',
    optionDescription: 'owa_funnelOptionDescription',
};

/** What a new step matches: a page, by its path. FunnelStep::DEFAULT_EVENT. */
export const DEFAULT_EVENT = 'page_view';

function fieldValue( el ) {

    return el ? String( el.value || '' ) : '';
}

/**
 * A step row as the preview sends it and the save reads it: the event as
 * named (the typed name for "another event of yours"), all or any, and each
 * condition that has a value.
 *
 * @param  {HTMLElement} row  li.owa_funnelStep
 * @return {Object} {name, event, custom, match, conditions: [{property, operator, value}]}
 */
export function readStep( row ) {

    const picked = fieldValue( row.querySelector( '.' + STEP_CLASSES.trigger ) );
    const custom = picked === CUSTOM_TRIGGER;
    const conditions = [];

    row.querySelectorAll( '.' + STEP_CLASSES.condition ).forEach( ( condition ) => {

        const value = fieldValue( condition.querySelector( '.owa_funnelConditionValue' ) ).trim();

        if ( value === '' ) {

            return;
        }

        conditions.push( {
            property: fieldValue( condition.querySelector( '.' + STEP_CLASSES.property ) ),
            operator: fieldValue( condition.querySelector( '.owa_funnelConditionOperator' ) ),
            value: value,
        } );
    } );

    return {
        name: fieldValue( row.querySelector( '.owa_funnelStepName' ) ),
        event: custom ? fieldValue( row.querySelector( '.' + STEP_CLASSES.custom ) ).trim() : picked,
        custom: custom,
        match: fieldValue( row.querySelector( '.owa_funnelStepMatch' ) ) === 'any' ? 'any' : 'all',
        conditions: conditions,
    };
}

/**
 * A row nobody filled in, which is not a step: no condition has a value and
 * the event is still the default. FunnelStep::isBlank() on the server.
 */
export function isBlankStep( step ) {

    return ! step.conditions.length && ! step.custom && step.event === DEFAULT_EVENT;
}

/**
 * Point a step's condition fields at its position.
 *
 * @param {HTMLElement} row
 * @param {number}      index  from 0, as the server reads the arrays
 */
export function renumber( row, index ) {

    row.querySelectorAll( '[name*="stepCondition"]' ).forEach( ( field ) => {

        field.name = field.name.replace( /(stepCondition(?:Property|Operator|Value))\[\d*\]/, '$1[' + index + ']' );
    } );
}

/** The funnel's vocabulary, read once from the page (visualization_edit.php). */
let vocabulary = null;

/**
 * Make one step's condition builder live. Called for every step on load and
 * for each one Add a step adds.
 *
 * @param {HTMLElement} row
 */
export function bindStep( row ) {

    if ( vocabulary === null ) {

        vocabulary = readVocabulary( 'owa_funnelVocabulary' ) || {};

        // The page's translated words for a property the event does not carry.
        const form = row.closest( 'form' );

        if ( form ) {

            setText( { notCarriedLabel: form.dataset.notCarriedLabel, notCarriedHelp: form.dataset.notCarriedHelp } );
        }
    }

    bindBuilder( row, vocabulary, STEP_CLASSES );
}

OWA.funnelBuilder = {

    readStep: readStep,
    isBlankStep: isBlankStep,
    renumber: renumber,
    bindStep: bindStep,

    /**
     * A blank copy of a row, so the new step does not inherit its values.
     *
     * Without any widget a row's select was enhanced into: a chosen box is
     * markup bound to the ORIGINAL select, so a cloned one opens the first
     * row's list. The list gets an `owa:rowadded` on the new row and enhances
     * it itself (owa.goalbuilder.js).
     */
    blankCopy: function ( $row ) {

        var $copy = $row.clone();

        $copy.find( 'input' ).val( '' );
        $copy.find( '.chosen-container' ).remove();
        $copy.find( 'select' ).removeData( 'chosen' ).css( 'display', '' );

        return $copy;
    },

    /**
     * Never remove the last row.
     *
     * Emptying the funnel is done by clearing the fields -- the server treats a
     * step with no path as one nobody filled in, and removes the funnel when
     * none are left. Removing the last ROW would leave no way to add one back
     * without reloading.
     */
    remove: function ( $row ) {

        if ( $row.siblings( '.constraintRow' ).length < 1 ) {

            $row.find( 'input' ).val( '' );

            return;
        }

        $row.remove();
    }
};

$( function () {

    // Buttons: Enter and Space click them.
    $( document ).on( 'click', LIST_SELECTOR + ' .constraintAddButton',
        function ( event ) {

            event.preventDefault();

            var $row = $( this ).closest( '.constraintRow' );

            OWA.funnelBuilder.blankCopy( $row ).insertAfter( $row ).trigger( 'owa:rowadded' );
        } );

    $( document ).on( 'click', LIST_SELECTOR + ' .constraintRemoveButton',
        function ( event ) {

            event.preventDefault();

            OWA.funnelBuilder.remove( $( this ).closest( '.constraintRow' ) );
        } );
} );

export { OWA };
