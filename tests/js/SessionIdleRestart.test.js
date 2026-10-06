/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://example.com/landing?utm_source=newsletter"}
 */
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { StateManager } from '../../modules/Base/src/common/StateManager.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * A page left open past the inactivity window starts a new session on its next
 * event, whatever the event is.
 *
 * The session used to be decided once, on the page's first event, so a click
 * 40 minutes after the page view carried the old session_id and stretched that
 * session over the idle time.
 */

const SITE   = 'idle-site';
const MINUTE = 60;
const T0     = 1800000000;

function wipe() {
    OWA.state = new StateManager();
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
    });
    OWA.state.cookies = Util.readAllCookies();
}

function sessionCookie() {
    const raw = Util.readCookie(`owa_s_${SITE}`);
    return raw ? Util.decodeCookieValue(unescape(raw)) : null;
}

function writeSessionCookie(store) {
    Util.setCookie(`owa_s_${SITE}`, JSON.stringify(store), 1, '/', OWA.getSetting('cookie_domain'));
    OWA.state.cookies = Util.readAllCookies();
}

/**
 * A tracker whose clock reads `now.t`, with every beacon captured and accepted.
 * The shared clock, because an event stamps its own time when it is made.
 */
function pageAt(now) {
    jest.spyOn(Util, 'getCurrentUnixTimestamp').mockImplementation(() => now.t);
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId(SITE);
    const sent = [];
    t.logEvent = (properties) => { sent.push(properties); t.sendAccepted(); };
    return { t, sent };
}

function click(t) {
    const event = t.makeEvent();
    event.setEventType('click');
    t.trackEvent(event);
}

afterEach(() => { jest.restoreAllMocks(); });

beforeEach(() => {
    wipe();
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('loggerPause', false);
});

describe('a page left open past the inactivity window', () => {

    test('an event inside the window keeps the session', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();
        now.t = T0 + 29 * MINUTE;
        click(t);

        expect(sent[1].session_id).toBe(sent[0].session_id);
        expect(sent[1].is_new_session_start).toBeUndefined();
    });

    test('the first event after it starts a new session, whatever the event', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();
        now.t = T0 + 31 * MINUTE;
        click(t);

        const [view, later] = sent;

        expect(later.event_type).toBe('click');
        expect(later.session_id).not.toBe(view.session_id);
        expect(later.is_new_session_start).toBe(true);
        expect(later.prior_session_id).toBe(view.session_id);
        expect(later.psts).toBe(view.sts);
        expect(later.sts).toBe(T0 + 31 * MINUTE);
        expect(later.nps).toBe(view.nps + 1);
    });

    test('the new session goes on: no second start, one session id, the sequence from 1', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();
        click(t);
        now.t = T0 + 45 * MINUTE;
        click(t);
        click(t);

        const restarted = sent.slice(2);

        expect(restarted.map((e) => e.session_id)).toEqual([restarted[0].session_id, restarted[0].session_id]);
        expect(restarted.map((e) => e.is_new_session_start)).toEqual([true, undefined]);
        expect(restarted.map((e) => e.event_seq)).toEqual([1, 2]);
    });

    /*
     * The old session ends at its last real event: nothing after the idle can
     * carry its id, so the session is not stretched over the gap.
     */
    test('nothing after the gap carries the old session id', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();
        const old = sent[0].session_id;

        now.t = T0 + 31 * MINUTE;
        click(t);
        now.t = T0 + 32 * MINUTE;
        click(t);

        expect(sent.slice(1).filter((e) => e.session_id === old)).toHaveLength(0);
    });

    /* The page's campaign tags and referrer ride every event, as GA's do. */
    test('the restarting event carries the page\'s own URL and referrer', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();
        now.t = T0 + 31 * MINUTE;
        click(t);

        expect(sent[1].page_location).toBe(sent[0].page_location);
        expect(sent[1].page_location).toContain('utm_source=newsletter');
        expect(sent[1].HTTP_REFERER).toBe(sent[0].HTTP_REFERER);
    });

    test('the restarted session is what the cookie holds once delivered', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();
        now.t = T0 + 31 * MINUTE;
        click(t);

        expect(sessionCookie().sid).toBe(sent[1].session_id);
    });
});

describe('another tab of the same site', () => {

    test('kept the session alive: this tab continues it', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();

        // The other tab was in use ten minutes ago, in the same session.
        writeSessionCookie(Object.assign({}, sessionCookie(), { last_req: T0 + 30 * MINUTE }));

        now.t = T0 + 40 * MINUTE;
        click(t);

        expect(sent[1].session_id).toBe(sent[0].session_id);
        expect(sent[1].is_new_session_start).toBeUndefined();
    });

    test('already started the next session: this tab joins it', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();

        writeSessionCookie(Object.assign({}, sessionCookie(), {
            sid: 'from-the-other-tab', sts: T0 + 35 * MINUTE, psts: T0,
            prior_session_id: sent[0].session_id, seq: 4, last_req: T0 + 36 * MINUTE,
        }));

        now.t = T0 + 40 * MINUTE;
        click(t);

        expect(sent[1].session_id).toBe('from-the-other-tab');
        expect(sent[1].is_new_session_start).toBeUndefined();
        expect(sent[1].sts).toBe(T0 + 35 * MINUTE);
        expect(sent[1].event_seq).toBe(5);
    });

    test('was idle too: this tab starts the next session', () => {
        const now = { t: T0 };
        const { t, sent } = pageAt(now);

        t.trackPageView();
        writeSessionCookie(Object.assign({}, sessionCookie(), { last_req: T0 + 2 * MINUTE }));

        now.t = T0 + 40 * MINUTE;
        click(t);

        expect(sent[1].session_id).not.toBe(sent[0].session_id);
        expect(sent[1].is_new_session_start).toBe(true);
    });
});
