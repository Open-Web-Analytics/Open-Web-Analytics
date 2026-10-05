import $ from 'jquery';
import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js');

/**
 * The narrowest a report grid may be (OWA.dataGrid.readableWidth): its fixed
 * metric columns at their widths, and each flexible column at what its content
 * measured, up to a cap. On a phone the grid is never squeezed below this; its
 * widget scrolls instead.
 */
function floorOf(columns, showRowNumbers = false) {
    return OWA.dataGrid.prototype.readableWidth.call({ options: { showRowNumbers } }, columns);
}

test('fixed columns count at their width, flexible ones at their measured width', () => {
    expect(floorOf([
        { name: 'pagePath', width: 90 },
        { name: 'pageViews', fixed: true, width: 100 },
    ])).toBe(190);
});

test('a long flexible column does not set the floor: it is capped', () => {
    expect(floorOf([{ name: 'pageUrl', width: 320 }])).toBe(120);
});

test('hidden columns take no room, and row numbers do', () => {
    expect(floorOf([{ name: 'x', width: 80 }, { name: 'y', hidden: true, width: 300 }], true)).toBe(105);
});

test('a flexible column with no measured width counts at the cap, not 150', () => {
    expect(floorOf([{ name: 'z' }])).toBe(120);
});
