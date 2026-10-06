/**
 * Sections `tracker-methods` and `tracker-options`: the tracker's public
 * commands and setOption() names, read from the `@command` / `@option` JSDoc
 * tags in modules/<module>/src/tracker/. See ../lib/tracker_api.js.
 *
 *   node tests/tools/wiki/sections/tracker.js methods
 *   node tests/tools/wiki/sections/tracker.js options
 *   node tests/tools/wiki/sections/tracker.js           both
 */

const { renderMethods, renderOptions } = require( '../lib/tracker_api.js' );

const which = process.argv[2];

if ( which === 'methods' ) {
    process.stdout.write( renderMethods() );
} else if ( which === 'options' ) {
    process.stdout.write( renderOptions() );
} else if ( which === undefined ) {
    process.stdout.write( '## Commands\n\n' + renderMethods() + '\n## Options\n\n' + renderOptions() );
} else {
    process.stderr.write( 'tracker section takes methods or options, got: ' + which + '\n' );
    process.exit( 1 );
}
