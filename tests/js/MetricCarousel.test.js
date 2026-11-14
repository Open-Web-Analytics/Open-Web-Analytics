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
 * The metric boxes over a trend (OWA.kpiBox): one row that scrolls natively,
 * with an arrow at each end that hides a box, a press that scrolled the row
 * choosing nothing, and a box's description behind its own button.
 */

const API = 'https://owa.test/api/index.php?do=reports&module=base&version=v1';

function resultSet(metrics, extra) {
    const aggregates = {};
    metrics.forEach((m) => { aggregates[m] = { name: m, label: m.toUpperCase(), value: 1, formatted_value: '1' }; });
    return Object.assign({
        guid: 'g' + Math.random().toString(36).slice(2),
        self: API + '&metrics=' + metrics.join(','),
        aggregates,
        relatedMetrics: {
            Usage: [
                { name: 'sessions', label: 'Sessions', description: 'The number of sessions.' },
                { name: 'pageViews', label: 'Page Views', description: '' },
            ],
        },
    }, extra || {});
}

/** A row `client` wide that scrolls to `scroll`, as layout would report it. */
function sized(track, client, scroll) {
    Object.defineProperty(track, 'clientWidth', { configurable: true, value: client });
    Object.defineProperty(track, 'scrollWidth', { configurable: true, value: scroll });
}

function draw(metrics, options, rs) {
    document.body.innerHTML = '<div id="w"></div>';
    const kpi = new OWA.kpiBox();
    kpi.generate(rs || resultSet(metrics), 'w', Object.assign({ showSparklines: false }, options || {}));
    const carousel = document.querySelector('#w > .owa_metricCarousel');
    return {
        kpi,
        carousel,
        track: carousel.querySelector('[data-ui="kpi-row"]'),
        prev: carousel.querySelector('[data-ui="kpi-prev"]'),
        next: carousel.querySelector('[data-ui="kpi-next"]'),
    };
}

afterEach(() => {
    OWA.metricInfo.close();
    document.body.innerHTML = '';
});

describe('which ends hide a box', () => {

    test.each([
        ['everything fits', 0, 400, 400, false, false],
        ['at the start of a row that overflows', 0, 800, 400, false, true],
        ['part way along', 200, 800, 400, true, true],
        ['at the end', 400, 800, 400, true, false],
        ['half a pixel short of the end', 399.5, 800, 400, true, false],
        ['right to left, part way', -200, 800, 400, true, true],
    ])('%s', (name, at, scroll, client, back, forward) => {
        expect(OWA.kpiBox.hiddenEnds(at, scroll, client)).toEqual({ back, forward });
    });
});

describe('where an arrow takes the row', () => {

    const starts = [0, 174, 348, 522, 696];

    test('forward to the next box, back to the one before', () => {
        expect(OWA.kpiBox.nextStop(starts, 0, 1, 500)).toBe(174);
        expect(OWA.kpiBox.nextStop(starts, 174, 1, 500)).toBe(348);
        expect(OWA.kpiBox.nextStop(starts, 348, -1, 500)).toBe(174);
        expect(OWA.kpiBox.nextStop(starts, 174, -1, 500)).toBe(0);
    });

    test('from part way through a box, to a boundary', () => {
        expect(OWA.kpiBox.nextStop(starts, 100, 1, 500)).toBe(174);
        expect(OWA.kpiBox.nextStop(starts, 100, -1, 500)).toBe(0);
    });

    test('never past an end', () => {
        expect(OWA.kpiBox.nextStop(starts, 348, 1, 400)).toBe(400);
        expect(OWA.kpiBox.nextStop(starts, 400, 1, 400)).toBe(400);
        expect(OWA.kpiBox.nextStop(starts, 0, -1, 400)).toBe(0);
    });

    test('a box lands clear of the back arrow', () => {
        expect(OWA.kpiBox.nextStop(starts, 0, 1, 500, 40)).toBe(134);
        expect(OWA.kpiBox.nextStop(starts, 134, 1, 500, 40)).toBe(308);
        expect(OWA.kpiBox.nextStop(starts, 308, -1, 500, 40)).toBe(134);
        expect(OWA.kpiBox.nextStop(starts, 134, -1, 500, 40)).toBe(0);
    });
});

describe('the row', () => {

    test('follows the scroller, however it was moved', () => {
        const { carousel, track, prev, next } = draw(['sessions', 'pageViews', 'bounceRate']);

        sized(track, 300, 600);
        track.scrollLeft = 0;
        track.dispatchEvent(new Event('scroll'));

        expect(carousel.classList.contains('owa_canScrollBack')).toBe(false);
        expect(carousel.classList.contains('owa_canScrollForward')).toBe(true);
        expect(prev.disabled).toBe(true);
        expect(next.disabled).toBe(false);

        // A swipe or a trackpad: no arrow was pressed.
        track.scrollLeft = 300;
        track.dispatchEvent(new Event('scroll'));

        expect(carousel.classList.contains('owa_canScrollBack')).toBe(true);
        expect(carousel.classList.contains('owa_canScrollForward')).toBe(false);
        expect(prev.disabled).toBe(false);
        expect(next.disabled).toBe(true);
    });

    test('an arrow moves the row', () => {
        const { track, next } = draw(['sessions', 'pageViews', 'bounceRate']);

        sized(track, 300, 600);
        track.querySelectorAll('.owa_metricInfobox').forEach((box, i) => {
            Object.defineProperty(box, 'offsetLeft', { configurable: true, value: i * 174 });
        });
        track.scrollLeft = 0;

        next.disabled = false;
        next.click();

        expect(track.scrollLeft).toBe(174);
    });

    test('sizes are custom properties, so the stylesheet can size a box on a phone', () => {
        const { track } = draw(['sessions', 'pageViews'], { width: '150px', minWidth: '100px' });
        const box = track.querySelector('[data-ui="kpi:sessions"]');

        expect(box.style.getPropertyValue('--owa-kpi-width')).toBe('150px');
        expect(box.style.getPropertyValue('--owa-kpi-min-width')).toBe('100px');
        expect(box.style.width).toBe('');
        expect(box.style.minWidth).toBe('');
        expect(box.classList.contains('owa_metricInfoboxFixed')).toBe(true);
    });

    test('a box with no width asked for shares the row', () => {
        const { track } = draw(['sessions']);
        const box = track.querySelector('[data-ui="kpi:sessions"]');

        expect(box.style.getPropertyValue('--owa-kpi-width')).toBe('auto');
        expect(box.classList.contains('owa_metricInfoboxFixed')).toBe(false);
    });
});

describe('a press on a box', () => {

    function chosen() {
        const picks = [];
        $('#w').on('click', '.owa_metricInfobox', function () { picks.push(this.getAttribute('data-metric')); });
        return picks;
    }

    test('a tap chooses the box', () => {
        const { track } = draw(['sessions', 'pageViews']);
        const picks = chosen();
        const box = track.querySelector('[data-ui="kpi:pageViews"]');

        track.scrollLeft = 0;
        box.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        box.click();

        expect(picks).toEqual(['pageViews']);
    });

    test('a swipe that ends over a box does not choose it', () => {
        const { track } = draw(['sessions', 'pageViews']);
        const picks = chosen();
        const box = track.querySelector('[data-ui="kpi:pageViews"]');

        track.scrollLeft = 0;
        box.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        track.scrollLeft = 120;
        box.click();

        expect(picks).toEqual([]);

        // The next tap, with the row still, chooses again.
        box.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        box.click();

        expect(picks).toEqual(['pageViews']);
    });
});

describe('a box\'s description', () => {

    test('a box whose metric has one: its name is the button that opens it', () => {
        const { track } = draw(['sessions', 'pageViews']);

        const name = $(track).find('[data-ui="kpi:sessions"] .owa_metricInfoboxLabel > button');
        expect(name.length).toBe(1);
        expect(name.text()).toBe('SESSIONS');
        expect(name.attr('aria-expanded')).toBe('false');

        // None: the name is text.
        expect($(track).find('[data-ui="kpi:pageViews"] .owa_metricInfoboxLabel > button').length).toBe(0);
        expect($(track).find('[data-ui="kpi:pageViews"] .owa_metricInfoboxLabel').text()).toBe('PAGEVIEWS');
    });

    test('opening it does not chart the metric', () => {
        jest.useFakeTimers();

        const { track } = draw(['sessions', 'pageViews']);
        const changed = [];
        const explorer = {
            areaChart: { changeMetric: (m) => { changed.push(m); return true; }, chartedMetric: () => 'pageViews' },
            markChartedMetric: () => {},
        };

        OWA.resultSetExplorer.prototype.bindMetricBoxes.call(explorer, 'w');

        const name = track.querySelector('[data-ui="kpi:sessions"] .owa_metricInfoboxLabel > button');
        name.click();
        jest.runAllTimers();

        expect(changed).toEqual([]);
        expect(name.getAttribute('aria-expanded')).toBe('true');
        expect(document.getElementById(name.getAttribute('aria-controls')).textContent).toBe('The number of sessions.');

        // A press on the description is not a press on the box either.
        document.getElementById(name.getAttribute('aria-controls')).click();
        expect(changed).toEqual([]);

        // The rest of the box still does.
        track.querySelector('[data-ui="kpi:sessions"] .owa_metricInfoboxLargeNumber').click();
        expect(changed).toEqual(['sessions']);

        jest.useRealTimers();
    });

    test('a mouse resting on the name opens it', () => {
        jest.useFakeTimers();

        const { track } = draw(['sessions']);
        const name = track.querySelector('[data-ui="kpi:sessions"] .owa_metricInfoboxLabel > button');
        const e = new Event('pointerenter');
        Object.defineProperty(e, 'pointerType', { value: 'mouse' });

        name.dispatchEvent(e);
        jest.advanceTimersByTime(200);

        expect(name.getAttribute('aria-expanded')).toBe('true');

        jest.useRealTimers();
    });
});
