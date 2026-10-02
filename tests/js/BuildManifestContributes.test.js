/**
 * @jest-environment node
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { discoverConfigs } = require('../../webpack.config.js');

/**
 * A module compiles source into ANOTHER module's bundle by listing it under
 * `contributes` in its build manifest. The file joins that package's entry,
 * so the package's own source never names the contributor.
 *
 * It runs BEFORE the package's own entry. The tracker's entry drains the
 * page's owa_cmds as it runs, so a plugin registered after it would never see
 * the snippet's command to start it.
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

test('a contributed file joins the target package\'s entry, ahead of its own', () => {
    const dir = modulesDir({
        Core: CORE,
        Addon: { contributes: { 'core.js': ['src/plugin.js'] } },
    });

    const [config] = discoverConfigs(dir);

    expect(config.entry['core.js']).toEqual([
        path.join(dir, 'Addon', 'src/plugin.js'),
        path.join(dir, 'Core', 'src/core.js'),
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

const LAZY = {
    name: 'addon', chunk: 'core.addon', entry: 'src/addon.js',
    methods: ['trackAddon'], reservedEventNames: ['addon'],
};

const CORE_WITH_API = {
    packages: [{
        name: 'core.js', type: 'js', entry: 'src/core.js', outputDir: 'dist', splitVendors: false,
        pluginApi: { path: 'src/api.js', export: 'Api' }, bundleManifest: 'core.manifest.json',
    }],
};

test('a lazy plugin joins the entry as a generated stub that imports its chunk', () => {
    const dir = modulesDir({ Core: CORE_WITH_API, Addon: { contributes_lazy: { 'core.js': [LAZY] } } });

    const [config] = discoverConfigs(dir);
    const entry = config.entry['core.js'];

    expect(entry).toHaveLength(2);
    expect(entry[1]).toBe(path.join(dir, 'Core', 'src/core.js'));

    const stub = fs.readFileSync(entry[0], 'utf8');
    expect(stub).toContain(`import { Api as Api } from ${JSON.stringify(path.join(dir, 'Core', 'src/api.js'))}`);
    expect(stub).toContain('Api.registerLazyPlugin({');
    expect(stub).toContain('methods: ["trackAddon"]');
    expect(stub).toContain(`import(/* webpackChunkName: "core.addon" */ ${JSON.stringify(path.join(dir, 'Addon', 'src/addon.js'))})`);

    // Not under node_modules: the vendor split would move it into a chunk the entry then needs.
    expect(entry[0]).not.toMatch(/node_modules/);
});

test('a lazy plugin needs the package to declare its plugin API', () => {
    const dir = modulesDir({ Core: CORE, Addon: { contributes_lazy: { 'core.js': [LAZY] } } });

    expect(() => discoverConfigs(dir)).toThrow(/declares no pluginApi/);
});

test('a lazy plugin to a package nobody declares fails the build', () => {
    const dir = modulesDir({ Core: CORE_WITH_API, Addon: { contributes_lazy: { 'missing.js': [LAZY] } } });

    expect(() => discoverConfigs(dir)).toThrow(/lazy plugin to 'missing.js'/);
});

test('the real tree: the recorder is a lazy chunk of the tracker, not compiled into it', () => {
    const configs = discoverConfigs(path.resolve(__dirname, '../../modules'));
    const tracker = configs.find((c) => c.entry['owa.tracker.js']);
    const entry = tracker.entry['owa.tracker.js'];

    expect(entry[entry.length - 1]).toMatch(/modules[\\/]Base[\\/]src[\\/]tracker[\\/]tracker-dom\.js$/);
    expect(entry.some((f) => /Recorder\.js$/.test(f))).toBe(false);

    // Playback is compiled in eagerly: an overlay opens the player on any page,
    // recording or not, so it cannot wait for the recorder's chunk.
    expect(entry.some((f) => /modules[\\/]Domstream[\\/]src[\\/]tracker[\\/]Overlay\.js$/.test(f))).toBe(true);

    const stub = fs.readFileSync(entry.find((f) => /lazy-domstream\.js$/.test(f)), 'utf8');
    expect(stub).toContain('webpackChunkName: "owa.domstream"');
    expect(stub).toMatch(/modules[\\/]Domstream[\\/]src[\\/]tracker[\\/]Recorder\.js/);
});
