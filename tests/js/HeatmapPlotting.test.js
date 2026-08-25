/**
 * Heatmap plotting: how fetched rows become dots, and how they are coloured.
 *
 * WHAT WAS WRONG WITH THE DRAWING
 *
 * Gradients were drawn straight onto the VISIBLE canvas, and each dot was then
 * recoloured in place -- a getImageData, a pixel loop and a putImageData per
 * click. Two consequences, and the second is the one you can see:
 *
 *   - Cost. One page on a live install holds 345,620 clicks, which is 345,620
 *     canvas read/write round trips.
 *   - Correctness. Once a region has been recoloured its pixels hold colour,
 *     not the black alpha ramp the next dot composites against. So wherever two
 *     clicks overlapped, the second blended into the first one's COLOUR and the
 *     result was neither click's intensity -- exactly where a heatmap is
 *     supposed to be most informative.
 *
 * Gradients now accumulate on an offscreen canvas and the visible one is
 * painted from it in a single pass, which is the standard way round.
 *
 * WHAT THE ROWS LOOK LIKE NOW
 *
 * The heatmap is an ordinary dimensional query -- domClicks grouped by clickX
 * and clickY -- so identical coordinates arrive ALREADY GROUPED with a count,
 * instead of one row per click. The count is the weight a point is drawn with,
 * which is what makes a hot spot hot.
 */

// jsdom gives us no canvas, and none is needed: these tests are about which
// coordinates and weights reach the drawing calls, so the 2d context is a
// recorder. A real canvas would test the browser, not this code.
function makeContext(recorder) {
    return {
        createRadialGradient(x0, y0, r0, x1, y1, r1) {
            const stops = [];
            recorder.gradients.push({ x: x0, y: y0, radius: r1, stops });
            return { addColorStop: (offset, color) => stops.push({ offset, color }) };
        },
        fillRect(x, y, w, h) { recorder.fills.push({ x, y, w, h }); },
        getImageData(x, y, w, h) {
            recorder.reads.push({ x, y, w, h });
            return { data: recorder.pixels, width: w, height: h };
        },
        putImageData(image, x, y) { recorder.writes.push({ x, y }); },
        set fillStyle(v) { recorder.fillStyles.push(v); },
        get fillStyle() { return null; },
    };
}

/** A Heatmap with its canvases replaced, without running the constructor. */
function makeHeatmap(Heatmap, recorder, docDimensions) {
    const hm = Object.create(Heatmap.prototype);

    hm.docDimensions = docDimensions || { w: 1000, h: 2000 };
    hm.options = { dotSize: 12, dotAlpha: 0.08 };
    hm.context = makeContext(recorder);
    hm.shadowContext = makeContext(recorder);
    hm.shadowCanvas = { width: hm.docDimensions.w, height: hm.docDimensions.h };
    hm.clicks = '';

    return hm;
}

/** One row as the reports API returns it. */
function row(x, y, clicks) {
    return {
        clickX: { result_type: 'dimension', name: 'clickX', value: String(x) },
        clickY: { result_type: 'dimension', name: 'clickY', value: String(y) },
        domClicks: { result_type: 'metric', name: 'domClicks', value: String(clicks) },
    };
}

let Heatmap;

beforeAll(async () => {
    global.OWA_instance = { debug() {} };
    ({ Heatmap } = await import('../../modules/Base/src/tracker/Heatmap.js'));
});

function freshRecorder() {
    return { gradients: [], fills: [], reads: [], writes: [], fillStyles: [], pixels: new Uint8ClampedArray(0) };
}

describe('reading the dimensional result set', () => {

    test('a row becomes a point carrying its click count as weight', () => {
        const hm = makeHeatmap(Heatmap, freshRecorder());
        hm.clicks = { resultsRows: [row(100, 200, 4)] };

        expect(hm.getClicks()).toEqual([{ x: 100, y: 200, weight: 4 }]);
    });

    test('coordinates arrive as strings and must be plotted as numbers', () => {
        const hm = makeHeatmap(Heatmap, freshRecorder());
        hm.clicks = { resultsRows: [row(10, 20, 1)] };

        const point = hm.getClicks()[0];

        // '10' + dotSize would be '1012', which lands the dot off the page.
        expect(typeof point.x).toBe('number');
        expect(typeof point.y).toBe('number');
    });

    test('a row with no coordinates is skipped rather than plotted as NaN', () => {
        const hm = makeHeatmap(Heatmap, freshRecorder());
        hm.clicks = { resultsRows: [{ domClicks: { value: '3' } }, row(5, 6, 1)] };

        expect(hm.getClicks()).toEqual([{ x: 5, y: 6, weight: 1 }]);
    });

    test('an empty or unfetched result set yields no points, not a throw', () => {
        const hm = makeHeatmap(Heatmap, freshRecorder());

        expect(hm.getClicks()).toEqual([]);

        hm.clicks = { resultsRows: [] };
        expect(hm.getClicks()).toEqual([]);
    });
});

describe('plotting', () => {

    test('a point hit more often is drawn more strongly', () => {
        const rec = freshRecorder();
        const hm = makeHeatmap(Heatmap, rec);

        hm.plotDotsRound([{ x: 100, y: 100, weight: 1 }, { x: 300, y: 300, weight: 5 }]);

        const alphaOf = (g) => parseFloat(g.stops[0].color.match(/([\d.]+)\)$/)[1]);

        expect(rec.gradients).toHaveLength(2);
        expect(alphaOf(rec.gradients[1])).toBeGreaterThan(alphaOf(rec.gradients[0]));
    });

    test('intensity saturates, so one very hot point cannot flatten the map', () => {
        const rec = freshRecorder();
        const hm = makeHeatmap(Heatmap, rec);

        hm.plotDotsRound([{ x: 10, y: 10, weight: 100000 }]);

        const alpha = parseFloat(rec.gradients[0].stops[0].color.match(/([\d.]+)\)$/)[1]);

        expect(alpha).toBeLessThanOrEqual(1);
    });

    test('gradients are drawn on the OFFSCREEN canvas, never the visible one', () => {
        const rec = freshRecorder();
        const hm = makeHeatmap(Heatmap, rec);

        // Distinguish the two contexts by identity.
        const visible = [];
        hm.context.fillRect = (x, y, w, h) => visible.push({ x, y, w, h });

        hm.plotDotsRound([{ x: 50, y: 50, weight: 1 }]);

        expect(rec.fills.length).toBeGreaterThan(0);
        expect(visible).toEqual([]);
    });

    test('a click outside the page is skipped', () => {
        const rec = freshRecorder();
        const hm = makeHeatmap(Heatmap, rec, { w: 500, h: 500 });

        hm.plotDotsRound([
            { x: -5, y: 10, weight: 1 },
            { x: 10, y: -5, weight: 1 },
            { x: 900, y: 10, weight: 1 },
            { x: 10, y: 900, weight: 1 },
            { x: 250, y: 250, weight: 1 },
        ]);

        expect(rec.gradients).toHaveLength(1);
        expect(rec.gradients[0].x).toBe(250);
    });

    /**
     * The heart of the fix: colouring is ONE pass over the whole canvas, not
     * one per dot. Three dots used to mean three getImageData/putImageData
     * pairs; a page's worth meant hundreds of thousands.
     */
    test('colouring happens once per plotted page, not once per dot', () => {
        const rec = freshRecorder();
        const hm = makeHeatmap(Heatmap, rec);

        hm.plotDotsRound([
            { x: 10, y: 10, weight: 1 },
            { x: 20, y: 20, weight: 1 },
            { x: 30, y: 30, weight: 1 },
        ]);

        expect(rec.gradients).toHaveLength(3);
        expect(rec.reads).toHaveLength(1);
        expect(rec.writes).toHaveLength(1);
    });

    test('the colour pass reads the whole canvas and paints it at the origin', () => {
        const rec = freshRecorder();
        const hm = makeHeatmap(Heatmap, rec, { w: 800, h: 1200 });
        hm.shadowCanvas = { width: 800, height: 1200 };

        hm.plotDotsRound([{ x: 400, y: 600, weight: 2 }]);

        expect(rec.reads[0]).toEqual({ x: 0, y: 0, w: 800, h: 1200 });
        expect(rec.writes[0]).toEqual({ x: 0, y: 0 });
    });
});

describe('colourising', () => {

    test('a fully transparent pixel is left alone', () => {
        const rec = freshRecorder();
        // one transparent pixel
        rec.pixels = new Uint8ClampedArray([0, 0, 0, 0]);

        const hm = makeHeatmap(Heatmap, rec, { w: 1, h: 1 });
        hm.shadowCanvas = { width: 1, height: 1 };
        hm.getRgbFromAlpha = () => ({ r: 255, g: 0, b: 0 });

        hm.colorize();

        // Colouring it would paint the entire untouched page the coldest colour.
        expect(Array.from(rec.pixels)).toEqual([0, 0, 0, 0]);
    });

    test('a pixel carrying alpha is given the colour for that alpha', () => {
        const rec = freshRecorder();
        rec.pixels = new Uint8ClampedArray([0, 0, 0, 128]);

        const hm = makeHeatmap(Heatmap, rec, { w: 1, h: 1 });
        hm.shadowCanvas = { width: 1, height: 1 };
        hm.getRgbFromAlpha = (a) => (a === 128 ? { r: 10, g: 20, b: 30 } : { r: 0, g: 0, b: 0 });

        hm.colorize();

        expect(Array.from(rec.pixels)).toEqual([10, 20, 30, 128]);
    });
});
