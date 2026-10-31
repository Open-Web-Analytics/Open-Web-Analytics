// The explorer imports jQuery as a namespace and calls it. Webpack makes that
// callable; babel-jest's interop does only when jQuery is marked an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js');

/**
 * A grid's row link skips the values it is told to.
 *
 * Page Detail's Next Pages card links each next page to that page's Page
 * Detail, and (exit) is not a page: there is nothing for it to open.
 */

function row(path) {
    return { nextPagePath: { name: 'nextPagePath', value: path, formatted_value: path } };
}

function linked(except, paths = ['/pricing', '(exit)']) {
    const explorer = new OWA.resultSetExplorer('grid');
    explorer.resultSet = { resultsRows: paths.map(row) };
    explorer.addLinkToColumn('nextPagePath', '?do=base.report&reportId=document&pagePath=%s', ['nextPagePath'], except);
    explorer.applyLinks();

    return explorer.resultSet.resultsRows.map((r) => r.nextPagePath.link);
}

test('a value in except is not linked; the others are', () => {
    expect(linked(['(exit)'])).toEqual([
        '?do=base.report&reportId=document&pagePath=%2Fpricing',
        undefined,
    ]);
});

test('a row after a skipped one is still linked', () => {
    expect(linked(['(exit)'], ['(exit)', '/docs'])).toEqual([
        undefined,
        '?do=base.report&reportId=document&pagePath=%2Fdocs',
    ]);
});

test('without except every row is linked, as before', () => {
    expect(linked(undefined).filter(Boolean)).toHaveLength(2);

});
