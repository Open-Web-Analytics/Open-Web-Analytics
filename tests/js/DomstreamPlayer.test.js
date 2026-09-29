// Player does `import * as jQuery from 'jquery'` and calls jQuery(...). Webpack
// makes that namespace callable; babel-jest's interop does not unless jQuery is
// marked an ES module, which mirrors production with the genuine jQuery.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { Player } from '../../modules/Domstream/src/tracker/Player.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Playback of a recording's tuples (see Recorder.js), at the pace recorded.
 */

let scrolledTo;

beforeEach(() => {
    document.body.innerHTML = '';
    const jq = require('jquery');
    jq.jGrowl = Object.assign(function () {}, { defaults: {} });
    scrolledTo = null;
    window.scroll = (x, y) => { scrolledTo = { x, y }; };
    OWA.setSetting('baseUrl', 'https://owa.example.test/');
    jest.useFakeTimers();
});

afterEach(() => {
    jest.useRealTimers();
});

function playerWith(samples) {
    const p = new Player();
    p.showPlayerControls();
    p.load({ data: { samples } });
    return p;
}

test('pointer moves are accumulated from their deltas', () => {
    const p = playerWith([[0, 'm', 100, 50], [0, 'm', 10, -5]]);

    p.playSample(p.samples[0]);
    p.playSample(p.samples[1]);

    expect([p.x, p.y]).toEqual([110, 45]);
    expect(document.getElementById('owa-cursor').style.left).toBe('110px');
});

test('a scroll sample scrolls the page', () => {
    const p = playerWith([[0, 's', 400]]);

    p.playSample(p.samples[0]);

    expect(scrolledTo).toEqual({ x: 0, y: 400 });
});

test('a click sample drops a marker where it was', () => {
    const p = playerWith([[0, 'c', 12, 34, 'a', 'buy', '']]);

    p.playSample(p.samples[0]);

    const marker = document.querySelector('.owa-click-marker');
    expect(marker.style.left).toBe('12px');
    expect(marker.style.top).toBe('34px');
});

/*
 * A key press shows WHICH FIELD received it. The recording holds no key, and
 * the player does not invent one: the field's value is left alone.
 */
test('a key press highlights its field and types nothing', () => {
    document.body.innerHTML = '';
    const p = playerWith([[0, 'k', 'input', 'email', 'email']]);
    document.body.insertAdjacentHTML('beforeend', '<input id="email" name="email" value="">');

    p.playSample(p.samples[0]);

    const field = document.getElementById('email');
    expect(field.classList.contains('owa-key-pressed')).toBe(true);
    expect(field.value).toBe('');
    expect(document.getElementById('owa-overlay-status').textContent).toContain('#email');
});

test('play() replays each sample after its recorded pause, and stops at the end', () => {
    const p = playerWith([[100, 'm', 5, 5], [300, 'm', 5, 5]]);

    p.play();
    jest.advanceTimersByTime(99);
    expect(p.step).toBe(0);
    jest.advanceTimersByTime(1);
    expect(p.step).toBe(1);
    jest.advanceTimersByTime(300);
    expect(p.step).toBe(2);
    expect(p.playing).toBe(false);
});

test('a long pause is shortened, so a recording never stalls', () => {
    const p = playerWith([[600000, 's', 10]]);

    p.play();
    jest.advanceTimersByTime(2000);

    expect(scrolledTo).toEqual({ x: 0, y: 10 });
});

test('stop() pauses, and play() resumes where it stopped', () => {
    const p = playerWith([[100, 's', 1], [100, 's', 2]]);

    p.play();
    jest.advanceTimersByTime(100);
    p.stop();
    jest.advanceTimersByTime(1000);
    expect(p.step).toBe(1);

    p.play();
    jest.advanceTimersByTime(100);
    expect(scrolledTo).toEqual({ x: 0, y: 2 });
});

test('a sample of an unknown type is skipped', () => {
    const p = playerWith([[0, 'x', 1, 2]]);

    expect(() => p.playSample(p.samples[0])).not.toThrow();
});
