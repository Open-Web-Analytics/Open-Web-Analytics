import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * Domstream CAPTURE tests.
 *
 * Domstream recording is the tracker's most complex event path: instead of
 * beaconing each interaction, it binds handlers for mouse movement, scroll,
 * keypress and click, pushes a compact Event for each onto an in-memory queue,
 * and later flushes the whole queue as a single dom.stream event (see
 * TrackerTransport's domstream tests for the flush/transport half). These tests
 * pin the CAPTURE half: that each handler produces a correctly-typed, correctly
 * shaped Event on event_queue, and that the mixed queue serializes into the
 * dom.stream payload the admin-side Player later replays (see
 * DomStreamPlayback.test.js for the replay round-trip).
 *
 * We call the handlers directly with synthetic event objects rather than
 * dispatching real DOM events, because the handlers read a handful of fields
 * (coords, target, key) off the event and otherwise just enqueue -- driving them
 * directly keeps the test about the captured PAYLOAD, not jsdom's event plumbing.
 */

function newTracker() {
    window.owa_baseUrl = 'https://owa.example.test/';
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('domstream-site');
    // trackDomStream() gates enqueue behind this option for the click handler.
    t.setOption('trackDomStream', true);
    return t;
}

afterEach(() => {
    delete window.owa_baseUrl;
});

describe('domstream capture (event_queue)', () => {

    test('scrollEventHandler enqueues a dom.scroll event with the scroll position', () => {
        const t = newTracker();
        // getScrollingPosition reads window.pageXOffset/pageYOffset.
        window.pageXOffset = 12;
        window.pageYOffset = 340;

        t.scrollEventHandler({});

        expect(t.event_queue).toHaveLength(1);
        const props = t.event_queue[0];
        expect(props.event_type).toBe('dom.scroll');
        expect(props.x).toBe(12);
        expect(props.y).toBe(340);
    });

    test('keypressEventHandler enqueues a dom.keypress with the key + target element', () => {
        const t = newTracker();
        const target = document.createElement('input');
        target.id = 'search-box';
        target.name = 'q';
        // charCode 65 === 'A'
        t.keypressEventHandler({ charCode: 65, target: target });

        expect(t.event_queue).toHaveLength(1);
        const props = t.event_queue[0];
        expect(props.event_type).toBe('dom.keypress');
        expect(props.key_value).toBe('A');
        expect(props.key_code).toBe(65);
        expect(props.dom_element_id).toBe('search-box');
        expect(props.dom_element_name).toBe('q');
        expect(props.dom_element_tag).toBe('input');
    });

    test('keypressEventHandler does NOT capture keystrokes in a password field', () => {
        const t = newTracker();
        const pw = document.createElement('input');
        pw.type = 'password';

        t.keypressEventHandler({ charCode: 65, target: pw });

        // Password keystrokes must never enter the stream.
        expect(t.event_queue).toHaveLength(0);
    });

    test('movementEventHandler enqueues a dom.movement with cursor coords', () => {
        const t = newTracker();
        // The handler throttles on movementInterval since last_movement; zero both
        // so the first synthetic move always records.
        t.last_movement = 0;
        t.setOption('movementInterval', 0);

        t.movementEventHandler({ clientX: 200, clientY: 150, pageX: 200, pageY: 150 });

        expect(t.event_queue).toHaveLength(1);
        const props = t.event_queue[0];
        expect(props.event_type).toBe('dom.movement');
        // getCoords stringifies coords (value + ''), so these ride as strings.
        expect(props.cursor_x).toBe('200');
        expect(props.cursor_y).toBe('150');
    });

    test('clickEventHandler enqueues a dom.click with the clicked element identity', () => {
        const t = newTracker();
        const btn = document.createElement('button');
        btn.id = 'buy-now';
        document.body.appendChild(btn);
        try {
            t.clickEventHandler({ target: btn, clientX: 55, clientY: 66, pageX: 55, pageY: 66 });

            expect(t.event_queue).toHaveLength(1);
            const props = t.event_queue[0];
            expect(props.event_type).toBe('dom.click');
            expect(props.dom_element_id).toBe('buy-now');
            // getDomElementProperties lower-cases the tag for consistent storage.
            expect(props.dom_element_tag).toBe('button');
            // getCoords stringifies click coords.
            expect(props.click_x).toBe('55');
            expect(props.click_y).toBe('66');
        } finally {
            document.body.removeChild(btn);
        }
    });

    test('a mixed stream of all four event types serializes into one dom.stream flush', () => {
        const spy = { sent: [] };
        const t = newTracker();
        // Capture the flush without a network: over the GET limit -> cdPost.
        t.setOption('getRequestCharacterLimit', 50);
        const posted = [];
        t.cdPost = (data) => { posted.push(data); };

        // Interleave the four capture handlers the way a real session would.
        window.pageXOffset = 0; window.pageYOffset = 100;
        t.last_movement = 0; t.setOption('movementInterval', 0);
        const input = document.createElement('input'); input.id = 'f';
        const link = document.createElement('a'); link.id = 'l'; document.body.appendChild(link);
        try {
            for (let i = 0; i < 4; i++) {
                t.scrollEventHandler({});
                t.movementEventHandler({ clientX: i, clientY: i, pageX: i, pageY: i });
                t.keypressEventHandler({ charCode: 97 + i, target: input });
                t.clickEventHandler({ target: link, clientX: i, clientY: i, pageX: i, pageY: i });
                t.last_movement = 0; // defeat throttle between synthetic moves
            }

            expect(t.event_queue.length).toBeGreaterThan(t.options.domstreamEventThreshold);
            t.logDomStream();

            expect(posted).toHaveLength(1);
            const data = posted[0];
            expect(data['event_type']).toBe('dom.stream');
            // The serialized stream_events blob must carry each captured type.
            const blob = data['stream_events'];
            expect(blob).toContain('"event_type":"dom.scroll"');
            expect(blob).toContain('"event_type":"dom.movement"');
            expect(blob).toContain('"event_type":"dom.keypress"');
            expect(blob).toContain('"event_type":"dom.click"');
            // The queue is drained on flush.
            expect(t.event_queue).toHaveLength(0);
        } finally {
            document.body.removeChild(link);
        }
    });
});
