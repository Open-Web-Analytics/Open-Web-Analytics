/**
 * @jest-environment node
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { discoverConfigs } = require('../../webpack.config.js');

/**
 * A module compiles source into ANOTHER module's bundle by listing it under
 * `contributes` in its build manifest. The file is appended to that package's
 * entry, so the package's own source never names the contributor.
 */

function modulesDir(manifests) {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'owa-manifests-'));
    for (const [name, manifest] of Object.entries(manifests)) {
        fs.mkdirSync(path.join(dir, name));
        fs.writeFileSync(path.join(dir, name, 'build.manifest.json'), JSON.stringify(manifest));
    }
    return dir;
}

const CORE = {
    packages: [{ name: 'core.js', type: 'js', entry: 'src/core.js', outputDir: 'dist', splitVendors: false }],
};

test('a contributed file is appended to the target package\'s entry', () => {
    const dir = modulesDir({
        Core: CORE,
        Addon: { contributes: { 'core.js': ['src/plugin.js'] } },
    });

    const [config] = discoverConfigs(dir);

    expect(config.entry['core.js']).toEqual([
        path.join(dir, 'Core', 'src/core.js'),
        path.join(dir, 'Addon', 'src/plugin.js'),
    ]);
});

test('a module that only contributes builds no package of its own', () => {
    const dir = modulesDir({ Core: CORE, Addon: { contributes: { 'core.js': ['src/plugin.js'] } } });

    expect(discoverConfigs(dir)).toHaveLength(1);
});

test('a contribution to a package nobody declares fails the build', () => {
    const dir = modulesDir({ Core: CORE, Addon: { contributes: { 'missing.js': ['src/plugin.js'] } } });

    expect(() => discoverConfigs(dir)).toThrow(/contributes to 'missing.js'/);
});

test('the real tree: the tracker bundle\'s entry starts with Base\'s own entry', () => {
    const configs = discoverConfigs(path.resolve(__dirname, '../../modules'));
    const tracker = configs.find((c) => c.entry['owa.tracker.js']);

    expect(tracker.entry['owa.tracker.js'][0]).toMatch(/modules[\\/]Base[\\/]src[\\/]tracker[\\/]tracker-dom\.js$/);
});
