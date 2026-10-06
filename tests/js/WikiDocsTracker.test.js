/**
 * The wiki's tracker reference is generated from the `@command` and `@option`
 * tags in the tracker source (tests/tools/wiki/lib/tracker_api.js). Nothing in
 * the code otherwise says which tracker methods are public -- CommandQueue
 * calls whatever the tracker object has -- so these tests hold every method
 * and option that looks like API to a declaration one way or the other:
 * `@command`/`@option`, or `@internal`.
 *
 * A new public method with no docblock fails here rather than going missing
 * from the documentation.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const api = require( '../tools/wiki/lib/tracker_api.js' );

const { ROOT } = api;
const read = ( rel ) => fs.readFileSync( path.join( ROOT, rel ), 'utf8' );

const declared = api.readApi();
const commandNames = new Set( declared.commands.map( ( c ) => c.name ) );
const optionNames = new Set( declared.options.map( ( o ) => o.name ) );
const isDeclaredMethod = ( name ) => commandNames.has( name ) || declared.internal.has( name );
const isDeclaredOption = ( name ) => optionNames.has( name ) || declared.internal.has( name );

/** The keys of the object literal that starts at the first `{` after `marker`. */
function objectKeys( src, marker ) {

    const at = src.indexOf( marker );

    expect( at ).toBeGreaterThan( -1 );

    const start = src.indexOf( '{', at );
    const literal = src.slice( start, api.literalEnd( src, start ) );

    // eslint-disable-next-line no-new-func
    return Object.keys( new Function( 'return ' + literal )() );
}

describe( 'wiki tracker reference', () => {

    test( 'every command and option has a description, and no name is declared twice', () => {

        for ( const item of declared.commands.concat( declared.options ) ) {
            expect( [ item.name, item.description.length > 0 ] ).toEqual( [ item.name, true ] );
        }

        expect( commandNames.size ).toBe( declared.commands.length );
        expect( optionNames.size ).toBe( declared.options.length );
        expect( declared.commands.length ).toBeGreaterThan( 30 );
    } );

    test( 'every setter-shaped tracker method is @command or @internal', () => {

        const src = read( 'modules/Base/src/tracker/Tracker.js' );
        const re = /^ {4}((?:set|track|add|share|delete|pause|restart|check)[A-Za-z]*)\s*\([^)]*\)\s*\{/gm;
        const found = [ ...src.matchAll( re ) ].map( ( m ) => m[1] );

        expect( found ).toContain( 'trackPageView' ); // the pattern matches something
        expect( found.filter( ( name ) => ! isDeclaredMethod( name ) ) ).toEqual( [] );
    } );

    test( 'every method a module plugin adds to the tracker is @command or @internal', () => {

        let seen = 0;

        for ( const { file } of api.sourceFiles() ) {

            const src = fs.readFileSync( file, 'utf8' );

            for ( const m of src.matchAll( /registerPlugin\(\s*\{/g ) ) {

                const at = src.indexOf( 'methods:', m.index );

                if ( at === -1 ) {
                    continue;
                }

                // Methods one level inside `methods: {`, not the code inside them.
                const indent = src.slice( src.lastIndexOf( '\n', at ) + 1, at ) + '    ';
                const start = src.indexOf( '{', at );
                const body = src.slice( start + 1, api.literalEnd( src, start ) - 1 );
                const re = new RegExp( '^' + indent + '([A-Za-z_$][\\w$]*)\\s*\\([^)]*\\)\\s*\\{', 'gm' );
                const names = [ ...body.matchAll( re ) ].map( ( x ) => x[1] );

                seen += names.length;
                expect( names.filter( ( name ) => ! isDeclaredMethod( name ) ) ).toEqual( [] );
            }
        }

        expect( seen ).toBeGreaterThan( 0 );
    } );

    test( 'every option the tracker reads or defaults is @option or @internal', () => {

        const names = new Set();

        for ( const { file } of api.sourceFiles() ) {

            const src = fs.readFileSync( file, 'utf8' );

            for ( const m of src.matchAll( /(?:getOption|this\.option)\(\s*['"]([\w$]+)['"]/g ) ) {
                names.add( m[1] );
            }
        }

        objectKeys( read( 'modules/Base/src/tracker/Tracker.js' ), "'tracker.default_options'" )
            .forEach( ( k ) => names.add( k ) );
        objectKeys( read( 'modules/Domstream/src/tracker/Recorder.js' ), 'const DEFAULTS' )
            .forEach( ( k ) => names.add( k ) );

        expect( names.has( 'disabledFeatures' ) ).toBe( true );
        expect( [ ...names ].filter( ( name ) => ! isDeclaredOption( name ) ) ).toEqual( [] );
    } );

    test( 'every command and option the server writes into a tag is documented', () => {

        const php = read( 'modules/Base/Classes/TrackerBundle.php' ) + read( 'modules/Base/templates/js_log_tag.php' );
        const commands = new Set();
        const options = new Set();

        for ( const m of php.matchAll( /array\(\s*'((?:set|track)[A-Z]\w*)'/g ) ) {
            commands.add( m[1] );
        }

        for ( const m of php.matchAll( /=>\s*'(track[A-Z]\w*)'/g ) ) {
            commands.add( m[1] );
        }

        for ( const m of php.matchAll( /owa_cmds\.push\(\s*\[\s*'(\w+)'/g ) ) {
            commands.add( m[1] );
        }

        for ( const m of php.matchAll( /array\(\s*'setOption',\s*'(\w+)'/g ) ) {
            options.add( m[1] );
        }

        expect( commands.has( 'trackPageView' ) ).toBe( true );
        expect( options.has( 'scrollThresholds' ) ).toBe( true );
        expect( [ ...commands ].filter( ( name ) => ! commandNames.has( name ) ) ).toEqual( [] );
        expect( [ ...options ].filter( ( name ) => ! optionNames.has( name ) ) ).toEqual( [] );
    } );

    test( 'the generated sections list every command and option', () => {

        const methods = api.renderMethods( declared );
        const options = api.renderOptions( declared );

        for ( const c of declared.commands ) {
            expect( methods ).toContain( c.deprecated ? `| \`${ c.name }(` : `\n### ${ c.name }\n` );
        }

        for ( const o of declared.options ) {
            expect( options ).toContain( `| \`${ o.name }\` |` );
        }
    } );

    test( 'an untagged method is not documented, and @internal keeps one out', () => {

        const src = [
            'class T {',
            '    /**',
            '     * Public.',
            '     *',
            '     * @command',
            '     * @param {string} a  the a',
            '     */',
            '    doIt( a, b = 2 ) {',
            '    }',
            '    /** @internal Private. */',
            '    hidden() {',
            '    }',
            '    bare() {',
            '    }',
            '}',
        ].join( '\n' );

        const dir = fs.mkdtempSync( path.join( require( 'os' ).tmpdir(), 'owa-wiki-' ) );
        const file = path.join( dir, 'T.js' );

        fs.writeFileSync( file, src );

        try {

            const parsed = api.readApi( [ { module: 'Example', file } ] );

            expect( parsed.commands.map( ( c ) => c.name ) ).toEqual( [ 'doIt' ] );
            expect( parsed.commands[0].args ).toEqual( [ 'a', 'b' ] );
            expect( parsed.commands[0].params[0] ).toEqual( { type: 'string', name: 'a', description: 'the a' } );
            expect( [ ...parsed.internal ] ).toEqual( [ 'hidden' ] );
            expect( api.renderMethods( parsed ) ).toContain( 'Provided by the Example module.' );

        } finally {
            fs.rmSync( dir, { recursive: true, force: true } );
        }
    } );
} );
