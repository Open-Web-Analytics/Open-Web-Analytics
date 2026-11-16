// The explorer imports jQuery as a namespace and calls it; see DataGrid.test.js.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/v1/owa.kpibox.js');
require('../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js');

/**
 * A metric box's label and the dimension picker's groups and dimensions are
 * text: a custom report's widget title or a dimension label holding markup is
 * shown as written, and adds no element.
 */

const MARKUP = 'Sales <img src=x data-probe="1"> & "returns"';

afterEach(() => {
    OWA.metricInfo.close();
    document.body.innerHTML = '';
});

describe('a metric box', () => {

    function draw(options) {
        document.body.innerHTML = '<div id="w"></div>';
        new OWA.kpiBox().generate({
            guid: 'g1',
            self: 'https://owa.test/api/index.php?do=reports&metrics=sessions',
            aggregates: { sessions: { name: 'sessions', label: MARKUP, value: 1, formatted_value: '1' } },
            relatedMetrics: {},
        }, 'w', Object.assign({ showSparklines: false }, options || {}));

        return document.querySelector('#w .owa_metricInfoboxLabel');
    }

    test('shows its metric label as text', () => {
        const label = draw();

        expect(label.textContent).toBe(MARKUP);
        expect(document.querySelector('[data-probe]')).toBeNull();
    });

    test('shows a widget title given as its label as text', () => {
        const label = draw({ label: 'Title <b data-probe="1">bold</b>' });

        expect(label.textContent).toBe('Title <b data-probe="1">bold</b>');
        expect(document.querySelector('[data-probe]')).toBeNull();
    });

    test('shows an ordinary label once, not escaped twice', () => {
        document.body.innerHTML = '<div id="w"></div>';
        new OWA.kpiBox().generate({
            guid: 'g2',
            self: 'https://owa.test/api/index.php?do=reports&metrics=sessions',
            aggregates: { sessions: { name: 'sessions', label: 'Sessions & Visits', value: 1, formatted_value: '1' } },
            relatedMetrics: {},
        }, 'w', { showSparklines: false });

        expect(document.querySelector('#w .owa_metricInfoboxLabel').textContent).toBe('Sessions & Visits');
    });
});

describe('the dimension picker', () => {

    test('shows its groups and dimensions as text, and keeps each name as its value', () => {
        document.body.innerHTML = '<div id="dp"></div>';
        $.fn.chosen = function () { return this; };

        const picker = new OWA.dimensionPicker('#dp');
        picker.setDimensionlist({
            ['Custom <i data-probe="1">x</i>']: [
                { name: 'cd_plan"><b data-probe="1">', label: MARKUP },
            ],
        });
        picker.generateDimList('#dp');

        const group  = document.querySelector('#dp optgroup');
        const option = document.querySelector('#dp optgroup option');

        expect(group.getAttribute('label')).toBe('Custom <i data-probe="1">x</i>');
        expect(option.value).toBe('cd_plan"><b data-probe="1">');
        expect(option.textContent).toBe(MARKUP);
        expect(document.querySelector('[data-probe]')).toBeNull();
    });
});
