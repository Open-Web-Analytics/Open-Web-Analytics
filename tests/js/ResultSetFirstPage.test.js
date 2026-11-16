// The explorer imports jQuery as a namespace and calls it; see DataGrid.test.js.
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
        jest.spyOn(explorer, 'getNewResultSet').mockImplementation((u) => { requested = new OWA.uri(u); });
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('a new filter drops the page', () => {
        explorer.changeConstraints('pagePath=~blog');
        expect(requested.getQueryParam('constraints')).toBe('pagePath=~blog');
        expect(requested.getQueryParam('page')).toBeUndefined();
        expect(requested.getQueryParam('resultsPerPage')).toBe('25');
    });

    test('a new dimension list drops the page', () => {
        explorer.setDimensionList(['pagePath', 'browserType']);
        expect(requested.getQueryParam('dimensions')).toBe('pagePath,browserType');
        expect(requested.getQueryParam('page')).toBeUndefined();
    });

    test('a swapped dimension drops the page', () => {
        explorer.changeDimension('', 'browserType');
        expect(requested.getQueryParam('dimensions')).toBe('pagePath,browserType');
        expect(requested.getQueryParam('page')).toBeUndefined();
    });

    test('a page change keeps the filter and sets the page', () => {
        explorer.changePage(4);
        expect(requested.getQueryParam('page')).toBe('4');
        expect(requested.getQueryParam('dimensions')).toBe('pagePath');
    });
});
