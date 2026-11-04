/**
 * Sections `report-table-conditions` and `report-table-limits`: the report
 * table's filter conditions, and its rows-per-page and dimension-picker
 * limits, read from the grid's constants. See ../lib/report_table.js.
 *
 *   node tests/tools/wiki/sections/report-table.js conditions
 *   node tests/tools/wiki/sections/report-table.js limits
 *   node tests/tools/wiki/sections/report-table.js            both
 */

const { renderConditions, renderLimits } = require( '../lib/report_table.js' );

const which = process.argv[2];

if ( which === 'conditions' ) {
    process.stdout.write( renderConditions() );
} else if ( which === 'limits' ) {
    process.stdout.write( renderLimits() );
} else if ( which === undefined ) {
    process.stdout.write( renderConditions() + '\n' + renderLimits() );
} else {
    process.stderr.write( 'report-table section takes conditions or limits, got: ' + which + '\n' );
    process.exit( 1 );
}
