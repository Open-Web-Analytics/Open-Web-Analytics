// The explorer imports jQuery as a namespace and calls it; see DataGrid.test.js.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/owa.kpibox.js');
require('../../modules/Base/src/reporting/owa.resultSetExplorer.js');
require('../../modules/Base/src/reporting/owa.filterpills.js');

/**
 * A metric box's label and the filter pills' groups and dimensions are text: a
 * custom report's widget title or a dimension label holding markup is shown as
 * written, and adds no element. A box's width stays inside its attribute.
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

    test('keeps a widget width given as text inside its style attribute', () => {
        // A saved custom report's boxWidth arrives here as options.width.
        const width = '150px" data-probe="1"><img data-probe="2" src="x';

        draw({ width: width, minWidth: '1px"><b data-probe="3">' });

        const box = document.querySelector('#w .owa_metricInfobox');

        expect(document.querySelector('[data-probe]')).toBeNull();
        expect(box.getAttribute('style')).toBe('--owa-kpi-min-width:1px"><b data-probe="3">;--owa-kpi-width:' + width);
        expect(box.getAttribute('data-metric')).toBe('sessions');
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

describe('the filter pills', () => {

    test('show their groups and dimensions as text, and keep each name as its value', () => {
        document.body.innerHTML = `
            <div id="p"></div><button id="a"></button>
            <div id="f"><select id="d"></select><select id="o"></select><input id="v"><button id="af"></button></div>`;
        $.fn.chosen = function () { return this; };

        const pills = new OWA.filterPills(
            { pills: 'p', add: 'a', form: 'f', dimension: 'd', operator: 'o', value: 'v', addFilter: 'af', cancel: 'c' },
            OWA.filterPills.choices({
                ['Custom <i data-probe="1">x</i>']: [
                    { name: 'cd_plan"><b data-probe="1">', label: MARKUP },
                ],
            }));

        pills.open();

        const group  = document.querySelector('#d optgroup');
        const option = document.querySelector('#d optgroup option');

        expect(group.getAttribute('label')).toBe('Custom <i data-probe="1">x</i>');
        expect(option.value).toBe('cd_plan"><b data-probe="1">');
        expect(option.textContent).toBe(MARKUP + ' (cd_plan"><b data-probe="1">)');
        expect(document.querySelector('[data-probe]')).toBeNull();
    });
});
