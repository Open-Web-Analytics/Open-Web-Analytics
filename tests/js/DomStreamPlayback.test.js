// Player does `import * as jQuery from 'jquery'` and then calls jQuery(...).
// Webpack makes that wildcard namespace callable (jQuery's CJS export IS the
// function); babel-jest's interop instead yields a non-callable namespace object,
// so jQuery(...) throws "not a function". Marking the real jQuery function as an
// ES module makes babel's _interopRequireWildcard return it verbatim (callable),
// which mirrors production while using the genuine jQuery. Must precede the Player
// import (jest hoists jest.mock above imports anyway).
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { Player } from '../../modules/Base/src/tracker/Player.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Domstream PLAYBACK tests (admin-side replay).
 *
 * A recorded domstream is only useful if the admin overlay can play it back.
 * Player is the admin-side class (dynamically imported as the owa.player chunk)
 * that steps through a stored stream and re-enacts each captured event in the
 * browser: moving a cursor for dom.movement, scrolling the viewport for
 * dom.scroll, re-typing into inputs for dom.keypress, and re-clicking elements
 * for dom.click. These tests feed Player a stream shaped exactly like the one
 * DomStreamCapture.test.js produces and assert the replay round-trips each type.
 *
 * This closes the loop the user asked for: capture (DomStreamCapture) ->
 * transport (TrackerTransport domstream tests) -> playback (here).
 *
 * Player leans on jQuery for its DOM work and jGrowl for notifications; both are
 * real deps under jsdom. We stub only jGrowl's visual toast (it has no bearing on
 * whether an event replayed) and window.scroll (jsdom doesn't implement it).
 */

// A captured stream in the Player's expected shape: { events: [ {event_type, ...} ] }.
// step() starts at queue_step = 1, so index 0 is a sentinel that is never played
// (matches the live Player, whose first real event is at index 1).
function makeStream(events) {
    return { events: [{ event_type: 'sentinel' }].concat(events) };
}

describe('domstream playback (admin-side Player)', () => {

    let scrolledTo;

    beforeEach(() => {
        document.body.innerHTML = '';
        // jGrowl toast is purely visual; silence it so a replay step doesn't throw.
        const jq = require('jquery');
        jq.jGrowl = Object.assign(function () {}, { defaults: {} });
        // jsdom has no real scroll; record the target instead.
        scrolledTo = null;
        window.scroll = (x, y) => { scrolledTo = { x, y }; };
        // jsdom doesn't implement elementFromPoint. Returning null drives the
        // Player's id/name accessor fallback -- the deterministic replay path.
        document.elementFromPoint = () => null;
        // Player builds a cursor img src from baseUrl; give it something.
        OWA.setSetting('baseUrl', 'https://owa.example.test/');
    });

    test('new Player() initializes its defaults (constructor regression)', () => {
        // Regression guard: Player once named its initializer construct() instead of
        // constructor(), so `new Player()` never ran it and animateInterval /
        // queue_step / queue_count / lock were all undefined -- playback would tick
        // at the browser minimum instead of the intended 250ms cadence.
        const p = new Player();
        expect(p.animateInterval).toBe(250);
        expect(p.queue_step).toBe(1);
        expect(p.queue_count).toBe(0);
        expect(p.lock).toBe(false);
        expect(p.timer).toBeNull();
    });

    test('load() ingests a captured stream and counts its events', () => {
        const p = new Player();
        const stream = makeStream([
            { event_type: 'dom.scroll', x: 0, y: 100 },
            { event_type: 'dom.click', dom_element_id: 'x', click_x: 1, click_y: 2 },
        ]);
        p.load({ data: stream });
        expect(p.queue_count).toBe(stream.events.length);
    });

    test('a dom.scroll event replays by scrolling the viewport', () => {
        const p = new Player();
        p.scrollEventHandler({ x: 0, y: 420 });
        expect(scrolledTo).toEqual({ x: 0, y: 420 });
    });

    test('a dom.keypress event replays by appending the key to its target input', () => {
        document.body.innerHTML = '<input id="search-box" />';
        const p = new Player();
        // Two keystrokes into the same field should accumulate.
        p.keypressEventHandler({ event_type: 'dom.keypress', key_value: 'h', dom_element_id: 'search-box', dom_element_name: 'q', dom_element_tag: 'input' });
        p.keypressEventHandler({ event_type: 'dom.keypress', key_value: 'i', dom_element_id: 'search-box', dom_element_name: 'q', dom_element_tag: 'input' });
        expect(document.getElementById('search-box').value).toBe('hi');
    });

    test('a dom.click event replays by clicking the recorded element', () => {
        document.body.innerHTML = '<button id="buy-now">buy</button>';
        let clicked = false;
        document.getElementById('buy-now').addEventListener('click', () => { clicked = true; });
        const p = new Player();
        // elementFromPoint is unimplemented in jsdom -> returns null, so the Player
        // falls back to the id/name accessor path, which is what we want to exercise.
        p.clickEventHandler({
            event_type: 'dom.click',
            dom_element_id: 'buy-now',
            dom_element_name: '(not set)',
            dom_element_class: '(not set)',
            dom_element_tag: 'button',
            click_x: 10, click_y: 20,
        });
        expect(clicked).toBe(true);
        // A visual click marker is dropped at the recorded coordinates.
        expect(document.querySelector('.owa-click-marker')).not.toBeNull();
    });

    test('playEvent dispatches each captured type to the right handler', () => {
        const p = new Player();
        const calls = [];
        p.movementEventHandler = (e) => calls.push('movement:' + e.cursor_x);
        p.scrollEventHandler = (e) => calls.push('scroll:' + e.y);
        p.keypressEventHandler = (e) => calls.push('keypress:' + e.key_value);
        p.clickEventHandler = (e) => calls.push('click:' + e.dom_element_id);

        p.playEvent({ event_type: 'dom.movement', cursor_x: 5 });
        p.playEvent({ event_type: 'dom.scroll', y: 99 });
        p.playEvent({ event_type: 'dom.keypress', key_value: 'z' });
        p.playEvent({ event_type: 'dom.click', dom_element_id: 'go' });

        expect(calls).toEqual(['movement:5', 'scroll:99', 'keypress:z', 'click:go']);
    });

    test('step() walks the loaded stream event by event and stops at the end', () => {
        const p = new Player();
        const played = [];
        p.playEvent = (e) => played.push(e.event_type);
        // stop() clears the timer; there is none in this unit context, so stub it.
        p.stop = () => { played.push('STOP'); };

        p.load({ data: makeStream([
            { event_type: 'dom.scroll', x: 0, y: 1 },
            { event_type: 'dom.click', dom_element_id: 'a' },
            { event_type: 'dom.keypress', key_value: 'b' },
        ]) });

        // queue_count = 4 (sentinel + 3). Drive step() until it reports STOP.
        for (let i = 0; i < 6 && played[played.length - 1] !== 'STOP'; i++) {
            p.step();
        }

        // queue_step starts at 1, so playback plays events[1..3] (scroll, click,
        // keypress) in order, then step() hits queue_step >= queue_count and stops.
        expect(played).toEqual(['dom.scroll', 'dom.click', 'dom.keypress', 'STOP']);
    });
});
