/**
 * The report date picker: the dates it hands the calendars must be the dates
 * the calendars read back.
 *
 * THE BUG THIS EXISTS FOR
 *
 * The control formats a yyyymmdd into `mm-dd-yy` for the calendars, and
 * declares `dateFormat: 'mm-dd-yy'` when it creates them. Those look like the
 * same thing and are not: in jQuery UI's date format, `y` is a TWO digit year
 * and `yy` is a FOUR digit one. formatYyyymmdd emitted two digits -- 20260727
 * became '07-27-26' -- while the picker was told to expect four.
 *
 * So the calendars were seeded with dates that do not parse back to the period
 * that was chosen. The period LABEL was right, because it is built from the
 * same string and never round-trips; only the calendars were wrong, which is
 * exactly the kind of thing a screenshot does not catch.
 *
 * WHAT IS PINNED
 *
 * Not the format string -- the ROUND TRIP. Whatever the control chooses to emit
 * must parse back, under the format it itself declares, to the date it started
 * from. That holds if someone later switches to ISO, or to four-digit years, or
 * changes the separator; it fails the moment the two disagree again.
 */

// The module under test imports jQuery as an ES module; the tracker tests use
// the same shim so `import * as jQuery` gets the real thing.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

// owa.report.js exports nothing -- it AUGMENTS the OWA object owa.js exports
// (OWA.report = ...). So import OWA from there, and the report module for its
// side effect.
import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';
import '../../modules/Base/src/reporting/v1/owa.report.js';

let $;
let DATE_FORMAT;
let formatYyyymmdd;

beforeAll(() => {

    // The jest environment is already jsdom, so jQuery binds to its window on
    // require. jQuery UI then attaches its widgets -- including datepicker,
    // which is what actually parses these dates in the browser.
    $ = require('jquery');
    global.jQuery = $;
    global.$ = $;
    window.jQuery = $;
    window.$ = $;

    require('jquery-ui-dist/jquery-ui.js');

    // The control renders its markup with jQote2 (jqoteapp), the same plugin
    // reporting-entry.js loads in the browser. Without it the control cannot be
    // built at all, and this file could only ever test the formatter.
    require('../../modules/Base/src/reporting/v1/includes/jquery/jQote2/jquery.jqote2.js');

    const src = require('fs').readFileSync(
        require('path').join(__dirname, '..', '..',
            'modules/Base/src/reporting/v1/owa.report.js'), 'utf8');

    /*
     * The format the control DECLARES when it builds the calendars, read out of
     * the source rather than restated here. Restating it would let the two
     * drift apart again -- which is the whole bug.
     */
    const declared = src.match(/dateFormat:\s*'([^']+)'/);
    DATE_FORMAT = declared ? declared[1] : null;

    /*
     * And the formatter it feeds them with. Taken off the prototype so the test
     * exercises the shipped function, not a copy of it.
     */
    formatYyyymmdd = OWA.report.timePeriodControl.prototype.formatYyyymmdd;
});

/** Every fixed period the picker offers, as the server resolves them. */
const PERIODS = [
    ['today', '20260825', '20260825'],
    ['yesterday', '20260824', '20260824'],
    ['this_week', '20260823', '20260829'],
    ['this_month', '20260801', '20260831'],
    ['this_year', '20260101', '20261231'],
    ['last_week', '20260816', '20260823'],
    ['last_month', '20260701', '20260731'],
    ['last_year', '20250101', '20251231'],
    ['last_seven_days', '20260818', '20260825'],
    ['last_thirty_days', '20260727', '20260825'],
    ['same_week_last_year', '20250823', '20250829'],
    ['same_month_last_year', '20250801', '20250831'],
];

function parse(value) {
    return $.datepicker.parseDate(DATE_FORMAT, value);
}

function asYyyymmdd(date) {
    return $.datepicker.formatDate('yymmdd', date);
}

test('the control declares a date format', () => {
    expect(DATE_FORMAT).toBeTruthy();
});

describe('a formatted date parses back to the date it came from', () => {

    test.each(PERIODS)('%s', (period, start, end) => {

        for (const yyyymmdd of [start, end]) {

            const formatted = formatYyyymmdd(yyyymmdd);

            let parsed;

            expect(() => { parsed = parse(formatted); })
                .not.toThrow();

            expect(asYyyymmdd(parsed)).toBe(yyyymmdd);
        }
    });
});

describe('the calendars stay in order', () => {

    test.each(PERIODS)('%s: end is not before start', (period, start, end) => {

        const parsedStart = parse(formatYyyymmdd(start));
        const parsedEnd = parse(formatYyyymmdd(end));

        expect(parsedEnd.getTime()).toBeGreaterThanOrEqual(parsedStart.getTime());
    });
});

/**
 * The year is the part that broke, so it is checked on its own: a date landing
 * in the year 26 rather than 2026 still parses, still orders correctly against
 * another date that is equally wrong, and is still nonsense on screen.
 */
describe('the year survives the round trip', () => {

    test.each(PERIODS)('%s', (period, start, end) => {

        for (const yyyymmdd of [start, end]) {

            const year = Number(yyyymmdd.slice(0, 4));

            expect(parse(formatYyyymmdd(yyyymmdd)).getFullYear()).toBe(year);
        }
    });
});

/**
 * The widget itself, not just the formatter.
 *
 * The formatter round trip above was necessary and not sufficient: the calendars
 * are INLINE, and defaultDate only decides which month opens on an empty field
 * -- it never selects a date. So both calendars sat with nothing selected,
 * highlighting today, whatever period was named beside them. A test that only
 * checked the string could not see that, which is why this drives the control.
 */
describe('the control selects the period on both calendars', () => {

    function build(start, end) {

        document.body.innerHTML = '<div id="owa_reportPeriodLabelContainer"></div>';

        return new OWA.report.timePeriodControl('#owa_reportPeriodLabelContainer', {
            startDate: start,
            endDate: end,
            selectedPeriod: 'date_range',
        });
    }

    function selected(id) {
        return $('#' + id).datepicker('getDate');
    }

    test.each(PERIODS)('%s', (period, start, end) => {

        build(start, end);

        const gotStart = selected('owa_report-datepicker-start');
        const gotEnd = selected('owa_report-datepicker-end');

        // Null here means nothing is selected, which is the bug: an inline
        // calendar with no date just highlights today.
        expect(gotStart).not.toBeNull();
        expect(gotEnd).not.toBeNull();

        expect(asYyyymmdd(gotStart)).toBe(start);
        expect(asYyyymmdd(gotEnd)).toBe(end);
    });

    test.each(PERIODS)('%s: the end calendar is not before the start', (period, start, end) => {

        build(start, end);

        expect(selected('owa_report-datepicker-end').getTime())
            .toBeGreaterThanOrEqual(selected('owa_report-datepicker-start').getTime());
    });

    /**
     * The two calendars constrain each other from the start, rather than only
     * once something is clicked -- which is how an end could be chosen before
     * its own start.
     */
    test('the calendars bound each other on load', () => {

        build('20260727', '20260825');

        const minOfEnd = $('#owa_report-datepicker-end').datepicker('option', 'minDate');
        const maxOfStart = $('#owa_report-datepicker-start').datepicker('option', 'maxDate');

        // Absent means the calendars do not bound each other at all.
        expect(minOfEnd).toBeTruthy();
        expect(maxOfStart).toBeTruthy();

        expect(asYyyymmdd(new Date(minOfEnd))).toBe('20260727');
        expect(asYyyymmdd(new Date(maxOfStart))).toBe('20260825');
    });

    /** A missing date must not take the picker down. */
    test('an absent date leaves the calendar empty rather than throwing', () => {

        expect(() => build('', '')).not.toThrow();
    });
});

/**
 * Choosing specific dates means the report is no longer on a named period, so
 * the predefined-period menu must stop claiming otherwise. It is the control a
 * reader looks at to know what they are seeing, and leaving "Last Thirty Days"
 * selected beside a custom range says something untrue.
 */
describe('the predefined period menu gives way to a custom range', () => {

    function build(period) {

        document.body.innerHTML = '<div id="owa_reportPeriodLabelContainer"></div>';

        return new OWA.report.timePeriodControl('#owa_reportPeriodLabelContainer', {
            startDate: '20260727',
            endDate: '20260825',
            selectedPeriod: period,
        });
    }

    const PLACEHOLDER = 'Select...';

    test('a named period starts out selected in the menu', () => {

        build('last_thirty_days');

        expect($('#owa_reportPeriodFilter').val()).not.toBe(PLACEHOLDER);
    });

    test('applying a date range returns the menu to its placeholder', () => {

        const control = build('last_thirty_days');

        control.clearFixedPeriodSelection();

        expect($('#owa_reportPeriodFilter').val()).toBe(PLACEHOLDER);
    });

    test('clicking a day on a calendar returns the menu to its placeholder', () => {

        build('last_thirty_days');

        // Drive the calendar's own handler, which is what a click reaches.
        const onSelect = $('#owa_report-datepicker-start').datepicker('option', 'onSelect');

        expect(typeof onSelect).toBe('function');

        onSelect.call($('#owa_report-datepicker-start')[0], '07-29-2026');

        expect($('#owa_reportPeriodFilter').val()).toBe(PLACEHOLDER);
    });

    test('the placeholder is the first option, so it can be selected by index', () => {

        build('last_thirty_days');

        expect($('#owa_reportPeriodFilter option').first().text().trim()).toBe(PLACEHOLDER);
    });
});

/** A separator is only cosmetic: it must not change what the value means. */
test('a different separator does not change the date', () => {

    const withDash = formatYyyymmdd('20260727');
    const withSlash = formatYyyymmdd('20260727', '/');

    expect(asYyyymmdd(parse(withDash)))
        .toBe(asYyyymmdd($.datepicker.parseDate(DATE_FORMAT.replace(/-/g, '/'), withSlash)));
});
