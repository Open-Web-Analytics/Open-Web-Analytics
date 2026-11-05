import $ from 'jquery';

/**
 * A condition builder: the property list follows the event. A goal event
 * definition's form has one (bind(), below), and each funnel step has one
 * (owa.funnelbuilder.js), through bindBuilder() with its own class names.
 *
 * The page renders the properties of the SAVED event
 * (goal_event_definition_edit.php) and carries every event's list as JSON
 * beside it, so changing the event rebuilds each condition's picker here
 * instead of offering page-view properties for a click until the form is saved
 * and reloaded.
 *
 * A SAVED condition's property stays when the new event does not carry it,
 * labelled "not carried by <event>" -- as
 * GoalEventDefinitionEdit::conditionProperties() does on the server. Dropping
 * it would rewrite a stored condition to whatever came first in the list on the
 * next save. A property picked on this page and
 * not yet saved is not kept: nothing would be lost, so the picker moves to the
 * new event's list without a warning. The saved property is on the row as
 * data-saved-property; a new or added row has none.
 *
 * The pickers are chosen widgets, searchable, and each option reads
 * "Label -- description" in the list so both are searched and seen. The
 * selected property shows its label alone, with the description on the line
 * under the row.
 *
 *   <script type="application/json" id="owa_goalVocabulary">{ event: [ {name, label, description, carried} ] }</script>
 */

export const SEPARATOR = ' — ';

/**
 * The class names one builder's parts carry. The goal event definition form's
 * are these; a funnel step passes its own to bindBuilder().
 *
 *   trigger            the event picker
 *   custom             the field naming "another event of yours"
 *   condition          a condition row
 *   property           a condition row's property picker
 *   help, warning      the line under a row, and its class when it warns
 *   optionName, optionDescription  the two lines of a picker's list item
 */
export const GOAL_CLASSES = {
    trigger: 'owa_goalTrigger',
    custom: 'owa_goalCustomTrigger',
    condition: 'owa_goalCondition',
    property: 'owa_goalProperty',
    help: 'owa_goalConditionHelp',
    warning: 'owa_goalConditionWarning',
    optionName: 'owa_goalOptionName',
    optionDescription: 'owa_goalOptionDescription',
};

/**
 * The trigger picker's value for "another event of yours", named in the field
 * beside it: GoalEventDefinition::TRIGGER_CUSTOM. Its vocabulary is the one
 * every custom event shares, and an event the vocabulary has no list for is a
 * custom one.
 */
export const CUSTOM_TRIGGER = '(custom)';

/**
 * The words this script writes, as printf patterns. The page passes them
 * translated (data-not-carried-label / -help on .owa_goalBuilder, from
 * CoreAPI::t()); these English ones are the fallback.
 */
const TEXT = {
    notCarriedLabel: '%s -- not carried by %s',
    notCarriedHelp: 'A %s event does not carry this property.',
};

/** Fill %s placeholders in order. */
export function format( pattern, ...args ) {

    let i = 0;

    return String( pattern ).replace( /%s/g, () => String( args[ i++ ] ?? '' ) );
}

/** Use the page's translated patterns. */
export function setText( text ) {

    for ( const key of Object.keys( TEXT ) ) {

        if ( text && typeof text[ key ] === 'string' && text[ key ] !== '' ) {

            TEXT[ key ] = text[ key ];
        }
    }
}

/** The label a property has under any event, without a "not carried" suffix. */
function plainLabel( vocabulary, name ) {

    for ( const event of Object.keys( vocabulary ) ) {

        const hit = ( vocabulary[ event ] || [] ).find( ( p ) => p.name === name && p.carried !== false );

        if ( hit ) {

            return hit.label;
        }
    }

    return name;
}

/**
 * The properties a condition can offer under this event, keeping the saved one.
 *
 * @param  {Object} vocabulary  event => list of {name, label, description, carried}
 * @param  {string} event
 * @param  {string} saved       the property the stored condition names, or '' for
 *                              a condition not yet saved
 * @return {Array}
 */
export function optionsFor( vocabulary, event, saved ) {

    const list = ( vocabulary[ event ] || vocabulary[ CUSTOM_TRIGGER ] || [] ).filter( ( p ) => p.carried !== false );

    if ( saved && ! list.some( ( p ) => p.name === saved ) ) {

        let description = '';

        for ( const e of Object.keys( vocabulary ) ) {

            const hit = ( vocabulary[ e ] || [] ).find( ( p ) => p.name === saved );

            if ( hit ) {

                description = hit.description || '';
                break;
            }
        }

        list.push( {
            name: saved,
            label: format( TEXT.notCarriedLabel, plainLabel( vocabulary, saved ), event ),
            description: description,
            carried: false,
        } );
    }

    return list;
}

/** What a picker's option reads in the list: the label, then what it holds. */
export function optionText( property ) {

    return property.description ? property.label + SEPARATOR + property.description : property.label;
}

/** The line under a row: the description, or why the condition cannot match. */
export function helpText( property, event ) {

    if ( ! property ) {

        return '';
    }

    if ( property.carried === false ) {

        return format( TEXT.notCarriedHelp, event );
    }

    return property.description || '';
}

/**
 * One item of chosen's open list, as two lines: the name, then what it holds.
 *
 * An <option> holds text only, so the list item arrives as "Label -- description"
 * in one run. chosen writes it from the option, already escaped and with any
 * search match wrapped in <em>, so the split is on that markup and keeps the
 * highlighting. Idempotent: an item already split is left alone.
 *
 * @param {HTMLElement} li
 * @param {Object}      classes  the builder's class names (GOAL_CLASSES)
 */
export function formatResult( li, classes = GOAL_CLASSES ) {

    if ( li.querySelector( '.' + classes.optionName ) ) {

        return;
    }

    const html = li.innerHTML;
    const at = html.indexOf( SEPARATOR );

    if ( at < 0 ) {

        return;
    }

    li.innerHTML = '<span class="' + classes.optionName + '">' + html.slice( 0, at ) + '</span>'
        + '<span class="' + classes.optionDescription + '">' + html.slice( at + SEPARATOR.length ) + '</span>';
}

/**
 * Keep a picker's list split while chosen redraws it.
 *
 * chosen rewrites every item on each keystroke of a search, and says nothing
 * when it does, so the list is watched rather than hooked.
 */
function splitResults( select, classes ) {

    const container = select.nextElementSibling;
    const results = container && container.querySelector( '.chosen-results' );

    if ( ! results || results.dataset.owaSplit || typeof MutationObserver === 'undefined' ) {

        return;
    }

    results.dataset.owaSplit = '1';

    new MutationObserver( () => {

        results.querySelectorAll( 'li.active-result' ).forEach( ( li ) => formatResult( li, classes ) );

    } ).observe( results, { childList: true, subtree: true } );
}

/** Replace a <select>'s options, keeping its selection where it survives. */
export function fill( select, options, selected ) {

    while ( select.options.length ) {

        select.remove( 0 );
    }

    for ( const p of options ) {

        const option = new Option( optionText( p ), p.name, false, p.name === selected );

        option.dataset.label = p.label;
        option.dataset.description = p.description || '';
        option.dataset.carried = p.carried === false ? '0' : '1';
        select.add( option );
    }

    if ( ! options.some( ( p ) => p.name === selected ) && select.options.length ) {

        select.selectedIndex = 0;
    }
}

/** The property a picker has selected, read back from its option. */
function selectedProperty( select ) {

    const option = select.options[ select.selectedIndex ];

    return option ? {
        name: option.value,
        label: option.dataset.label || option.text,
        description: option.dataset.description || '',
        carried: option.dataset.carried !== '0',
    } : null;
}

/** The vocabulary a page carries as JSON in the element with this id, or null. */
export function readVocabulary( id = 'owa_goalVocabulary' ) {

    const el = document.getElementById( id );

    if ( ! el ) {

        return null;
    }

    try {

        return JSON.parse( el.textContent || '{}' );

    } catch ( e ) {

        return null;
    }
}

/**
 * Show the selected property as its label, and its description under the row.
 *
 * chosen copies the option's text into its closed box, and the text carries the
 * description so the list can show and search it; the box gets the label back.
 */
function syncRow( row, event, classes ) {

    const select = row.querySelector( 'select.' + classes.property );

    if ( ! select ) {

        return;
    }

    const property = selectedProperty( select );
    const shown = row.querySelector( '.chosen-single > span' );

    if ( shown && property ) {

        shown.textContent = property.label;
    }

    const help = row.querySelector( '.' + classes.help );

    if ( help ) {

        help.textContent = helpText( property, event );
        help.classList.toggle( classes.warning, !! property && property.carried === false );
    }
}

function enhance( select, options ) {

    const $select = $( select );

    if ( typeof $select.chosen === 'function' ) {

        $select.chosen( options );
    }
}

function refresh( select ) {

    $( select ).trigger( 'chosen:updated' );
}

/**
 * Build one row's picker for this event.
 *
 * ON LOAD the row keeps what the server rendered, whatever it is: loading the
 * page is not changing the event. On a CHANGE, a property the new event does
 * not carry is kept only if it is the row's saved one.
 */
function buildRow( row, vocabulary, event, classes, onLoad = false ) {

    const select = row.querySelector( 'select.' + classes.property );

    if ( ! select ) {

        return;
    }

    // Kept and flagged only while the row still names its SAVED property.
    const current = select.value;
    const saved = row.dataset.savedProperty || '';

    const keep = onLoad || current === saved ? current : '';

    fill( select, optionsFor( vocabulary, event, keep ), current );

    if ( ! select.nextElementSibling || ! select.nextElementSibling.classList.contains( 'chosen-container' ) ) {

        enhance( select, { search_contains: true, width: '260px' } );
        splitResults( select, classes );
    } else {

        refresh( select );
    }

    syncRow( row, event, classes );
}

/**
 * The event a goal is triggered by, as the help text names it: the picked
 * event, or the name typed for "another event of yours".
 */
export function triggerName( picked, typed ) {

    return picked === CUSTOM_TRIGGER ? ( String( typed || '' ).trim() || 'custom' ) : picked;
}

/**
 * Make one builder live: its event picker, the custom name field beside it,
 * and its condition rows.
 *
 * Everything is found inside `builder` by the class names given, and the
 * events are delegated from it, so a page can carry several -- a funnel has
 * one per step -- and a builder added later is bound the same way.
 *
 * @param {HTMLElement} builder
 * @param {Object}      vocabulary  event => list of {name, label, description, carried}
 * @param {Object}      classes     the builder's class names (GOAL_CLASSES)
 */
export function bindBuilder( builder, vocabulary, classes = GOAL_CLASSES ) {

    const trigger = builder.querySelector( 'select.' + classes.trigger );
    const custom = builder.querySelector( 'input.' + classes.custom );
    const event = () => triggerName( trigger ? trigger.value : '', custom ? custom.value : '' );
    const rows = () => builder.querySelectorAll( '.' + classes.condition );

    // The name field is for "another event of yours" only.
    const showCustom = () => {

        if ( custom ) {

            custom.hidden = ! trigger || trigger.value !== CUSTOM_TRIGGER;
        }
    };

    rows().forEach( ( row ) => buildRow( row, vocabulary, event(), classes, true ) );
    showCustom();

    if ( trigger ) {

        enhance( trigger, { search_contains: true, width: '190px', disable_search_threshold: 8 } );

        $( trigger ).on( 'change', () => {

            showCustom();
            rows().forEach( ( row ) => buildRow( row, vocabulary, event(), classes ) );
        } );
    }

    $( builder ).on( 'change', 'select.' + classes.property, function () {

        syncRow( this.closest( '.' + classes.condition ), event(), classes );
    } );

    // A row the + button added: a fresh picker on the first property.
    $( builder ).on( 'owa:rowadded', '.' + classes.condition, function () {

        const select = this.querySelector( 'select.' + classes.property );

        if ( select ) {

            select.value = '';
        }

        // A copy of a saved row is not that condition.
        this.dataset.savedProperty = '';

        buildRow( this, vocabulary, event(), classes );
    } );
}

/** The goal event definition form's builder. */
function bind() {

    const builder = document.querySelector( '.owa_goalBuilder' );
    const vocabulary = readVocabulary( 'owa_goalVocabulary' );

    if ( ! builder || ! vocabulary ) {

        return;
    }

    setText( { notCarriedLabel: builder.dataset.notCarriedLabel, notCarriedHelp: builder.dataset.notCarriedHelp } );

    bindBuilder( builder, vocabulary, GOAL_CLASSES );
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {

        document.addEventListener( 'DOMContentLoaded', bind );
    } else {

        bind();
    }
}
