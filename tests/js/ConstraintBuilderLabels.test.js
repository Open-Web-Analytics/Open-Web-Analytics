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
 * The funnel filter's operator picker shows its labels translated: they are
 * the catalogued tables OWA.constraintBuilder.OPERATORS and EMPTY_TESTS.
 */
describe('the constraint builder operator picker', () => {

    afterEach(() => {
        delete OWA.config.strings;
        document.body.innerHTML = '';
    });

    test('shows each operator and empty test translated, keyed by the operator', () => {
        OWA.config.strings = { 'Contains': 'Enthält', 'Is Set': 'Hat einen Wert' };
        document.body.innerHTML = '<div id="ops"></div>';
        $.fn.chosen = function () { return this; };

        new OWA.constraintBuilder('#x', {}).makeOperatorPicker('#ops');

        const options = Array.from(document.querySelectorAll('#ops option')).map((o) => [o.value, o.textContent]);

        expect(options).toEqual([
            ['==', 'Exactly Matching'],
            ['!=', 'Not Matching'],
            ['>', 'Greater than'],
            ['<', 'Less than'],
            ['=@', 'Enthält'],
            ['!=(not set)', 'Hat einen Wert'],
            ['==(not set)', 'Is Not Set'],
        ]);
    });
});
