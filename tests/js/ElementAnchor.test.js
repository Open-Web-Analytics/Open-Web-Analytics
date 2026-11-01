import { ElementAnchor } from '../../modules/Base/src/tracker/ElementAnchor.js';

/**
 * Where on an element a click landed: the element as a hash of its path from
 * the nearest id, and the point as thousandths of its box.
 *
 * The tracker computes it when a click happens and the heatmap computes the same
 * thing for every element of the page it is drawn on, so the two must agree
 * exactly -- most of what is pinned here is that agreement.
 *
 * jsdom lays nothing out, so a box is given to an element by hand.
 */

function box(el, left, top, width, height) {
    el.getBoundingClientRect = () => ({ left, top, width, height });
    return el;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('the path', () => {

    test('starts at the nearest id and counts each step among its own tag', () => {
        document.body.innerHTML =
            '<div id="main"><p>a</p><div>x</div><p>b</p><div><a href="/1">one</a><a href="/2">two</a></div></div>';

        const second = document.querySelectorAll('#main a')[1];

        expect(ElementAnchor.path(second)).toBe('#main>div:2>a:2');
    });

    test('an element with an id of its own is just the id', () => {
        document.body.innerHTML = '<div id="main"><a id="buy" href="/b">buy</a></div>';

        expect(ElementAnchor.path(document.getElementById('buy'))).toBe('#buy');
    });

    test('with no id above it, the path runs from the root', () => {
        document.body.innerHTML = '<div><span>x</span></div>';

        expect(ElementAnchor.path(document.querySelector('span'))).toBe('html:1>body:1>div:1>span:1');
    });

    test('a duplicated id names only its first element; the second is placed by its parent', () => {
        document.body.innerHTML = '<div id="wrap"><b id="dup">1</b><b id="dup">2</b></div>';

        const [first, second] = document.querySelectorAll('b');

        expect(ElementAnchor.path(first)).toBe('#dup');
        expect(ElementAnchor.path(second)).toBe('#wrap>b:2');
    });

    test('a generated id is passed over', () => {
        document.body.innerHTML =
            '<div id="list"><div id=":r1:"><i>a</i></div><div id="item-3f9a2c7e1b"><i>b</i></div></div>';

        const [a, b] = document.querySelectorAll('i');

        expect(ElementAnchor.path(a)).toBe('#list>div:1>i:1');
        expect(ElementAnchor.path(b)).toBe('#list>div:2>i:1');
    });

    test('a numbered id is kept: the number is the page\'s own', () => {
        expect(ElementAnchor.isStableId('post-1234')).toBe(true);
        expect(ElementAnchor.isStableId('menu-item-5678')).toBe(true);
        expect(ElementAnchor.isStableId('deadbeef')).toBe(true);
        expect(ElementAnchor.isStableId('3f2504e0-4f89-11d3-9a0c-0305e82c3301')).toBe(false);
        expect(ElementAnchor.isStableId(':r1:')).toBe(false);
        expect(ElementAnchor.isStableId('')).toBe(false);
    });

    test('an input named "id" does not stand in for the form\'s id', () => {
        document.body.innerHTML = '<form id="signup"><input name="id"><button>go</button></form>';

        expect(ElementAnchor.path(document.querySelector('button'))).toBe('#signup>button:1');
    });
});

describe('the hash', () => {

    test('is a decimal string under 2^53, the same every time', () => {
        const h = ElementAnchor.hash('#main>div:2>a:2');

        expect(h).toMatch(/^[0-9]+$/);
        expect(Number(h)).toBeLessThanOrEqual(Number.MAX_SAFE_INTEGER);
        expect(ElementAnchor.hash('#main>div:2>a:2')).toBe(h);
        expect(ElementAnchor.hash('#main>div:2>a:1')).not.toBe(h);
    });

    /*
     * PINNED. tests/e2e/overlay_e2e_helper.php seeds clicks under this value for
     * the harness's #overlay-anchor, and stored anchors from real visitors are
     * this function's output: changing it orphans every one of them.
     */
    test('is pinned', () => {
        expect(ElementAnchor.hash('#overlay-anchor')).toBe('8991926351447641');
    });
});

describe('the anchor of a click', () => {

    test('is the element\'s hash and the point in thousandths of its box', () => {
        document.body.innerHTML = '<div id="main"><a href="/x">x</a></div>';
        const a = box(document.querySelector('a'), 100, 50, 200, 40);

        expect(ElementAnchor.of(a, 150, 60)).toEqual({
            anchor: ElementAnchor.hash('#main>a:1'),
            x: 250,
            y: 250,
        });
    });

    test('is none for a point outside the box, or an element with no box', () => {
        document.body.innerHTML = '<a id="a" href="/x">x</a><a id="b" href="/y">y</a>';

        expect(ElementAnchor.of(box(document.getElementById('a'), 100, 50, 200, 40), 0, 0)).toBeNull();
        expect(ElementAnchor.of(box(document.getElementById('b'), 0, 0, 0, 0), 0, 0)).toBeNull();
    });
});

describe('finding it again', () => {

    test('the page\'s index maps the click\'s anchor back to its element', () => {
        document.body.innerHTML =
            '<div id="main"><p>a</p><div><a href="/1">one</a><a href="/2">two</a></div></div><footer><a>f</a></footer>';

        const target = document.querySelectorAll('#main a')[1];
        const clicked = ElementAnchor.of(box(target, 10, 10, 100, 20), 60, 20);

        expect(ElementAnchor.index(document).get(clicked.anchor)).toBe(target);
    });

    test('a point is placed on the element\'s box here, scroll included', () => {
        document.body.innerHTML = '<a id="a" href="/x">x</a>';
        const a = box(document.getElementById('a'), 400, 100, 300, 50);

        expect(ElementAnchor.place(a, 250, 500, { pageXOffset: 0, pageYOffset: 1000 }))
            .toEqual({ x: 475, y: 1125 });
    });

    test('an element with no box here is not placed', () => {
        document.body.innerHTML = '<a id="a" href="/x">x</a>';

        expect(ElementAnchor.place(box(document.getElementById('a'), 0, 0, 0, 0), 500, 500, window)).toBeNull();
    });
});
