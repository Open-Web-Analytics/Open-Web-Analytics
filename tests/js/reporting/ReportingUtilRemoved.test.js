// The reporting bundle calls jQuery(...) at load: mark the real jQuery as an
// ES module, as the other reporting specs do.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../../modules/Base/src/reporting/owa.format.js';

const fs = require('fs');
const path = require('path');

/**
 * OWA.util has no countObjectProperties, and nothing in the reporting
 * sources names it.
 */
describe('OWA.util has no countObjectProperties', () => {

    test('the helper is not defined', () => {
        expect(OWA.util.countObjectProperties).toBeUndefined();
        expect(typeof OWA.util.is_object).toBe('function');
    });

    test('no reporting source calls it', () => {
        const dir = path.join(__dirname, '../../../modules/Base/src/reporting');

        const names = fs.readdirSync(dir).filter((f) => f.endsWith('.js'));

        expect(names.length).toBeGreaterThan(5);

        const naming = names.filter((f) =>
            fs.readFileSync(path.join(dir, f), 'utf8').includes('countObjectProperties'));

        expect(naming).toEqual([]);
    });
});
