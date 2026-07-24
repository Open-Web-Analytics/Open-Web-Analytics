const fs = require('fs');
const path = require('path');
const TerserPlugin = require('terser-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const RemoveEmptyScriptsPlugin = require('webpack-remove-empty-scripts');

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
//   CSS { name, type:'css', outputDir, files:[...] }
// where entry/files/outputDir are paths RELATIVE to the module directory.

const modulesDir = path.resolve(__dirname, 'modules');
const MANIFEST = 'build.manifest.json';

const minimizer = [new TerserPlugin({ extractComments: false })];

// Build the webpack `output` block shared by both JS product types. Kept exactly
// as the two inline JS configs used it: emit `[name]` verbatim (the package name
// IS the filename, so no PHP path churn) into the module's output dir, with
// iife:false so a vendors split emits a sibling entry chunk rather than a runtime
// import() (see the tracker note below).
function jsOutput(moduleDir, pkg) {
	return {
		path: path.resolve(moduleDir, pkg.outputDir),
		chunkFilename: '[name].js',
		iife: false,
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
// (`[name]`) into the module's output dir. css-loader runs with url:false so every
// url() is left EXACTLY as authored -- combined with keeping the output dir the
// same as the source dir, the relative asset paths (images/ui-icons_*,
// chosen-sprite.png, ../i/*) stay valid without rewriting a single url(). The
// `files` order is the cascade order (later files intentionally override earlier).
// A CSS-only entry still emits a stub .js chunk, which RemoveEmptyScriptsPlugin
// deletes. Output is NOT minified (the artifact has no -min suffix).
function cssConfig(moduleName, moduleDir, pkg) {
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
		plugins: [
			new RemoveEmptyScriptsPlugin(),
			new MiniCssExtractPlugin({ filename: '[name]' }),
		],
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
