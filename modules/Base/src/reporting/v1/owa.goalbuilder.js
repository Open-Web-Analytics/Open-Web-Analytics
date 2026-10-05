import $ from 'jquery';

/**
 * A goal event's condition builder: the property list follows the event.
 *
 * The page renders the properties of the SAVED event (goal_event_edit.php) and
 * carries every event's list as JSON beside it, so changing the event rebuilds
 * each condition's picker here instead of offering page-view properties for a
 * click until the form is saved and reloaded.
 *
 * A property a condition already names stays when the new event does not carry
 * it, labelled "not carried by <event>" -- as GoalEventEdit::conditionProperties()
 * does on the server. Dropping it would rewrite the condition to whatever came
 * first in the list on the next save.
 *
 * The pickers are chosen widgets, searchable, and each option reads
 * "Label -- description" in the list so both are searched and seen. The
 * selected property shows its label alone, with the description on the line
 * under the row.
 *
 *   <script type="application/json" id="owa_goalVocabulary">{ event: [ {name, label, description, carried} ] }</script>
 */

export const SEPARATOR = ' — ';

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
 * The properties a condition can offer under this event, keeping the one it
 * already names.
 *
 * @param  {Object} vocabulary  event => list of {name, label, description, carried}
 * @param  {string} event
 * @param  {string} selected    the property the condition names now, or ''
 * @return {Array}
 */
export function optionsFor( vocabulary, event, selected ) {

    const list = ( vocabulary[ event ] || [] ).filter( ( p ) => p.carried !== false );

    if ( selected && ! list.some( ( p ) => p.name === selected ) ) {

        let description = '';

        for ( const e of Object.keys( vocabulary ) ) {

            const hit = ( vocabulary[ e ] || [] ).find( ( p ) => p.name === selected );

            if ( hit ) {

                description = hit.description || '';
                break;
            }
        }

        list.push( {
            name: selected,
            label: plainLabel( vocabulary, selected ) + ' -- not carried by ' + event,
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

        return 'A ' + event + ' does not carry this, so the condition cannot match. '
            + 'Choose another property or another event.';
    }

    return property.description || '';
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

/** Build one row's picker for this event. */
function buildRow( row, vocabulary, event ) {

    const select = row.querySelector( 'select.owa_goalProperty' );

    if ( ! select ) {

        return;
    }

    fill( select, optionsFor( vocabulary, event, select.value ), select.value );

    if ( ! select.nextElementSibling || ! select.nextElementSibling.classList.contains( 'chosen-container' ) ) {

        enhance( select, { search_contains: true, width: '260px' } );
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

    const trigger = builder.querySelector( 'select.owa_goalTrigger' );
    const event = () => ( trigger ? trigger.value : '' );
    const rows = () => builder.querySelectorAll( '.owa_goalCondition' );

    rows().forEach( ( row ) => buildRow( row, vocabulary, event() ) );

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
