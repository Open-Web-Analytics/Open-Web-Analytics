import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';
import '../../modules/Base/src/reporting/v1/owa.realtime.js';

/**
 * What the realtime screen draws from one answer of v1/realtime.
 *
 * Page titles, paths and campaign names are what visitors' browsers sent, so
 * the check that matters most is that none of it becomes markup.
 */
const FRAME = `
<div id="rt">
  <p class="owa_realtimeStatus" hidden></p><p class="owa_realtimeQueued" hidden></p>
  <div class="owa_realtimeSummary">
    <span class="owa_realtimeUsers30"></span><span class="owa_realtimeUsers5"></span>
    <div class="owa_realtimeBars"></div>
    <div class="owa_realtimeMap"></div><p class="owa_realtimeMapNote"></p>
    <table class="owa_realtimeTable" data-card="sources"><thead><tr><th></th><th></th><th></th><th></th></tr></thead><tbody></tbody></table>
    <table class="owa_realtimeTable" data-card="pages"><thead><tr><th></th><th></th><th></th></tr></thead><tbody></tbody></table>
    <table class="owa_realtimeTable" data-card="events"><thead><tr><th></th><th></th></tr></thead><tbody></tbody></table>
    <span class="owa_realtimeGoalTotal"></span>
    <table class="owa_realtimeTable" data-card="goals"><thead><tr><th></th><th></th></tr></thead><tbody></tbody></table>
    <table class="owa_realtimeTable" data-card="countries"><thead><tr><th></th><th></th></tr></thead><tbody></tbody></table>
    <table class="owa_realtimeTable" data-card="devices"><thead><tr><th></th><th></th></tr></thead><tbody></tbody></table>
    <ol class="owa_realtimeRecent"></ol>
  </div>
  <div class="owa_realtimeVisitor" hidden><h3 class="owa_realtimeVisitorId"></h3><ol class="owa_realtimeVisitorEvents"></ol></div>
</div>`;

const ANSWER = {
    activeUsers: { last30: 3, last5: 1 },
    perMinute: Array(29).fill(0).concat([3]),
    pages: [{ path: '/x', title: '<img src=x onerror=alert(1)>', views: 2, users: 1 }],
    events: [{ name: 'page_view', count: 2 }],
    goals: { total: 0, byGoal: [] },
    sources: [{ source: null, medium: null, campaign: null, users: 2 },
              { source: 'news<b>letter</b>', medium: 'email', campaign: null, users: 1 }],
    countries: [{ code: 'US', name: 'united states', users: 2 }],
    located: 2,
    devices: [],
    recent: [{ ts: 1790000000000000, type: 'page_view', path: '/x', title: '<script>x()</script>',
               country: 'united states', city: 'ashburn', visitor: '8899300000000001' }],
    queued: true,
};

function widget() {
    document.body.innerHTML = FRAME;

    const rt = new OWA.realtime(document.getElementById('rt'), { apiUrl: 'api', mapUrl: '' });
    rt.render(ANSWER);

    return document.getElementById('rt');
}

test('visitor-sent text is drawn as text, never as markup', () => {
    const root = widget();

    expect(root.querySelector('img')).toBeNull();
    expect(root.querySelector('script')).toBeNull();
    expect(root.querySelector('b')).toBeNull();
    expect(root.querySelector('[data-card="pages"] td').textContent).toBe('<img src=x onerror=alert(1)>');
    expect(root.querySelector('.owa_realtimePage').textContent).toBe('<script>x()</script>');
});

test('no captured acquisition is (unknown), an empty column is (not set)', () => {
    const cells = [...widget().querySelectorAll('[data-card="sources"] tbody tr:first-child td')]
        .map((td) => td.textContent);

    expect(cells).toEqual(['(unknown)', '(not set)', '(not set)', '2']);
});

test('the counts, thirty bars, an empty card and the queue note', () => {
    const root = widget();

    expect(root.querySelector('.owa_realtimeUsers30').textContent).toBe('3');
    expect(root.querySelectorAll('.owa_realtimeBar')).toHaveLength(30);
    expect(root.querySelector('.owa_realtimeBar:last-child').style.height).toBe('100%');
    expect(root.querySelector('[data-card="devices"] tbody').textContent).toBe('None in the last 30 minutes');
    expect(root.querySelector('.owa_realtimeQueued').hidden).toBe(false);
    expect(root.querySelector('.owa_realtimeRecent li').getAttribute('data-visitor')).toBe('8899300000000001');
});

test('a refused refresh stops polling and says to reload', async () => {
    document.body.innerHTML = FRAME;
    global.fetch = jest.fn(() => Promise.resolve({ ok: false, status: 403 }));

    const rt = new OWA.realtime(document.getElementById('rt'), { apiUrl: 'api', mapUrl: '' });
    rt.timer = setInterval(() => {}, 100000);

    await rt.load();

    expect(rt.timer).toBeNull();
    expect(document.querySelector('.owa_realtimeStatus').textContent).toMatch(/Reload the page/);
});

describe('in the install\'s language', () => {

    afterEach(() => {
        delete OWA.config.locale;
        delete OWA.config.strings;
        delete OWA.config.plural_rule;
    });

    test('a bar names its users and its minute in one sentence', () => {
        const bars = [...widget().querySelectorAll('.owa_realtimeBar')];

        expect(bars[29].title).toBe('3 users, this minute');
        expect(bars[28].title).toBe('0 users, 1 minute ago');
        expect(bars[0].title).toBe('0 users, 29 minutes ago');
    });

    test('the locator note counts in the form the number takes', () => {
        document.body.innerHTML = FRAME;
        const rt = new OWA.realtime(document.getElementById('rt'), { apiUrl: 'api', mapUrl: '' });

        rt.render(Object.assign({}, ANSWER, { activeUsers: { last30: 3, last5: 1 }, located: 2 }));
        expect(document.querySelector('.owa_realtimeMapNote').textContent).toBe('1 of 3 users has no known location.');

        rt.render(Object.assign({}, ANSWER, { activeUsers: { last30: 1500, last5: 1 }, located: 2 }));
        expect(document.querySelector('.owa_realtimeMapNote').textContent).toBe('1,498 of 1,500 users have no known location.');
    });

    test('counts, minutes and words follow the install\'s locale and strings', () => {
        OWA.config.locale = 'de-DE';
        OWA.config.strings = {
            'None in the last 30 minutes': 'Keine in den letzten 30 Minuten',
            '%1$s user, %2$s': ['%1$s Nutzer, %2$s', '%1$s Nutzer, %2$s'],
        };

        document.body.innerHTML = FRAME;
        const rt = new OWA.realtime(document.getElementById('rt'), { apiUrl: 'api', mapUrl: '' });
        rt.render(Object.assign({}, ANSWER, { activeUsers: { last30: 1234, last5: 1 }, located: 1234 }));

        const root = document.getElementById('rt');

        expect(root.querySelector('.owa_realtimeUsers30').textContent).toBe('1.234');
        expect(root.querySelector('[data-card="devices"] tbody').textContent).toBe('Keine in den letzten 30 Minuten');
        expect(root.querySelectorAll('.owa_realtimeBar')[27].title).toBe('0 Nutzer, vor 2 Minuten');
    });
});
