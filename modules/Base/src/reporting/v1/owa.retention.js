/**
 * The confirmation before a Data Retention form is saved.
 *
 * Shortening the event-data window deletes data at the next maintenance run;
 * shortening or lengthening a reporting window changes what reports show, and
 * lengthening one queues a rebuild. Before such a save the form asks the server
 * what it would do (GET v1/retentionPreview, Classes\Retention::preview()) and
 * shows the answer in OWA.confirmAction(). The wording is the server's, so what
 * is said is what is stored; this file only decides when to ask.
 *
 *   <form data-owa-retention-form="install|property"
 *         data-owa-retention-property="ID"
 *         data-owa-retention-preview="api url">
 *
 * A save that changes no window is not interrupted. One whose preview cannot be
 * fetched is asked about in general terms rather than let through unasked.
 */

const RAW = 'base.raw_retention_months';
const CUBE = 'base.cube_retention_months';

/** The control for one setting, by the end of its name (the namespace prefix varies). */
function control( form, name ) {

    return form.querySelector( '[name$="config[' + name + ']"]' );
}

/**
 * What the form would save, as the preview route takes it.
 *
 * @param {HTMLFormElement} form
 * @return {Object} raw / cube_default (install), cube or cube_inherit (property)
 */
export function readValues( form ) {

    const kind = form.getAttribute( 'data-owa-retention-form' );
    const out = {};

    if ( kind === 'install' ) {

        const raw = control( form, RAW );
        const cube = control( form, CUBE );

        if ( raw && ! raw.disabled ) {
            out.raw = String( raw.value ).trim();
        }

        if ( cube && ! cube.disabled ) {
            out.cube_default = String( cube.value ).trim();
        }

        return out;
    }

    const cube = control( form, CUBE );
    const sw = form.querySelector( '[data-owa-override]' );

    const value = cube ? String( cube.value ).trim() : '';

    // Inheriting: switched off, or -- with nothing set above to override, so
    // no switch -- left blank.
    if ( ( sw && ! sw.checked ) || ( ! sw && value === '' ) ) {

        out.cube_inherit = '1';

    } else if ( cube ) {

        out.cube = value;
    }

    return out;
}

/**
 * The preview request's parameters, or null when nothing would change.
 *
 * @param {Object} initial readValues() at page load
 * @param {Object} now     readValues() at submit
 * @param {string} propertyId
 * @return {Object|null}
 */
export function previewParams( initial, now, propertyId ) {

    const keys = Object.keys( Object.assign( {}, initial, now ) );
    const changed = keys.some( ( k ) => String( initial[ k ] ?? '' ) !== String( now[ k ] ?? '' ) );

    if ( ! changed ) {
        return null;
    }

    const params = Object.assign( {}, now );

    if ( propertyId ) {
        params.property_id = propertyId;
    }

    return params;
}

/**
 * The preview URL with the parameters appended to what makeApiLink() built.
 *
 * @param {string} base
 * @param {Object} params
 * @return {string}
 */
export function previewUrl( base, params ) {

    const query = Object.keys( params )
        .map( ( k ) => encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] ) )
        .join( '&' );

    return base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
}

/** Asked when the preview could not be fetched: no answer is not a yes. */
export const UNKNOWN = {
    needed: true,
    tone: 'danger',
    title: 'Save the retention change?',
    paragraphs: [
        'What this change would do could not be worked out just now.',
        'A shorter event-data window deletes older event data at the next maintenance run, and it cannot be recovered.',
    ],
    proceed: 'Save anyway',
};

/**
 * Ask, when the preview says to, then submit.
 *
 * @param {Object} preview from the route
 * @param {Function} confirm OWA.confirmAction
 * @param {Function} submit  sends the form
 */
export function decide( preview, confirm, submit ) {

    if ( ! preview || ! preview.needed ) {

        submit();
        return;
    }

    confirm( {
        title: preview.title,
        paragraphs: preview.paragraphs,
        proceed: preview.proceed,
        tone: preview.tone,
    }, submit );
}

function bind( form ) {

    const initial = readValues( form );

    form.addEventListener( 'submit', function ( event ) {

        if ( form.getAttribute( 'data-owa-retention-confirmed' ) === '1' ) {

            form.removeAttribute( 'data-owa-retention-confirmed' );
            return;
        }

        const params = previewParams( initial, readValues( form ),
            form.getAttribute( 'data-owa-retention-property' ) || '' );

        if ( ! params ) {
            return;
        }

        event.preventDefault();

        const submitter = event.submitter || null;

        const submit = function () {

            form.setAttribute( 'data-owa-retention-confirmed', '1' );

            // Through the button, so its name and value (the action) are sent.
            if ( typeof form.requestSubmit === 'function' ) {
                form.requestSubmit( submitter || undefined );
            } else {
                form.submit();
            }
        };

        const confirm = ( window.OWA && window.OWA.confirmAction )
            ? window.OWA.confirmAction
            : function ( o, go ) { if ( window.confirm( o.title ) ) { go(); } };

        fetch( previewUrl( form.getAttribute( 'data-owa-retention-preview' ), params ), { credentials: 'same-origin' } )
            .then( ( r ) => ( r.ok ? r.json() : null ) )
            .then( ( data ) => decide( ( data && data.data && data.data.preview ) || UNKNOWN, confirm, submit ) )
            .catch( () => decide( UNKNOWN, confirm, submit ) );
    } );
}

if ( typeof document !== 'undefined' ) {

    const start = function () {
        document.querySelectorAll( 'form[data-owa-retention-form]' ).forEach( bind );
    };

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', start );
    } else {
        start();
    }
}
