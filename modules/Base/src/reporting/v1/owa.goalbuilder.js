import $ from 'jquery';

/**
 * A goal event's condition builder: the property list follows the event.
 *
 * The page renders the properties of the SAVED event (goal_event_edit.php) and
 * carries every event's list as JSON beside it, so changing the event rebuilds
 * each condition's picker here instead of offering page-view properties for a
 * click until the form is saved and reloaded.
 *
 * A SAVED condition's property stays when the new event does not carry it,
 * labelled "not carried by <event>" -- as GoalEventEdit::conditionProperties()
 * does on the server. Dropping it would rewrite a stored condition to whatever
 * came first in the list on the next save. A property picked on this page and
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

    const list = ( vocabulary[ event ] || [] ).filter( ( p ) => p.carried !== false );

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
 */
export function formatResult( li ) {

    if ( li.querySelector( '.owa_goalOptionName' ) ) {

        return;
    }

    const html = li.innerHTML;
    const at = html.indexOf( SEPARATOR );

    if ( at < 0 ) {

        return;
    }

    li.innerHTML = '<span class="owa_goalOptionName">' + html.slice( 0, at ) + '</span>'
        + '<span class="owa_goalOptionDescription">' + html.slice( at + SEPARATOR.length ) + '</span>';
}

/**
 * Keep a picker's list split while chosen redraws it.
 *
 * chosen rewrites every item on each keystroke of a search, and says nothing
 * when it does, so the list is watched rather than hooked.
 */
function splitResults( select ) {

    const container = select.nextElementSibling;
    const results = container && container.querySelector( '.chosen-results' );

    if ( ! results || results.dataset.owaSplit || typeof MutationObserver === 'undefined' ) {

        return;
    }

    results.dataset.owaSplit = '1';

    new MutationObserver( () => {

        results.querySelectorAll( 'li.active-result' ).forEach( formatResult );

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

function readVocabulary() {

    const el = document.getElementById( 'owa_goalVocabulary' );

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
function syncRow( row, event ) {

    const select = row.querySelector( 'select.owa_goalProperty' );

    if ( ! select ) {

        return;
    }

    const property = selectedProperty( select );
    const shown = row.querySelector( '.chosen-single > span' );

    if ( shown && property ) {

        shown.textContent = property.label;
    }

    const help = row.querySelector( '.owa_goalConditionHelp' );

    if ( help ) {

        help.textContent = helpText( property, event );
        help.classList.toggle( 'owa_goalConditionWarning', !! property && property.carried === false );
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
function buildRow( row, vocabulary, event, onLoad = false ) {

    const select = row.querySelector( 'select.owa_goalProperty' );

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
        splitResults( select );
    } else {

        refresh( select );
    }

    syncRow( row, event );
}

function bind() {

    const builder = document.querySelector( '.owa_goalBuilder' );
    const vocabulary = readVocabulary();

    if ( ! builder || ! vocabulary ) {

        return;
    }

    setText( { notCarriedLabel: builder.dataset.notCarriedLabel, notCarriedHelp: builder.dataset.notCarriedHelp } );

    const trigger = builder.querySelector( 'select.owa_goalTrigger' );
    const event = () => ( trigger ? trigger.value : '' );
    const rows = () => builder.querySelectorAll( '.owa_goalCondition' );

    rows().forEach( ( row ) => buildRow( row, vocabulary, event(), true ) );

    if ( trigger ) {

        enhance( trigger, { search_contains: true, width: '190px', disable_search_threshold: 8 } );

        $( trigger ).on( 'change', () => {

            rows().forEach( ( row ) => buildRow( row, vocabulary, event() ) );
        } );
    }

    $( builder ).on( 'change', 'select.owa_goalProperty', function () {

        syncRow( this.closest( '.owa_goalCondition' ), event() );
    } );

    // A row the + button added: a fresh picker on the first property.
    $( builder ).on( 'owa:rowadded', '.owa_goalCondition', function () {

        const select = this.querySelector( 'select.owa_goalProperty' );

        if ( select ) {

            select.value = '';
        }

        // A copy of a saved row is not that condition.
        this.dataset.savedProperty = '';

        buildRow( this, vocabulary, event() );
    } );
}

if ( typeof document !== 'undefined' ) {

    if ( document.readyState === 'loading' ) {

        document.addEventListener( 'DOMContentLoaded', bind );
    } else {

        bind();
    }
}
