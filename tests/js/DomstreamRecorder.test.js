import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { recorderFor, EVENT_NAME } from '../../modules/Domstream/src/tracker/Recorder.js';

/**
 * The domstream recorder: compiled into the tracker from the Domstream
 * module's source, registered as a plugin, and sending `domstream` chunks of
 * [ms, type, ...] tuples.
 */

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.rec.example' });
    t.setSiteId('recorder-site');
    return t;
}

function chunksOf(t) {
    const sent = [];
    t.logEvent = (p) => { sent.push(p); };
    return () => sent.filter((e) => e.event_type === EVENT_NAME);
}

/** A recorder started on a fixed clock, for exact tuples. */
function started(t, at = 1000) {
    const r = recorderFor(t);
    let now = at;
    r.now = () => now;
    r.tick = (ms) => { now += ms; };
    jest.spyOn(Math, 'random').mockReturnValue(0);
    r.start();
    Math.random.mockRestore();
    return r;
}

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('loggerPause', false);
    document.body.innerHTML = '';
});

test('it registers as a tracker plugin: the snippet command and the reserved name', () => {
    expect(typeof OWATracker.prototype.trackDomStream).toBe('function');
    expect(typeof OWATracker.prototype.setDomstreamSampleRate).toBe('function');
    expect(OWATracker.RESERVED_EVENT_NAMES).toContain('domstream');
});

test('a site may not send its own event named domstream', () => {
    const t = newTracker();
    const chunks = chunksOf(t);

    t.trackCustomEvent('domstream', { anything: 1 });

    expect(chunks()).toHaveLength(0);
});

test('outside the sample, nothing records', () => {
    const t = newTracker();
    t.setDomstreamSampleRate(10);
    jest.spyOn(Math, 'random').mockReturnValue(0.5);

    expect(t.trackDomStream()).toBe(false);

    Math.random.mockRestore();
});

test('samples are [ms since the last, type, ...] tuples; moves are relative', () => {
    const t = newTracker();
    const chunks = chunksOf(t);
    const r = started(t);

    r.tick(120);
    r.onMove({ pageX: 100, pageY: 50 });
    r.tick(150);
    r.onMove({ pageX: 110, pageY: 45 });
    r.tick(200);
    window.pageYOffset = 400;
    r.onScroll();
    r.flush();

    const sent = chunks();
    expect(sent).toHaveLength(1);
    expect(JSON.parse(sent[0].samples)).toEqual([
        [120, 'm', 100, 50],
        [150, 'm', 10, -5],
        [200, 's', 400],
    ]);
    r.stop();
});

/*
 * THAT A KEY WAS PRESSED, and where -- never which key. And nothing at all in
 * a password field.
 */
test('a key press records the field, never the key', () => {
    const t = newTracker();
    const chunks = chunksOf(t);
    const r = started(t);
    document.body.innerHTML = '<input id="email" name="email"><input type="password" id="pw">';

    r.tick(10);
    r.onKey({ key: 'a', target: document.getElementById('email') });
    r.tick(10);
    r.onKey({ key: 'b', target: document.getElementById('pw') });
    r.tick(10);
    r.onKey({ key: 'Shift', target: document.getElementById('email') });
    r.flush();

    const raw = chunks()[0].samples;
    expect(JSON.parse(raw)).toEqual([[10, 'k', 'input', 'email', 'email']]);
    expect(raw).not.toMatch(/"a"/);
    r.stop();
});

test('a click the tracker handles is recorded with its element', () => {
    const t = newTracker();
    const chunks = chunksOf(t);
    const r = started(t);
    t.setOption('logClicksAsTheyHappen', false);
    document.body.innerHTML = '<a id="buy" name="buy" href="/checkout">Buy</a>';

    r.tick(40);
    t.clickEventHandler({ target: document.getElementById('buy'), pageX: 12, pageY: 34 });
    r.flush();

    expect(JSON.parse(chunks()[0].samples)).toEqual([[40, 'c', 12, 34, 'a', 'buy', 'buy']]);
    r.stop();
});

/**
 * Through the page, not by calling the handler: the tracker binds its click
 * listener only when something asks for it, and a page that records without
 * also calling trackClicks must still have its clicks recorded.
 */
test('a click on the page is recorded without click tracking being on', () => {
    const t = newTracker();
    const sent = [];
    t.logEvent = (p) => { sent.push(p); };
    const r = started(t);
    document.body.innerHTML = '<button id="go" name="go">Go</button>';

    r.tick(10);
    document.getElementById('go').dispatchEvent(new MouseEvent('click', { bubbles: true }));
    r.flush();

    const chunks = sent.filter((e) => e.event_type === EVENT_NAME);
    const samples = JSON.parse(chunks[0].samples);

    expect(samples.map((s) => s[1])).toEqual(['c']);
    expect(samples[0].slice(4)).toEqual(['button', 'go', 'go']);
    // No click EVENT is sent: the page does not track clicks.
    expect(sent.filter((e) => e.event_type === 'click')).toHaveLength(0);
    r.stop();
});

test('chunks carry the recording id, their seq and the page view they belong to', () => {
    const t = newTracker();
    const chunks = chunksOf(t);
    t.trackPageView();
    const r = started(t);

    r.tick(150); r.onMove({ pageX: 1, pageY: 1 });
    r.flush();
    r.tick(150); r.onMove({ pageX: 2, pageY: 2 });
    r.flush();

    const [first, second] = chunks();
    expect(first.recording_id).toMatch(/^\d+$/);
    expect(second.recording_id).toBe(first.recording_id);
    expect([first.seq, second.seq]).toEqual([1, 2]);
    expect(first.page_view_seq).toBeGreaterThan(0);
    r.stop();
});

test('pointer moves closer together than domstreamMoveInterval are one sample', () => {
    const t = newTracker();
    const chunks = chunksOf(t);
    const r = started(t);

    r.tick(150); r.onMove({ pageX: 1, pageY: 1 });
    r.tick(20);  r.onMove({ pageX: 2, pageY: 2 });
    r.flush();

    expect(JSON.parse(chunks()[0].samples)).toHaveLength(1);
    r.stop();
});

test('an empty flush sends nothing', () => {
    const t = newTracker();
    const chunks = chunksOf(t);
    const r = started(t);

    expect(r.flush()).toBe(false);
    expect(chunks()).toHaveLength(0);
    r.stop();
});

test('a full chunk is sent without waiting for the timer', () => {
    const t = newTracker();
    const chunks = chunksOf(t);
    t.setOption('domstreamMaxSamples', 3);
    const r = started(t);

    for (let i = 1; i <= 4; i++) {
        r.tick(200);
        r.onMove({ pageX: i, pageY: i });
    }

    expect(chunks()).toHaveLength(1);
    expect(JSON.parse(chunks()[0].samples)).toHaveLength(3);
    r.stop();
});

test('hiding the page sends what has accrued', () => {
    const t = newTracker();
    const chunks = chunksOf(t);
    const r = started(t);

    r.tick(5);
    r.onMove({ pageX: 3, pageY: 3 });
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));

    expect(chunks()).toHaveLength(1);
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
    r.stop();
});

test('the player is an overlay mode the recorder registers', () => {
    expect(typeof OWA.overlayModes.loadPlayer).toBe('function');
});
