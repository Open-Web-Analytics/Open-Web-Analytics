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
 * A report request that fails clears the spinner and says so, instead of
 * leaving the spinner up for good.
 */
describe('a result set request that fails', () => {

    let fail;

    beforeEach(() => {
        document.body.innerHTML = '<div id="grid"></div>';
        OWA.setSetting('baseUrl', 'https://owa.test/');
        jest.spyOn($, 'getJSON').mockImplementation(() => {
            const d = $.Deferred();
            fail = () => d.reject({ status: 500 }, 'error');
            return d.promise();
        });
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('takes the spinner down and shows a message', () => {
        const explorer = new OWA.resultSetExplorer('grid');

        explorer.load('https://owa.test/api/index.php?do=reports');
        expect($('#grid .loader').length).toBe(1);

        fail();

        expect($('#grid .loader').length).toBe(0);
        expect($('#grid .owa_loadError').text()).toBe('This report could not be loaded. Try again in a moment.');
        // Announced as it appears.
        expect($('#grid .owa_loadError').attr('role')).toBe('alert');
    });

    test('the spinner is a status that says Loading; the image itself says nothing', () => {
        const explorer = new OWA.resultSetExplorer('grid');

        explorer.load('https://owa.test/api/index.php?do=reports');

        expect($('#grid .loader').attr('role')).toBe('status');
        expect($('#grid .loader img').attr('alt')).toBe('');
        expect($('#grid .loader img').attr('src')).toBe('https://owa.test/public/base/i/loader.gif');
        expect($('#grid .loader').text()).toBe('Loading');
    });

    test('a failed requery says so once, and a later answer clears it', () => {
        const explorer = new OWA.resultSetExplorer('grid');
        explorer.resultSet = { self: 'https://owa.test/api/index.php?do=reports' };

        explorer.getNewResultSet();
        fail();
        explorer.getNewResultSet();
        fail();

        expect($('#grid .owa_loadError').length).toBe(1);

        $.getJSON.mockImplementation((u, d, done) => {
            done({ guid: 'x' });
            return $.Deferred().resolve().promise();
        });
        jest.spyOn(explorer, 'setResultSet').mockImplementation(() => {});
        explorer.getNewResultSet();

        expect($('#grid .owa_loadError').length).toBe(0);
    });

    /* A card's Show more waits for rows; a failed requery tells it to stop waiting. */
    test('a failed requery is published to the grid', () => {
        const explorer = new OWA.resultSetExplorer('grid');
        explorer.resultSet = { self: 'https://owa.test/api/index.php?do=reports' };
        explorer.registerDataChangeSubscriber('grid');

        const heard = jest.fn();
        $('#grid').on('result_set_error', heard);

        explorer.getNewResultSet();
        expect(heard).not.toHaveBeenCalled();

        fail();
        expect(heard).toHaveBeenCalledTimes(1);
    });
});
