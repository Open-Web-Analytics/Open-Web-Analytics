/**
 * @jest-environment node
 */
/**
 * The self-hosted runner's global setup and teardown (tests/e2e/selfhost-global-*.js).
 *
 * Setup refuses a checkout without the built assets before it runs anything.
 * Playwright runs the teardown even when setup threw, so the teardown unseeds only
 * while owa-config.php is the scratch config (`selfhost_harness.php owned`): the
 * seeder deletes from whatever database the config names.
 */
jest.mock('child_process', () => ({ execFileSync: jest.fn() }));

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const setup = require('../e2e/selfhost-global-setup.js');
const teardown = require('../e2e/selfhost-global-teardown.js');

/** The script and command of each php call, in order. */
const calls = () => execFileSync.mock.calls.map(([, args]) => `${path.basename(args[0])} ${args[1]}`);

beforeEach(() => {
    execFileSync.mockReset();
    jest.restoreAllMocks();
});

describe('selfhost global setup', () => {

    test('a checkout without the built assets is refused before anything runs', async () => {
        jest.spyOn(fs, 'existsSync').mockReturnValue(false);

        await expect(setup()).rejects.toThrow(/built assets are missing: .*public\/base\/dist\/owa\.reporting-combined-min\.js.*npm run build/);
        expect(execFileSync).not.toHaveBeenCalled();
    });

    test('with the assets built it brings the install up, then seeds', async () => {
        jest.spyOn(fs, 'existsSync').mockReturnValue(true);

        await setup();

        expect(calls()).toEqual(['selfhost_harness.php up', 'seed_reporting_fixtures.php seed']);
    });

    test('the assets it checks are the build output', () => {
        expect(setup.BUILT).toEqual(expect.arrayContaining([
            'public/base/dist/owa.reporting-combined-min.js',
            'public/base/css/owa.reporting-css-combined.css',
        ]));
    });
});

describe('selfhost global teardown', () => {

    test('with a config the harness did not write it does not unseed, and still runs down', async () => {
        jest.spyOn(console, 'error').mockImplementation(() => {});
        execFileSync.mockImplementation((cmd, args) => {
            if (args[1] === 'owned') {
                throw new Error('exit 1');
            }
        });

        await teardown();

        expect(calls()).toEqual(['selfhost_harness.php owned', 'selfhost_harness.php down']);
    });

    test('with the scratch config it unseeds before down', async () => {
        await teardown();

        expect(calls()).toEqual(['selfhost_harness.php owned', 'seed_reporting_fixtures.php teardown', 'selfhost_harness.php down']);
    });
});
