const fs = require('fs');
const path = require('path');

/**
 * How many dimension pickers a grid's control bar will show.
 *
 * The bar is one picker per dimension the grid is grouped by, and a widget can
 * be as narrow as a quarter of the page. At three pickers plus the filter
 * control the bar stopped fitting and .owa_reportGridItem's overflow-x turned
 * that into a horizontal scrollbar across the whole widget.
 *
 * So the control caps at two -- and a widget already scoped to MORE than two
 * renders no control at all. That case is a report author being specific
 * (Latest Visits groups by seven), and a two-slot control over seven
 * dimensions could only take dimensions away. Nothing is hidden by it: the
 * grid's own column headings still name every dimension.
 *
 * Asserted against the built bundle rather than a copy of the source, so a
 * change to the shipped control is what this reads.
 */
describe('the dimension control caps at two slots', () => {

    const repoRoot = path.resolve(__dirname, '../../..');
    const bundlePath = path.join(repoRoot, 'public/base/dist/owa.reporting-combined-min.js');

    let OWA;
    let jq;

    beforeAll(() => {
        if (!fs.existsSync(bundlePath)) { return; }

        const code = fs.readFileSync(bundlePath, 'utf8');
        const run = new Function('window', 'document', 'navigator', code);

        run(window, document, navigator);

        OWA = window.OWA;
        jq = window.jQuery;
    });

    /** {family: [{name,label}]} -- the shape relatedDimensions has. */
    const choices = {
        Visitor: [
            { name: 'browserType', label: 'Browser Type' },
            { name: 'osName', label: 'Operating System' },
        ],
        Visit: [
            { name: 'medium', label: 'Medium' },
            { name: 'date', label: 'Date' },
        ],
    };

    function build(selected) {
        jq('body').append('<span id="dimtarget"></span>');

        const control = new OWA.dimensionSelectors('#dimtarget', {
            choices: choices,
            selected: selected,
        });

        control.display();

        return { control, $root: jq('#dimtarget') };
    }

    afterEach(() => {
        if (jq) { jq('#dimtarget').remove(); }
    });

    test('one dimension gets one picker and a way to add another', () => {
        if (!OWA) return;

        const { $root } = build(['browserType']);

        expect($root.find('.owa_dimSlot').length).toBe(1);
        expect($root.find('.owa_dimAdd').length).toBe(1);
    });

    test('at two dimensions there is no add button left', () => {
        if (!OWA) return;

        const { $root } = build(['browserType', 'date']);

        expect($root.find('.owa_dimSlot').length).toBe(2);

        // The cap has to be enforced by removing the way to exceed it, not by
        // refusing afterwards -- a plus that does nothing reads as broken.
        expect($root.find('.owa_dimAdd').length).toBe(0);
    });

    test('a widget scoped to more than two renders no control at all', () => {
        if (!OWA) return;

        const { $root } = build(['browserType', 'date', 'medium']);

        expect($root.find('.owa_dimSlot').length).toBe(0);
        expect($root.find('.owa_dimAdd').length).toBe(0);

        // And the wrapper class goes too, so the bar has no empty flex item
        // holding its gap open where the control used to be.
        expect($root.hasClass('owa_dimensionSelectors')).toBe(false);
    });

    test('the disabled case keeps the widget\'s dimensions, it does not drop them', () => {
        if (!OWA) return;

        const { control } = build(['browserType', 'date', 'medium']);

        // Rendering nothing must not be mistaken for "grouped by nothing": the
        // control still reports what the grid is grouped by, so a later
        // refetch cannot silently regroup the widget.
        expect(control.value()).toEqual(['browserType', 'date', 'medium']);
    });
});
