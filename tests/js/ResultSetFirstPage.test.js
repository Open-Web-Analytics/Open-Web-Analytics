// The explorer imports jQuery as a namespace and calls it; see DataGrid.test.js.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/owa.resultSetExplorer.js');

/**
 * A change of filter or grouping requests the first page; a page change
 * keeps the rest of the query.
 */
describe('the page a requery asks for', () => {

    const self = 'https://owa.test/api/index.php?do=reports&dimensions=pagePath&page=3&resultsPerPage=25';
    let explorer;
    let requested;

    beforeEach(() => {
        document.body.innerHTML = '<div id="grid"></div>';
        OWA.setSetting('baseUrl', 'https://owa.test/');
        explorer = new OWA.resultSetExplorer('grid');
        explorer.resultSet = { self: self };
        jest.spyOn(explorer, 'getNewResultSet').mockImplementation((u) => { requested = new URL(u).searchParams; });
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('a new filter drops the page', () => {
        explorer.changeConstraints('pagePath=~blog');
        expect(requested.get('constraints')).toBe('pagePath=~blog');
        expect(requested.has('page')).toBe(false);
        expect(requested.get('resultsPerPage')).toBe('25');
    });

    test('a new dimension list drops the page', () => {
        explorer.setDimensionList(['pagePath', 'browserType']);
        expect(requested.get('dimensions')).toBe('pagePath,browserType');
        expect(requested.has('page')).toBe(false);
    });

    test('a swapped dimension drops the page', () => {
        explorer.changeDimension('', 'browserType');
        expect(requested.get('dimensions')).toBe('pagePath,browserType');
        expect(requested.has('page')).toBe(false);
    });

    test('a page change keeps the filter and sets the page', () => {
        explorer.changePage(4);
        expect(requested.get('page')).toBe('4');
        expect(requested.get('dimensions')).toBe('pagePath');
    });
});
