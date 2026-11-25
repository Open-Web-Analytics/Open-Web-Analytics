/**
 * Read the report table's filter conditions and limits out of its source:
 * the `OWA.dataGrid.<NAME> = <literal>;` assignments in
 * modules/Base/src/reporting/owa.resultSetExplorer.js.
 *
 * Only each assignment's literal is evaluated, never the file: the grid needs
 * jQuery and a document, and its constants are plain literals.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { literalEnd, ROOT } = require( './tracker_api.js' );

const SOURCE = path.join( ROOT, 'modules/Base/src/reporting/owa.resultSetExplorer.js' );

/** The value of `OWA.dataGrid.<name> = <literal>;`, read from the source. */
function gridConstant( name, src = fs.readFileSync( SOURCE, 'utf8' ) ) {

    const m = new RegExp( '^OWA\\.dataGrid\\.' + name + '\\s*=\\s*', 'm' ).exec( src );

    if ( ! m ) {
        throw new Error( 'OWA.dataGrid.' + name + ' is not assigned in ' + path.relative( ROOT, SOURCE ) );
    }

    const start = m.index + m[0].length;

    // eslint-disable-next-line no-new-func
    return new Function( 'return ' + src.slice( start, literalEnd( src, start ) ) )();
}

/** "a, b or c" */
function either( words ) {

    return words.length > 1 ? words.slice( 0, -1 ).join( ', ' ) + ' or ' + words[ words.length - 1 ] : words.join( '' );
}

/** What each kind of value (OWA.dataGrid.valueKind()) is called in the table. */
const FIELDS = {
    text: 'A text dimension',
    number: 'A number dimension',
    date: 'A date dimension',
    boolean: 'A Yes/No dimension',
    metric: 'A metric',
};

/** Field | Conditions: what a filter form offers, by the kind of value the field takes. */
function renderConditions() {

    const conditions = gridConstant( 'CONDITIONS' );
    let out = '| Field | Conditions |\n|---|---|\n';

    for ( const kind of Object.keys( conditions ) ) {
        out += '| ' + ( FIELDS[ kind ] || kind ) + ' | ' + conditions[ kind ].map( ( c ) => '**' + c[1] + '**' ).join( ', ' ) + ' |\n';
    }

    return out;
}

/** Rows per page, and how many dimensions the headings let a reader choose. */
function renderLimits() {

    return '| Control | Offers |\n|---|---|\n'
        + '| **Rows per page** | ' + either( gridConstant( 'PAGE_SIZES' ).map( String ) ) + ' |\n'
        + '| Dimension pickers in the headings | On a table grouped by up to ' + gridConstant( 'MAX_DIMENSIONS' ) + ' dimensions |\n';
}

module.exports = { gridConstant, renderConditions, renderLimits, SOURCE, FIELDS };
