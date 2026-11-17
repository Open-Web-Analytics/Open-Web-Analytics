import jQuery from 'jquery';
import { OWA } from './owa.format.js';
import { readStep, isBlankStep, renumber, bindStep } from './owa.funnelbuilder.js';

/**
 * The funnel builder screen (visualization_edit.php): its steps, the Save
 * gate, and the preview at the right -- the funnel counted as it stands, from
 * the steps on the screen, over the last 30 days (base.visualizationPreview).
 *
 * A step's own fields and conditions are owa.funnelbuilder.js. The template
 * hands its data over as JSON in
 *
 *   <script type="application/json" data-ui="funnel-editor:config">
 *     { maxSteps, dimensions, previewUrl }
 *
 * and this module starts the screen on DOM ready when that element is on the
 * page.
 */

/** The element holding the template's data. */
export const CONFIG_SELECTOR = '[data-ui="funnel-editor:config"]';

/** A page path condition given a full web address rather than a path. */
export function isFullUrl( value ) {

    return /^[a-z][a-z0-9+.\-]*:\/\//i.test( String( value || '' ) );
}

/** pattern with %1$s, %2$s... replaced by the values in order. */
export function sayEach( pattern, values ) {

    return String( pattern ).replace( /%(\d+)\$s/g, function ( all, i ) {
        return i - 1 < values.length ? String( values[ i - 1 ] ) : all;
    } );
}

/** pattern with its %s replaced by n. */
function say( pattern, n ) {

    return String( pattern ).replace( '%s', n );
}

/**
 * Why Save will not go, short of the filters, or ''. The same rules
 * VisualizationSave::validate() applies: a name, then each step the save
 * keeps names an event and matches a path rather than a web address, then at
 * least one such step. A step is numbered by its row, blank rows included.
 *
 * @param  {string}   name   the funnel's name as typed
 * @param  {Object[]} steps  readStep() of every row, in order
 * @return {string}
 */
export function stepsProblem( name, steps ) {

    if ( ! String( name || '' ).trim() ) {
        return OWA.l( 'Give the funnel a name.' );
    }

    var kept = 0;

    for ( var i = 0; i < steps.length; i++ ) {

        var step = steps[ i ];

        if ( isBlankStep( step ) ) {
            continue;
        }

        kept++;

        if ( step.event === '' ) {
            return say( OWA.l( 'Step %s needs an event.' ), i + 1 );
        }

        for ( var c = 0; c < step.conditions.length; c++ ) {

            if ( step.conditions[ c ].property === 'page_path' && isFullUrl( step.conditions[ c ].value ) ) {
                return say( OWA.l( 'Step %s needs a path, such as /basket, not a full web address.' ), i + 1 );
            }
        }
    }

    return kept ? '' : OWA.l( 'Add at least one step.' );
}

/**
 * The steps a preview counts: every row the save keeps, or null while one of
 * them could not be counted (no event, or a web address for a path).
 *
 * @param  {Object[]} steps  readStep() of every row
 * @return {Object[]|null}
 */
export function previewSteps( steps ) {

    var kept = steps.filter( function ( step ) { return ! isBlankStep( step ); } );

    var bad = kept.some( function ( step ) {

        return step.event === '' || step.conditions.some( function ( c ) {
            return c.property === 'page_path' && isFullUrl( c.value );
        } );
    } );

    return bad ? null : kept;
}

/** The preview's URL: the page's preview link with the steps and the filter. */
export function previewUrl( base, steps, filter ) {

    return base
        + '&' + 'steps' + '=' + encodeURIComponent( JSON.stringify( steps ) )
        + '&' + 'funnelFilter' + '=' + encodeURIComponent( filter );
}

/** part of whole as a percentage in the install's locale, to a tenth. */
export function percent( part, whole ) {

    var share = whole > 0 ? Math.round( part / whole * 1000 ) / 1000 : 0;

    try {
        return OWA.util.formatNumber( share, { style: 'percent', minimumFractionDigits: whole > 0 ? 1 : 0, maximumFractionDigits: 1 } );
    } catch ( e ) {
        return whole > 0 ? ( share * 100 ).toFixed( 1 ) + '%' : '0%';
    }
}

/** A count in the install's locale. */
function formatCount( n ) {

    try {
        return OWA.util.formatNumber( n );
    } catch ( e ) {
        return n.toLocaleString();
    }
}

/**
 * Start the screen on the page's markup.
 *
 * @param {Object} config  the template's data
 */
export function start( config ) {

    var MAX_STEPS   = Number( config.maxSteps ) || 10;
    var DIMENSIONS  = config.dimensions || [];
    var PREVIEW_URL = String( config.previewUrl || '' );

    var $list = jQuery( '#owa_funnelSteps' );

    var filters = new OWA.filterPills( {
        pills: 'owa_funnelFilters', add: 'owa_funnelAddFilter', form: 'owa_funnelFilterForm',
        dimension: 'owa_funnelFilterDimension', operator: 'owa_funnelFilterOperator',
        value: 'owa_funnelFilterValue', addFilter: 'owa_funnelFilterAdd', cancel: 'owa_funnelFilterCancel'
    }, DIMENSIONS, function () {
        jQuery( '#owa_funnelConstraints' ).val( filters.get() );
        refresh();
    } );

    filters.set( jQuery( '#owa_funnelConstraints' ).val() );

    function rows() {
        return $list.children( '.owa_funnelStep' );
    }

    function readRows() {
        return rows().get().map( readStep );
    }

    /** Numbers, labels and the condition fields' position, after any change to the list. */
    function syncRows() {

        rows().each( function ( i ) {

            var $row = jQuery( this );
            var n    = i + 1;

            renumber( this, i );

            $row.find( '.owa_funnelStepNumber' ).text( n );
            $row.find( '.owa_funnelStepEvent' ).attr( 'aria-label', say( OWA.l( 'The event step %s matches' ), n ) );
            $row.find( '.owa_builderMove' ).attr( 'aria-label', say( OWA.l( 'Move step %s' ), n ) );
            $row.find( '.owa_funnelStepRemove' ).attr( 'aria-label', say( OWA.l( 'Remove step %s' ), n ) );
        } );

        jQuery( '#owa_funnelAddStep' ).prop( 'disabled', rows().length >= MAX_STEPS );
    }

    /** Why Save will not go, or ''. */
    function blocker() {

        return stepsProblem( jQuery( '#owa_funnelName' ).val(), readRows() ) || filters.problem();
    }

    function refresh() {

        var problem = blocker();

        jQuery( '#owa_visualizationSubmit' ).prop( 'disabled', !! problem ).attr( 'title', problem );
        jQuery( '#owa_visualizationBlocker' ).text( problem ).toggle( !! problem );

        schedulePreview();
    }

    // ---- the preview ------------------------------------------------------

    var previewTimer   = null;
    var previewRequest = null;
    var previewAsked   = '';

    function schedulePreview() {

        window.clearTimeout( previewTimer );
        previewTimer = window.setTimeout( preview, 400 );
    }

    function previewMessage( text ) {
        jQuery( '#owa_funnelPreview' ).empty()
            .append( jQuery( '<p class="owa_funnelPreviewEmpty">' ).text( text ) );
    }

    function preview() {

        var all   = readRows();
        var steps = previewSteps( all );

        if ( ! all.some( function ( step ) { return ! isBlankStep( step ); } ) ) {
            previewAsked = '';
            previewMessage( OWA.l( 'Add a step to see how many sessions reach it.' ) );
            return;
        }

        if ( steps === null ) {
            return;
        }

        var url = previewUrl( PREVIEW_URL, steps, filters.get() );

        // Nothing it counts has changed.
        if ( url === previewAsked ) {
            return;
        }

        previewAsked = url;

        if ( previewRequest ) {
            previewRequest.abort();
        }

        jQuery( '#owa_funnelPreview' ).addClass( 'is-loading' );

        previewRequest = jQuery.getJSON( url )
            .done( function ( answer ) {

                if ( answer && answer.error ) {
                    previewMessage( answer.error );
                    return;
                }

                drawPreview( ( ( answer && answer.data ) || {} ).steps || [] );
            } )
            .fail( function ( xhr, status ) {

                if ( status !== 'abort' ) {
                    previewAsked = '';
                    previewMessage( OWA.l( 'The preview could not be counted.' ) );
                }
            } )
            .always( function () {
                jQuery( '#owa_funnelPreview' ).removeClass( 'is-loading' );
            } );
    }

    function drawPreview( steps ) {

        var $box  = jQuery( '#owa_funnelPreview' ).empty();
        var first = steps.length ? steps[0].reached : 0;

        if ( ! steps.length ) {
            previewMessage( OWA.l( 'These steps could not be counted.' ) );
            return;
        }

        var $steps = jQuery( '<ol class="owa_funnelPreviewSteps">' );

        steps.forEach( function ( step, i ) {

            var $count = jQuery( '<span class="owa_funnelPreviewCount">' )
                .append( jQuery( '<b>' ).text( formatCount( step.reached ) ) );

            if ( i > 0 ) {
                $count.append( jQuery( '<span class="owa_funnelPreviewRate">' )
                    .text( ' · ' + say( OWA.l( '%s continued' ), percent( step.reached, steps[ i - 1 ].reached ) ) ) );
            }

            $steps.append( jQuery( '<li class="owa_funnelPreviewStep">' )
                .append( jQuery( '<span class="owa_funnelPreviewHead">' )
                    .append( jQuery( '<span class="owa_funnelPreviewName">' )
                        .append( jQuery( '<span class="owa_funnelPreviewNumber">' ).text( i + 1 ) )
                        .append( document.createTextNode( ' ' + step.name ) ) )
                    .append( $count ) )
                .append( jQuery( '<span class="owa_funnelPreviewBar" aria-hidden="true">' )
                    .append( jQuery( '<span class="owa_funnelPreviewFill">' )
                        .css( 'width', ( first > 0 ? step.reached / first * 100 : 0 ).toFixed( 1 ) + '%' ) ) ) );
        } );

        $box.append( $steps );

        if ( steps.length > 1 ) {

            var last = steps[ steps.length - 1 ];
            // The sentence around the bold percentage: the parts before and after %1$s.
            var parts = String( OWA.l( '%1$s of sessions that reached %2$s went on to reach %3$s.' ) ).split( '%1$s' );
            var names = [ '', steps[0].name, last.name ];

            $box.append( jQuery( '<p class="owa_funnelPreviewSummary">' )
                .append( document.createTextNode( sayEach( parts[0], names ) ) )
                .append( jQuery( '<b>' ).text( percent( last.reached, first ) ) )
                .append( document.createTextNode( sayEach( parts.slice( 1 ).join( '%1$s' ), names ) ) ) );
        }
    }

    // ---- the steps --------------------------------------------------------

    function addStep() {

        if ( rows().length >= MAX_STEPS ) {
            return;
        }

        var $row = jQuery( jQuery( '#owa_funnelStepTemplate' ).html() ).filter( '.owa_funnelStep' );

        $list.append( $row );
        bindStep( $row[0] );
        syncRows();
        refresh();

        $row.find( '.owa_funnelConditionValue' ).first().trigger( 'focus' );
    }

    function moveStep( from, to ) {

        var $rows = rows();

        if ( from === to || to < 0 || to >= $rows.length ) {
            return false;
        }

        var $moved = $rows.eq( from ).detach();

        if ( to >= rows().length ) {
            $list.append( $moved );
        } else {
            rows().eq( to ).before( $moved );
        }

        syncRows();
        refresh();

        return true;
    }

    var dragFrom = null;

    jQuery( '#owa_funnelAddStep' ).on( 'click', addStep );

    $list
        /*
         * Anything that changes what a step matches makes it a different step:
         * its stored name goes, and it is named for its page or event again.
         */
        .on( 'input change', '.owa_funnelStepEvent, .owa_funnelStepCustomEvent, .owa_funnelStepMatch, '
            + '.owa_funnelConditionProperty, .owa_funnelConditionOperator, .owa_funnelConditionValue', function () {
            jQuery( this ).closest( '.owa_funnelStep' ).find( '.owa_funnelStepName' ).val( '' );
            refresh();
        } )
        // A condition added or removed by its + and X.
        .on( 'owa:rowadded', '.owa_funnelCondition', function () {
            syncRows();
            refresh();
        } )
        .on( 'click', '.owa_funnelConditions .constraintRemoveButton', function () {
            window.setTimeout( refresh, 0 );
        } )
        .on( 'click', '.owa_funnelStepRemove', function () {

            var at = jQuery( this ).closest( '.owa_funnelStep' ).index();

            jQuery( this ).closest( '.owa_funnelStep' ).remove();

            if ( ! rows().length ) {
                addStep();
                return;
            }

            syncRows();
            refresh();

            rows().eq( Math.min( at, rows().length - 1 ) ).find( '.owa_funnelStepRemove' ).trigger( 'focus' );
        } )

        // Moving a step: its handle and the arrow keys...
        .on( 'keydown', '.owa_builderMove', function ( e ) {

            var step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[ e.key ];

            if ( ! step ) {
                return;
            }

            e.preventDefault();

            var from = jQuery( this ).closest( '.owa_funnelStep' ).index();

            if ( moveStep( from, from + step ) ) {
                rows().eq( from + step ).find( '.owa_builderMove' ).trigger( 'focus' );
                jQuery( '#owa_funnelMoved' ).text(
                    sayEach( OWA.l( 'Step moved to position %1$s of %2$s.' ), [ from + step + 1, rows().length ] ) );
            }
        } )

        /*
         * ...or dragging a step onto another: it takes that step's place.
         * Draggable only while its handle is held, so the text in its field
         * can still be selected.
         */
        .on( 'mousedown', '.owa_builderMove', function () {
            jQuery( this ).closest( '.owa_funnelStep' ).attr( 'draggable', 'true' );
        } )
        .on( 'mouseup', '.owa_builderMove', function () {
            jQuery( this ).closest( '.owa_funnelStep' ).removeAttr( 'draggable' );
        } )
        .on( 'dragstart', '.owa_funnelStep', function ( e ) {

            dragFrom = jQuery( this ).index();

            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData( 'text/plain', String( dragFrom ) );

            jQuery( this ).addClass( 'is-dragging' );
        } )
        .on( 'dragover', '.owa_funnelStep', function ( e ) {

            if ( dragFrom === null ) {
                return;
            }

            e.preventDefault();
            rows().removeClass( 'is-dropTarget' );
            jQuery( this ).not( '.is-dragging' ).addClass( 'is-dropTarget' );
        } )
        .on( 'drop', '.owa_funnelStep', function ( e ) {

            e.preventDefault();

            if ( dragFrom !== null ) {
                moveStep( dragFrom, jQuery( this ).index() );
            }

            dragFrom = null;
        } )
        .on( 'dragend', '.owa_funnelStep', function () {
            dragFrom = null;
            rows().removeClass( 'is-dragging is-dropTarget' ).removeAttr( 'draggable' );
        } );

    jQuery( '#owa_funnelName' ).on( 'input', refresh );

    jQuery( '#owa_funnelForm' ).on( 'submit', function ( e ) {

        jQuery( '#owa_funnelConstraints' ).val( filters.get() );

        if ( blocker() ) {
            e.preventDefault();
            refresh();
            return false;
        }
    } );

    rows().each( function () {
        bindStep( this );
    } );

    syncRows();
    refresh();
}

/** The screen starts where the template has handed its data over. */
jQuery( function () {

    var holder = document.querySelector( CONFIG_SELECTOR );

    if ( holder ) {

        start( JSON.parse( holder.textContent || '{}' ) );
    }
} );

export { OWA };
