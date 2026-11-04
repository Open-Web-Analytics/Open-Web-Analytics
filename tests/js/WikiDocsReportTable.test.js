// The grid imports jQuery as a namespace and calls it; see DataGrid.test.js.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js');

const table = require('../tools/wiki/lib/report_table.js');

/**
 * The wiki's report table sections (tests/tools/wiki/sections/report-table.js)
 * read the grid's constants out of its source without running it. These hold
 * what they read to what the loaded grid has, and the sections to every entry.
 */
describe('wiki report table reference', () => {

    test('the constants read from the source are the ones the grid runs with', () => {

        for (const name of ['CONDITIONS', 'PAGE_SIZES', 'MAX_DIMENSIONS']) {
            expect(table.gridConstant(name)).toEqual(OWA.dataGrid[name]);
        }
    });

    test('every filter condition is listed under its field', () => {

        const md = table.renderConditions();
        const fields = Object.keys(OWA.dataGrid.CONDITIONS);

        expect(fields).toEqual(['dimension', 'metric']);

        for (const field of fields) {

            const row = md.split('\n').find((line) => line.startsWith('| A ' + field + ' |'));

            expect(row).toBeDefined();

            for (const [, label] of OWA.dataGrid.CONDITIONS[field]) {
                expect(row).toContain('**' + label + '**');
            }
        }
    });

    test('every page size and the dimension limit are listed', () => {

        const md = table.renderLimits();

        for (const size of OWA.dataGrid.PAGE_SIZES) {
            expect(md).toMatch(new RegExp('\\| \\*\\*Rows per page\\*\\* \\|[^|]*\\b' + size + '\\b'));
        }

        expect(md).toContain('up to ' + OWA.dataGrid.MAX_DIMENSIONS + ' dimensions');
    });
});
