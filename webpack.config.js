const fs = require('fs');
const path = require('path');
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
// A manifest package is one of:
//   JS  { name, type:'js', entry, outputDir, splitVendors }
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
function jsConfig(moduleName, moduleDir, pkg) {
	return {
		name: `${moduleName}:${pkg.name}`,
		entry: {
			[pkg.name]: [path.resolve(moduleDir, pkg.entry)],
		},
		output: jsOutput(moduleDir, pkg),
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

function configForPackage(moduleName, moduleDir, pkg) {
	switch (pkg.type) {
		case 'js':
			return jsConfig(moduleName, moduleDir, pkg);
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
function discoverConfigs() {
	const configs = [];

	for (const moduleName of fs.readdirSync(modulesDir).sort()) {
		const moduleDir = path.join(modulesDir, moduleName);
		const manifestPath = path.join(moduleDir, MANIFEST);
		if (!fs.existsSync(manifestPath)) {
			continue;
		}

		const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
		for (const pkg of manifest.packages || []) {
			configs.push(configForPackage(moduleName, moduleDir, pkg));
		}
	}

	return configs;
}

module.exports = discoverConfigs();
