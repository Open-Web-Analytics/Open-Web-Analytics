// The chooser imports jQuery as a namespace and calls it; see DataGrid.test.js.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/owa.resultSetExplorer.js');
require('../../modules/Base/src/reporting/owa.fieldchooser.js');

/**
 * The metric and dimension chooser (OWA.fieldChooser): it opens on the group
 * names, a group shows its fields under a back row, the search covers every
 * group, and a click on a name picks it and closes the chooser. On a desktop
 * a detail pane describes the field under the pointer; on a phone a sheet,
 * whose rows' info icons describe and whose names pick.
 */

const FIELDS = [
    { name: 'source', label: 'Source', description: 'Where the session came from.', group: 'traffic source', group_label: 'Traffic Source', type: 'string', kind: 'dimension' },
    { name: 'attributedSource', label: 'Attributed Source', description: "Where this session's credit goes.", group: 'traffic source', group_label: 'Traffic Source', type: 'string', kind: 'dimension' },
    { name: 'isOutbound', label: 'Outbound Click', description: 'Whether the click went to a host other than the page it was on.', group: 'event', group_label: 'Event', type: 'boolean', kind: 'dimension' },
    { name: 'sessions', label: 'Sessions', description: 'The number of sessions.', group: 'Site Usage', group_label: 'Site Usage', type: 'distinct_count', kind: 'metric' },
];

let picked;
let closed;

function open(fields = FIELDS, options = {}) {
    document.body.innerHTML = '<button id="t">Field</button>';
    picked = [];
    closed = 0;

    const c = new OWA.fieldChooser(fields, Object.assign({
        title: 'Filter on',
        onPick: (name) => picked.push(name),
        onClose: () => closed++,
    }, options));

    c.open(document.getElementById('t'));

    return c;
}

const groupNames = () => $('.owa_fieldGroup .owa_fieldLabel').map((i, e) => $(e).text()).get();
const fieldNames = () => $('.owa_fieldOption[data-name]').map((i, e) => $(e).attr('data-name')).get();

afterEach(() => {
    document.body.innerHTML = '';
    delete window.matchMedia;
});

describe('on a desktop', () => {

    test('it opens on the group names, each with its count, under a heading per kind', () => {
        open();

        expect(groupNames()).toEqual(['Traffic Source', 'Event', 'Site Usage']);
        expect($('.owa_fieldGroup .owa_fieldCount').map((i, e) => $(e).text()).get()).toEqual(['2', '1', '1']);
        expect($('.owa_fieldHeading').map((i, e) => $(e).text()).get()).toEqual(['Dimensions', 'Metrics']);
        expect($('.owa_fieldSearch').prop('hidden')).toBe(false);
        expect($('.owa_fieldChooser').hasClass('is-atGroups')).toBe(true);
        expect($('.owa_fieldDetail').text()).toBe('');
    });

    test('one kind has no kind headings', () => {
        open(FIELDS.filter((f) => f.kind === 'dimension'));

        expect($('.owa_fieldHeading')).toHaveLength(0);
    });

    test('a group shows its fields under a back row, without the search', () => {
        open();

        $('.owa_fieldGroup').first().trigger('click');

        expect(fieldNames()).toEqual(['source', 'attributedSource']);
        expect($('.owa_fieldBack').text()).toContain('Traffic Source');
        expect($('.owa_fieldSearch').prop('hidden')).toBe(true);

        $('.owa_fieldBack').trigger('click');

        expect(groupNames()).toEqual(['Traffic Source', 'Event', 'Site Usage']);
    });

    test('the detail describes the field under the pointer', () => {
        open(FIELDS, { filtering: true });

        $('.owa_fieldGroup').eq(1).trigger('click');
        $('.owa_fieldOption[data-name="isOutbound"]').trigger('mousemove');

        const detail = $('.owa_fieldDetail').text();

        expect(detail).toContain('Outbound Click');
        expect(detail).toContain('Whether the click went to a host other than the page it was on.');
        expect(detail).toContain('Yes / No');
        expect(detail).toContain('Filters with: Is');
        expect(detail).toContain('API name isOutbound');
    });

    test("a metric's detail says whether its rows add up", () => {
        open();

        $('.owa_fieldGroup').eq(2).trigger('click');

        expect($('.owa_fieldDetail').text()).toContain('rows can add up to more than the total');
    });

    test('the search covers every group, and matches descriptions', () => {
        open();

        $('.owa_fieldSearch').val('credit').trigger('input');

        expect(fieldNames()).toEqual(['attributedSource']);

        $('.owa_fieldSearch').val('zzz').trigger('input');

        expect($('.owa_fieldEmpty').text()).toBe('Nothing matches “zzz”.');
    });

    test('a click on a name picks it and closes the chooser', () => {
        open();

        $('.owa_fieldGroup').first().trigger('click');
        $('.owa_fieldOption[data-name="attributedSource"]').trigger('click');

        expect(picked).toEqual(['attributedSource']);
        expect(closed).toBe(1);
        expect($('.owa_fieldChooser')).toHaveLength(0);
        expect($('#t').attr('aria-expanded')).toBe('false');
    });

    test('keys: Enter opens a group, Backspace goes back, Enter picks', () => {
        open();

        const key = (el, k) => el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true }));

        key($('.owa_fieldSearch')[0], 'ArrowDown');
        key($('.owa_fieldSearch')[0], 'Enter');

        expect(fieldNames()).toEqual(['isOutbound']);

        key($('.owa_fieldList')[0], 'Backspace');

        expect(groupNames()).toHaveLength(3);
        expect($('.owa_fieldOption.is-active .owa_fieldLabel').text()).toBe('Event');

        key($('.owa_fieldSearch')[0], 'ArrowRight');
        key($('.owa_fieldList')[0], 'Enter');

        expect(picked).toEqual(['isOutbound']);
    });

    test('Escape closes it without a pick', () => {
        open();

        $('.owa_fieldSearch')[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

        expect(picked).toEqual([]);
        expect(closed).toBe(1);
    });

    test('what is chosen is marked, in its row and on its group', () => {
        open(FIELDS, { selected: ['source'] });

        expect($('.owa_fieldGroup').first().find('.owa_fieldCount').text()).toBe('✓ 2');

        $('.owa_fieldGroup').first().trigger('click');

        expect($('.owa_fieldOption[data-name="source"]').attr('aria-selected')).toBe('true');
        expect($('.owa_fieldOption[data-name="attributedSource"]').attr('aria-selected')).toBe('false');
    });
});

describe('on a phone', () => {

    beforeEach(() => {
        window.matchMedia = (q) => ({ matches: q === OWA.fieldChooser.PHONE });
    });

    test('it is a sheet, and a row\'s icon shows the field in the panel without picking it', () => {
        open();

        expect($('.owa_fieldChooser').hasClass('owa_fieldSheet')).toBe(true);
        expect($('.owa_fieldScrim')).toHaveLength(1);

        $('.owa_fieldGroup').first().trigger('click');
        $('.owa_fieldOption[data-name="attributedSource"] .owa_fieldInfo').trigger('click');

        expect(picked).toEqual([]);
        expect($('.owa_fieldDetail').text()).toContain("Where this session's credit goes.");
        // The type and the API name fold behind the chevron.
        expect($('.owa_fieldDetail').text()).not.toContain('API name');

        $('.owa_fieldMore').trigger('click');

        expect($('.owa_fieldDetail').text()).toContain('API name attributedSource');
        expect($('.owa_fieldMore').attr('aria-expanded')).toBe('true');

        $('.owa_fieldOption[data-name="source"] .owa_fieldLabel').trigger('click');

        expect(picked).toEqual(['source']);
        expect($('.owa_fieldSheet, .owa_fieldScrim')).toHaveLength(0);
    });

    test('a tap outside the sheet closes it', () => {
        open();

        $('.owa_fieldScrim').trigger('click');

        expect(closed).toBe(1);
    });
});

describe('fields', () => {

    test('fromGroups() reads a result set\'s lists by group', () => {
        expect(OWA.fieldChooser.fromGroups('metric', { 'Site Usage': [{ name: 'sessions', label: 'Sessions', description: 'd', group_label: 'Site Usage', metric_type: 'distinct_count' }] }))
            .toEqual([{ name: 'sessions', label: 'Sessions', description: 'd', group: 'Site Usage', group_label: 'Site Usage', type: 'distinct_count', kind: 'metric' }]);
    });

    test('attach() shows the field picked, or the placeholder', () => {
        document.body.innerHTML = '<button id="b"></button>';

        const b = OWA.fieldChooser.attach($('#b'), { fields: FIELDS, placeholder: 'Choose a field', title: 'Filter on' });

        expect($('#b').text()).toBe('Choose a field');

        b.set('isOutbound');

        expect($('#b').text()).toBe('Outbound Click');
        expect(b.field().type).toBe('boolean');

        $('#b').trigger('click');
        $('.owa_fieldGroup').first().trigger('click');
        $('.owa_fieldOption[data-name="source"]').trigger('click');

        expect(b.get()).toBe('source');
        expect($('#b').text()).toBe('Source');
    });
});
