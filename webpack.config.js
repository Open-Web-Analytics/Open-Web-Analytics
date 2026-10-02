const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const TerserPlugin = require('terser-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const RemoveEmptyScriptsPlugin = require('webpack-remove-empty-scripts');
const CopyPlugin = require('copy-webpack-plugin');

// --- Per-module asset build registration ---------------------------------
//
// The build products are no longer hardcoded here. Instead each module declares
// what it builds in a `build.manifest.json` in its own directory, and this config
// DISCOVERS them by scanning modules/*/build.manifest.json -- the direct analogue
// of how root composer.json merges modules/*/composer.json via
// wikimedia/composer-merge-plugin. A module self-registers its assets by dropping
// that one file in its own dir; there is no central list to edit.
//
// Today only `base` ships build inputs, so exactly one manifest is found and the
// three configs below (tracker JS, reporting JS, reporting CSS) come out of it --
// byte-identical to when they were inline. The factories translate each manifest
// package into the right webpack config for its `type`.
//
// A manifest may also CONTRIBUTE source to another module's package:
//   "contributes": { "owa.tracker.js": ["src/tracker/Recorder.js"] }
// Each listed file (relative to the contributing module) joins that package's
// entry, so it compiles into the same bundle -- BEFORE the package's own entry,
// in module-name order. The contributed code registers itself (the tracker's
// OWATracker.registerPlugin), and the tracker's entry drains the page's
// owa_cmds as it runs, so a plugin registered after it would miss the
// snippet's commands. The package's own source names no contributor. A
// contribution to a package no manifest declares is an error.
//
// A manifest may also contribute a LAZY plugin: built as its own chunk and
// loaded the first time one of its commands is called (PLAN 2.24.3).
//   "contributes_lazy": { "owa.tracker.js": [ { "name": "domstream",
//       "chunk": "owa.domstream", "entry": "src/tracker/Recorder.js",
//       "methods": ["trackDomStream"], "reservedEventNames": ["domstream"] } ] }
// The build generates a stub that registers the commands with the package's
// plugin API -- which the package declares as `pluginApi: {path, export}` --
// and joins the entry like a contribution. The chunk's own code registers the
// real plugin when it arrives. A package that declares `bundleManifest` also
// emits that file: its own file and each lazy chunk's, with their SHA-256, for
// whatever composes them (publishing a Profile's tracking bundle).
// A manifest package is one of:
//   JS  { name, type:'js', entry, outputDir, splitVendors, licence? }
//   CSS { name, type:'css', outputDir, files:[...], copy:[{from,to,ignore?}] }
// where entry/files paths and outputDir are RELATIVE to the module directory
// (outputDir may reach outside it, e.g. ../../public/base/dist, to emit into the
// web-facing public/ tree). `copy` (CSS only) brings the stylesheet's url()-
// referenced assets -- sprites, theme images, fonts, and the ../i/ siblings -- into
// the public output verbatim so url:false relative paths keep resolving; each entry's
// from/to are relative to the module dir / the package outputDir respectively.

const modulesDir = path.resolve(__dirname, 'modules');
const MANIFEST = 'build.manifest.json';

const minimizer = [new TerserPlugin({ extractComments: false })];

// Build the webpack `output` block shared by both JS product types: emit `[name]`
// verbatim (the package name IS the filename, so no PHP path churn) into the
// module's output dir.
//
// `output.iife` is deliberately LEFT AT ITS DEFAULT (true for target:web). It used
// to be forced to false, carried over unexplained from the original webpack
// migration (0592fcb6). That is not a safe setting for a bundle that ships as a
// classic <script>: production mode enables scope hoisting, so webpack concatenates
// the entry's whole module graph into one scope, and without the IIFE that scope IS
// the page's global scope. Every top-level declaration in every concatenated source
// file then becomes a global binding -- the tracker's event class, then still
// named `Event` (it is OwaEvent now, in tracker/OwaEvent.js), shadowed the DOM's
// window.Event for the entire page, breaking any library that does `new Event(...)`
// (Bootstrap's dropdowns, modals and tabs, for instance).
//
// The flag also does not do what the old comment claimed. `output.iife` controls
// only the wrapper; whether a vendors split lands in a sibling chunk or an async
// import() is governed by `optimization.splitChunks` below, which is unchanged.
function jsOutput(moduleDir, pkg) {
	return {
		path: path.resolve(moduleDir, pkg.outputDir),
		chunkFilename: '[name].js',
		filename: '[name]',
	};
}

// JS package -> webpack config.
//
// `splitVendors` is either false (single self-contained file -- the reporting
// templates load exactly one script, so vendors must NOT be split out) or a chunk
// name (the tracker splits node_modules into a sibling vendors chunk).
//
// NOTE (Phase 4): there is no longer a ProvidePlugin. The reporting bundle used to
// need one to feed its legacy vendor plugins (chosen, flot, jquery-ui, ...) a bundled
// jQuery via the free `jQuery`/`$` globals they read at eval time; that scoped the
// two products into separate configs. Now the reporting entry publishes jQuery on
// window itself (its first import, vendor-jquery-global.js) before the plugins run,
// and OWA's own files import jQuery explicitly -- so no config-level jQuery injection
// is needed and both products share this one factory.
// Where the lazy-plugin stubs are written. Gitignored, and NOT under
// node_modules: the tracker's vendor split matches /node_modules/, and a stub
// there was moved into a vendors chunk the entry then depended on -- a second
// script every page would need before the tracker could run.
const GENERATED_DIR = path.join(__dirname, '.build', 'generated');

// One lazy contribution -> the stub module that registers it.
function lazyStub(moduleDir, pkg, lazy) {
	if (!pkg.pluginApi || !pkg.pluginApi.path || !pkg.pluginApi.export) {
		throw new Error(
			`${lazy.moduleName}/${MANIFEST}: contributes a lazy plugin to '${pkg.name}', which declares no pluginApi`
		);
	}
	const spec = lazy.spec;
	for (const key of ['name', 'chunk', 'entry']) {
		if (typeof spec[key] !== 'string' || !spec[key]) {
			throw new Error(`${lazy.moduleName}/${MANIFEST}: a lazy plugin needs '${key}'`);
		}
	}
	if (!/^[A-Za-z0-9._-]+$/.test(spec.chunk)) {
		throw new Error(`${lazy.moduleName}/${MANIFEST}: chunk name '${spec.chunk}' is not a plain file name`);
	}

	const api = path.resolve(moduleDir, pkg.pluginApi.path);
	const entry = path.resolve(lazy.moduleDir, spec.entry);
	const file = path.join(GENERATED_DIR, `${pkg.name}.lazy-${spec.name}.js`);

	const source = [
		'// Generated by webpack.config.js from ' + lazy.moduleName + '/' + MANIFEST + '. Do not edit.',
		`import { ${pkg.pluginApi.export} as Api } from ${JSON.stringify(api)};`,
		'Api.registerLazyPlugin({',
		`\tname: ${JSON.stringify(spec.name)},`,
		`\tmethods: ${JSON.stringify(spec.methods || [])},`,
		`\treservedEventNames: ${JSON.stringify(spec.reservedEventNames || [])},`,
		`\tload: () => import(/* webpackChunkName: ${JSON.stringify(spec.chunk)} */ ${JSON.stringify(entry)}),`,
		'});',
		'',
	].join('\n');

	fs.mkdirSync(GENERATED_DIR, { recursive: true });
	if (!fs.existsSync(file) || fs.readFileSync(file, 'utf8') !== source) {
		fs.writeFileSync(file, source);
	}

	return file;
}

// Emits a package's bundle manifest: its own file and each lazy chunk's, with
// their SHA-256, computed from what is actually written.
class BundleManifestPlugin {
	constructor(filename, core, chunks) {
		this.filename = filename;
		this.core = core;
		this.chunks = chunks;
	}

	// webpack's own classes come from the compiler it hands the plugin: this
	// config imports no webpack itself (see BundleIntegrity.test.js).
	apply(compiler) {
		const { Compilation, sources } = compiler.webpack;

		compiler.hooks.thisCompilation.tap('OwaBundleManifest', (compilation) => {
			compilation.hooks.processAssets.tap(
				{ name: 'OwaBundleManifest', stage: Compilation.PROCESS_ASSETS_STAGE_REPORT },
				(assets) => {
					const describe = (file) => {
						if (!assets[file]) {
							compilation.errors.push(new Error(`bundle manifest: '${file}' was not emitted`));
							return { file, sha256: null };
						}
						return {
							file,
							sha256: crypto.createHash('sha256').update(assets[file].source()).digest('hex'),
						};
					};
					const manifest = { core: describe(this.core), plugins: {} };
					for (const { name, chunk } of this.chunks) {
						manifest.plugins[name] = describe(`${chunk}.js`);
					}
					compilation.emitAsset(
						this.filename,
						new sources.RawSource(JSON.stringify(manifest, null, 2) + '\n')
					);
				}
			);
		});
	}
}

// Keeps a package's version file -- committed, like package-lock.json -- in step
// with what the package is built from (PLAN 2.30.7). After each build it hashes
// every file webpack read outside node_modules, plus package-lock.json, this
// config and the modules' build manifests; when that hash differs from the one
// in the file, the version goes up by one and the file is rewritten.
//
// PHP reads the version: an install whose recorded tracker version is lower has
// an update pending, and the update republishes the Profiles' bundles. CI builds
// and fails if the file changed, so a tracker change cannot land without it.
//
// A PHP file rather than JSON because every request reads it, log.php included,
// and the opcode cache makes that free.
class TrackerVersionPlugin {
	constructor(file) {
		this.file = file;
	}

	static readCurrent(file) {
		if (!fs.existsSync(file)) {
			return { version: 0, sources: '' };
		}
		const text = fs.readFileSync(file, 'utf8');
		const version = /'version'\s*=>\s*(\d+)/.exec(text);
		const sources = /'sources'\s*=>\s*'([0-9a-f]*)'/.exec(text);
		return { version: version ? Number(version[1]) : 0, sources: sources ? sources[1] : '' };
	}

	static inputs(compilation) {
		const root = __dirname + path.sep;
		const files = new Set(
			[...compilation.fileDependencies].filter((f) =>
				f.startsWith(root)
				&& !f.includes(`${path.sep}node_modules${path.sep}`)
				&& !f.includes(`${path.sep}.build${path.sep}`)
				&& fs.existsSync(f) && fs.statSync(f).isFile()));

		files.add(path.join(__dirname, 'package-lock.json'));
		files.add(path.join(__dirname, 'webpack.config.js'));
		for (const dir of fs.readdirSync(path.join(__dirname, 'modules'))) {
			const manifest = path.join(__dirname, 'modules', dir, MANIFEST);
			if (fs.existsSync(manifest)) {
				files.add(manifest);
			}
		}

		return [...files].map((f) => path.relative(__dirname, f).split(path.sep).join('/')).sort();
	}

	apply(compiler) {
		compiler.hooks.afterEmit.tap('OwaTrackerVersion', (compilation) => {
			if (compilation.errors.length) {
				return;
			}
			const hash = crypto.createHash('sha256');
			for (const rel of TrackerVersionPlugin.inputs(compilation)) {
				hash.update(rel + '\0').update(fs.readFileSync(path.join(__dirname, rel))).update('\0');
			}
			const sources = hash.digest('hex');
			const current = TrackerVersionPlugin.readCurrent(this.file);

			if (current.sources === sources) {
				return;
			}

			fs.writeFileSync(this.file, [
				'<?php',
				'// GENERATED by the tracker build (webpack.config.js, TrackerVersionPlugin).',
				'// Commit it with the change, like package-lock.json. The version goes up when',
				'// anything the tracker is built from changes; an install recording a lower one',
				'// has an update pending, which republishes its Profiles\' bundles.',
				`return array( 'version' => ${current.version + 1}, 'sources' => '${sources}' );`,
				'',
			].join('\n'));
		});
	}
}

function jsConfig(moduleName, moduleDir, pkg, contributed = [], lazy = []) {
	const stubs = lazy.map((l) => lazyStub(moduleDir, pkg, l));

	return {
		name: `${moduleName}:${pkg.name}`,
		entry: {
			// Contributions first: see the manifest notes at the top of this file.
			[pkg.name]: [...contributed, ...stubs, path.resolve(moduleDir, pkg.entry)],
		},
		output: jsOutput(moduleDir, pkg),
		// A package may declare its own `licence` (a path relative to the module dir),
		// emitted VERBATIM next to the bundle. Only the tracker does: it was relicensed
		// BSD-3 in 2020 (#670) so site owners can embed it on non-GPL pages, while the
		// rest of OWA -- the reporting bundle included -- stays GPL-2.0. Do not widen
		// this to every package; that would ship the wrong licence beside GPL output.
		//
		// A SIBLING FILE, not a banner in the bundle. BSD-3 clause 2 lets a binary-form
		// redistribution carry the notice in "materials provided with the distribution",
		// and the tracker loads on every tracked page view -- a banner cost 1552 bytes
		// raw / 745 gzipped there for no legal gain. It cannot instead live with the
		// source: the release tarball excludes modules/Base/src and ships public/, so
		// public/ is the only place the notice actually reaches a user.
		plugins: (pkg.bundleManifest
			? [new BundleManifestPlugin(pkg.bundleManifest, pkg.name, lazy.map((l) => l.spec))]
			: []
		).concat(pkg.versionFile
			? [new TrackerVersionPlugin(path.resolve(moduleDir, pkg.versionFile))]
			: []
		).concat(pkg.licence
			? [
					new CopyPlugin({
						patterns: [
							{
								from: path.resolve(moduleDir, pkg.licence),
								// Named for the bundle, not the directory: several bundles share
								// this output dir and only this one is BSD-3 (owa.vendors.js is
								// third-party, owa.reporting-combined-min.js is GPL), so a bare
								// LICENSE.txt would read as covering all of them. Matches the
								// <bundle>.LICENSE.txt convention terser uses.
								to: `${pkg.name}.LICENSE.txt`,
							},
						],
					}),
			  ]
			: []),
		optimization: {
			minimize: true,
			minimizer,
			splitChunks: pkg.splitVendors
				? {
						cacheGroups: {
							vendor: {
								test: /[\\/]node_modules[\\/]/,
								name: pkg.splitVendors,
								chunks: 'all',
							},
						},
				  }
				: false,
		},
	};
}

// CSS package -> webpack config.
//
// mini-css-extract-plugin emits the combined stylesheet under the package name
// (`[name]`) into the package output dir (now public/base/css). css-loader runs with
// url:false so every url() is left EXACTLY as authored; the CopyPlugin below then
// mirrors the url()-referenced assets into the SAME relative layout under the public
// output (css sprites/theme-images beside the stylesheet, ../i/ as a sibling), so the
// verbatim relative paths (images/ui-icons_*, chosen-sprite.png, ../i/*) keep
// resolving without rewriting a single url() -- the public-tree analogue of the old
// same-dir strategy. The `files` order is the cascade order (later files
// intentionally override earlier). A CSS-only entry still emits a stub .js chunk,
// which RemoveEmptyScriptsPlugin deletes. Output is NOT minified (no -min suffix).
function cssConfig(moduleName, moduleDir, pkg) {
	const plugins = [
		new RemoveEmptyScriptsPlugin(),
		new MiniCssExtractPlugin({ filename: '[name]' }),
	];

	// Bring the stylesheet's url()-referenced assets into the public output verbatim.
	// `from` is resolved against the module dir; `to` against the package output dir.
	// A copied file that mini-css-extract also emits (the combined stylesheet itself,
	// if `from` is the css source dir) is excluded via `ignore` in the manifest.
	if (Array.isArray(pkg.copy) && pkg.copy.length) {
		plugins.push(
			new CopyPlugin({
				patterns: pkg.copy.map((c) => ({
					from: path.resolve(moduleDir, c.from),
					to: c.to,
					globOptions: c.ignore ? { ignore: c.ignore } : undefined,
					noErrorOnMissing: true,
				})),
			})
		);
	}

	return {
		name: `${moduleName}:${pkg.name}`,
		entry: {
			[pkg.name]: pkg.files.map((f) => path.resolve(moduleDir, f)),
		},
		output: {
			path: path.resolve(moduleDir, pkg.outputDir),
		},
		module: {
			rules: [
				{
					test: /\.css$/,
					use: [
						MiniCssExtractPlugin.loader,
						{ loader: 'css-loader', options: { url: false, import: false } },
					],
				},
			],
		},
		plugins,
	};
}

function configForPackage(moduleName, moduleDir, pkg, contributed = [], lazy = []) {
	switch (pkg.type) {
		case 'js':
			return jsConfig(moduleName, moduleDir, pkg, contributed, lazy);
		case 'css':
			return cssConfig(moduleName, moduleDir, pkg);
		default:
			throw new Error(
				`${moduleName}/${MANIFEST}: unknown package type '${pkg.type}' for '${pkg.name}'`
			);
	}
}

// Discover every modules/*/build.manifest.json and flatten its packages into a
// webpack multi-config array.
function discoverConfigs(dir = modulesDir) {
	const manifests = [];

	for (const moduleName of fs.readdirSync(dir).sort()) {
		const moduleDir = path.join(dir, moduleName);
		const manifestPath = path.join(moduleDir, MANIFEST);
		if (!fs.existsSync(manifestPath)) {
			continue;
		}

		manifests.push({
			moduleName,
			moduleDir,
			manifest: JSON.parse(fs.readFileSync(manifestPath, 'utf8')),
		});
	}

	// Every package any manifest declares, then what other modules contribute to it.
	const contributions = {};
	const lazyContributions = {};

	for (const { manifest } of manifests) {
		for (const pkg of manifest.packages || []) {
			contributions[pkg.name] = [];
			lazyContributions[pkg.name] = [];
		}
	}

	for (const { moduleName, moduleDir, manifest } of manifests) {
		for (const [target, specs] of Object.entries(manifest.contributes_lazy || {})) {
			if (!(target in lazyContributions)) {
				throw new Error(
					`${moduleName}/${MANIFEST}: contributes a lazy plugin to '${target}', which no manifest declares`
				);
			}
			for (const spec of specs) {
				lazyContributions[target].push({ moduleName, moduleDir, spec });
			}
		}
	}

	for (const { moduleName, moduleDir, manifest } of manifests) {
		for (const [target, files] of Object.entries(manifest.contributes || {})) {
			if (!(target in contributions)) {
				throw new Error(
					`${moduleName}/${MANIFEST}: contributes to '${target}', which no manifest declares`
				);
			}
			for (const file of files) {
				contributions[target].push(path.resolve(moduleDir, file));
			}
		}
	}

	const configs = [];

	for (const { moduleName, moduleDir, manifest } of manifests) {
		for (const pkg of manifest.packages || []) {
			configs.push(
				configForPackage(moduleName, moduleDir, pkg, contributions[pkg.name], lazyContributions[pkg.name])
			);
		}
	}

	return configs;
}

module.exports = discoverConfigs();
module.exports.discoverConfigs = discoverConfigs;
