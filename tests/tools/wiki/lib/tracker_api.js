/**
 * Read the tracker's public API out of its source: the JSDoc blocks tagged
 * `@command` (a command a tag can push, or call on the tracker object) and
 * `@option` (a name setOption() takes).
 *
 * THE TAG IS THE DECLARATION. CommandQueue dispatches to any method the tracker
 * object has, so nothing in the code says which of them a site may rely on; a
 * method is public because its docblock says so. `@internal` says the opposite,
 * for a method or option that only looks like API, and WikiDocsTracker.test.js
 * holds every setter-shaped method and every option read to one or the other.
 *
 * Tags read:
 *
 *   @command [name]  public. The name defaults to the method the block precedes;
 *                    a queue-level command with no method (pause-owa) names
 *                    itself and lists its arguments with @param.
 *   @option name     a setOption() name. The default is the literal the block
 *                    precedes in a defaults object, or @default when there is none.
 *   @default value
 *   @internal        deliberately not public
 *   @deprecated text
 *   @param {type} name description
 *
 * The description is the first paragraph of the block. Indented lines that
 * contain `owa_cmds.push` are its example.
 *
 * No parser dependency: the blocks, and the line after each, are all this
 * needs, and the tracker's source is written in one consistent style.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '../../../..' );

/** The tracker source files: Base's tracker, and every module's tracker plugin. */
function sourceFiles() {

    const modules = path.join( ROOT, 'modules' );
    const files = [];

    for ( const mod of fs.readdirSync( modules ).sort() ) {

        const dir = path.join( modules, mod, 'src', 'tracker' );

        if ( ! fs.existsSync( dir ) ) {
            continue;
        }

        for ( const f of fs.readdirSync( dir ).sort() ) {

            if ( f.endsWith( '.js' ) ) {
                files.push( { module: mod, file: path.join( dir, f ) } );
            }
        }
    }

    return files;
}

/** Index of the end of the literal starting at src[i]: the next top-level , ; or closing bracket. */
function literalEnd( src, i ) {

    let depth = 0;
    let quote = null;

    for ( ; i < src.length; i++ ) {

        const c = src[ i ];

        if ( quote ) {

            if ( c === '\\' ) {
                i++;
            } else if ( c === quote ) {
                quote = null;
            }

            continue;
        }

        if ( c === '/' && src[ i + 1 ] === '*' ) {

            const close = src.indexOf( '*/', i + 2 );

            i = close === -1 ? src.length : close + 1;

        } else if ( c === '/' && src[ i + 1 ] === '/' ) {

            if ( depth === 0 ) {
                return i; // a trailing line comment ends the literal
            }

            const eol = src.indexOf( '\n', i );

            i = eol === -1 ? src.length : eol;

        } else if ( c === '"' || c === "'" || c === '`' ) {
            quote = c;
        } else if ( c === '[' || c === '{' || c === '(' ) {
            depth++;
        } else if ( c === ']' || c === '}' || c === ')' ) {

            if ( depth === 0 ) {
                return i;
            }

            depth--;

        } else if ( ( c === ',' || c === ';' ) && depth === 0 ) {
            return i;
        }
    }

    return i;
}

/** What a block precedes: a method, an object key, or nothing. */
function subjectAfter( src, end ) {

    const rest = src.slice( end );
    const lead = rest.match( /^\s*/ )[0].length;
    const text = rest.slice( lead );

    let m = text.match( /^(?:static\s+)?(?:async\s+)?([A-Za-z_$][\w$]*)\s*\(([^)]*)\)\s*\{/ );

    if ( m ) {
        return { kind: 'method', name: m[1], params: splitParams( m[2] ) };
    }

    m = text.match( /^([A-Za-z_$][\w$]*)\s*:\s*/ );

    if ( m ) {

        const start = end + lead + m[0].length;
        const value = src.slice( start, literalEnd( src, start ) ).trim().replace( /\s+/g, ' ' );

        return { kind: 'key', name: m[1], value: value.replace( /,\s*([\]}])/g, ' $1' ) };
    }

    return { kind: 'none' };
}

function splitParams( list ) {

    return list.split( ',' ).map( ( p ) => p.replace( /=.*$/, '' ).trim() ).filter( Boolean );
}

/** One docblock's text, tags and example. */
function parseBlock( body ) {

    // A line with a leading `*` keeps its indentation after it, for examples;
    // a one-line block has none.
    const lines = body.split( '\n' )
        .map( ( l ) => ( /^\s*\*/.test( l ) ? l.replace( /^\s*\* ?/, '' ) : l.trim() ).replace( /\s+$/, '' ) );
    const tags = [];
    const prose = [];
    let open = null; // the tag whose text is still continuing

    for ( const line of lines ) {

        const t = line.match( /^@(\w+)\s*(.*)$/ );

        if ( t ) {
            open = { tag: t[1], text: t[2] };
            tags.push( open );
            continue;
        }

        if ( open && line.trim() !== '' && ! /^\s{2,}/.test( line ) ) {
            open.text += ' ' + line.trim();
            continue;
        }

        open = null;
        prose.push( line );
    }

    // First paragraph of the prose.
    const firstPara = [];

    for ( const line of prose ) {

        if ( line.trim() === '' ) {

            if ( firstPara.length ) {
                break;
            }

            continue;
        }

        firstPara.push( line.trim() );
    }

    // Runs of indented lines; a run that pushes a command is an example.
    const runs = [];
    let run = [];

    for ( const line of prose ) {

        if ( /^\s{2,}\S/.test( line ) ) {
            run.push( line );
        } else if ( run.length ) {
            runs.push( run );
            run = [];
        }
    }

    if ( run.length ) {
        runs.push( run );
    }

    const example = runs
        .filter( ( r ) => r.some( ( l ) => l.includes( 'owa_cmds.push' ) ) )
        .map( ( r ) => {
            const indent = Math.min( ...r.map( ( l ) => l.match( /^\s*/ )[0].length ) );
            return r.map( ( l ) => l.slice( indent ) ).join( '\n' );
        } )
        .join( '\n' );

    return { description: firstPara.join( ' ' ), tags, example };
}

function tag( block, name ) {

    const t = block.tags.find( ( x ) => x.tag === name );

    return t ? t.text.trim() : null;
}

function firstWord( text ) {

    return text ? text.split( /\s+/ )[0] : '';
}

function params( block ) {

    return block.tags.filter( ( t ) => t.tag === 'param' ).map( ( t ) => {

        const m = t.text.match( /^(?:\{([^}]*)\}\s+)?(\[?[\w.$-]+\]?)\s*(.*)$/ );

        return m ? { type: m[1] || '', name: m[2], description: m[3].replace( /^[-—]\s*/, '' ) }
                 : { type: '', name: t.text, description: '' };
    } );
}

/** Every docblock in one source, with what it precedes. */
function blocksIn( src ) {

    const out = [];
    const re = /\/\*\*([\s\S]*?)\*\//g;
    let m;

    while ( ( m = re.exec( src ) ) ) {

        const block = parseBlock( m[1] );

        block.subject = subjectAfter( src, m.index + m[0].length );
        out.push( block );
    }

    return out;
}

/**
 * The declared API.
 *
 * @return {{commands: object[], options: object[], internal: Set<string>}}
 *         internal holds the names tagged @internal, methods and options alike
 */
function readApi( files = sourceFiles() ) {

    const commands = [];
    const options = [];
    const internal = new Set();

    for ( const { module, file } of files ) {

        for ( const b of blocksIn( fs.readFileSync( file, 'utf8' ) ) ) {

            const isCommand = b.tags.some( ( t ) => t.tag === 'command' );
            const isOption = b.tags.some( ( t ) => t.tag === 'option' );

            // @internal wins: a block may name several options it covers.
            if ( b.tags.some( ( t ) => t.tag === 'internal' ) ) {

                const named = b.tags.filter( ( t ) => t.tag === 'option' ).map( ( t ) => firstWord( t.text ) );

                for ( const name of named.length ? named : [ b.subject.name ] ) {

                    if ( name ) {
                        internal.add( name );
                    }
                }

                continue;
            }

            if ( isCommand ) {

                const name = firstWord( tag( b, 'command' ) ) || ( b.subject.kind === 'method' ? b.subject.name : '' );
                const declared = params( b );

                commands.push( {
                    name,
                    module,
                    file: path.relative( ROOT, file ),
                    args: b.subject.kind === 'method' && ! tag( b, 'command' )
                        ? b.subject.params : declared.map( ( p ) => p.name ),
                    params: declared,
                    description: b.description,
                    deprecated: tag( b, 'deprecated' ),
                    example: b.example,
                } );
            }

            if ( isOption ) {

                options.push( {
                    name: firstWord( tag( b, 'option' ) ) || b.subject.name,
                    module,
                    file: path.relative( ROOT, file ),
                    default: tag( b, 'default' ) ?? ( b.subject.kind === 'key' ? b.subject.value : null ),
                    description: b.description,
                    deprecated: tag( b, 'deprecated' ),
                } );
            }
        }
    }

    const byName = ( a, b ) => a.name.localeCompare( b.name, 'en', { sensitivity: 'base' } );

    return { commands: commands.sort( byName ), options: options.sort( byName ), internal };
}

const cell = ( s ) => String( s ).replace( /\|/g, '\\|' ).replace( /\n/g, ' ' );

const moduleNote = ( item ) => item.module === 'Base' ? '' : ` Provided by the ${ item.module } module.`;

function renderMethods( api = readApi() ) {

    const current = api.commands.filter( ( c ) => ! c.deprecated );
    const deprecated = api.commands.filter( ( c ) => c.deprecated );
    const signature = ( c ) => `${ c.name }(${ c.args.length ? ' ' + c.args.join( ', ' ) + ' ' : '' })`;
    const anchor = ( c ) => c.name.toLowerCase().replace( /[^a-z0-9-]/g, '' );

    let out = '| Command | Description |\n|---|---|\n';

    for ( const c of current ) {
        out += `| [\`${ c.name }\`](#${ anchor( c ) }) | ${ cell( c.description + moduleNote( c ) ) } |\n`;
    }

    for ( const c of current ) {

        out += `\n### ${ c.name }\n\n\`\`\`js\n${ signature( c ) }\n\`\`\`\n\n${ c.description }${ moduleNote( c ) }\n`;

        if ( c.params.length ) {

            out += '\n';

            for ( const p of c.params ) {
                out += `- \`${ p.name }\`${ p.type ? ` (${ p.type })` : '' }${ p.description ? ' — ' + p.description : '' }\n`;
            }
        }

        if ( c.example ) {
            out += `\n\`\`\`js\n${ c.example }\n\`\`\`\n`;
        }
    }

    if ( deprecated.length ) {

        out += '\n### Deprecated commands\n\nThese still work.\n\n'
             + '| Command | Use instead |\n|---|---|\n';

        for ( const c of deprecated ) {
            out += `| \`${ signature( c ) }\` | ${ cell( c.deprecated ) } |\n`;
        }
    }

    return out;
}

function renderOptions( api = readApi() ) {

    let out = '| Option | Default | Description |\n|---|---|---|\n';

    for ( const o of api.options ) {

        const desc = ( o.deprecated ? `**Deprecated.** ${ o.deprecated } ` : '' ) + o.description + moduleNote( o );

        out += `| \`${ o.name }\` | ${ o.default === null || o.default === '' ? '—' : '`' + cell( o.default ) + '`' } | ${ cell( desc ) } |\n`;
    }

    return out;
}

module.exports = { readApi, blocksIn, sourceFiles, literalEnd, renderMethods, renderOptions, ROOT };
