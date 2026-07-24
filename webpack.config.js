const path = require('path');
const webpack = require('webpack');
const dist_path = '/modules/base/dist';
const src_path = __dirname + '/modules/base/src';
const css_path = __dirname + '/modules/base/css';
const TerserPlugin = require('terser-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const RemoveEmptyScriptsPlugin = require('webpack-remove-empty-scripts');

// Filename of the reporting bundle. Kept IDENTICAL to the previously-emitted
// output so report templates keep loading one file and no PHP path changes (the
// ?version=OWA_VERSION cache-busting is preserved).
const REPORTING_BUNDLE = 'owa.reporting-combined-min.js';

// Filename of the combined reporting stylesheet. Kept IDENTICAL to the file the
// former PHP-CLI `cmd=build` concat used to emit, and emitted to the SAME
// directory (modules/base/css/) -- both matter: the two setCss() call
// sites (owa_view.php:930, report.php:114) are unchanged, AND every url() in the
// source CSS is a path relative to modules/base/css/ (images/ui-icons_*,
// chosen-sprite.png, ../i/funnel_*), so keeping the output dir identical keeps
// every asset reference valid without rewriting a single url().
const REPORTING_CSS = 'owa.reporting-css-combined.css';

const output = {
	path: __dirname + dist_path, // Output to dist directory
	chunkFilename: '[name].js',
	iife: false,
	filename: '[name]',
};

const minimizer = [new TerserPlugin({ extractComments: false })];

// Two independent products are built from disjoint module graphs, so they are
// two separate compiler configs (a webpack multi-config array) rather than one
// shared config. The critical reason they MUST stay separate: the reporting
// bundle needs webpack.ProvidePlugin to feed a bundled jQuery to its legacy
// plugins, but the TRACKER must NOT get ProvidePlugin -- common/Util.js
// references a bare global `jQuery` (jQuery.getScript / jQuery.param) that is
// meant to resolve to the *host page's* jQuery at runtime, and ProvidePlugin
// would instead bundle a second jQuery into the tracker and change that
// behavior. Keeping the configs separate scopes ProvidePlugin to reporting only.

// --- Tracker: vendors split + no ProvidePlugin. ---
const trackerConfig = {
	name: 'tracker',
	entry: {
		'owa.tracker.js': [
			path.resolve(__dirname, src_path + '/tracker/tracker-dom.js'),
		],
	},
	output,
	optimization: {
		minimize: true,
		minimizer,
		splitChunks: {
			cacheGroups: {
				vendor: {
					test: /[\\/]node_modules[\\/]/,
					name: 'owa.vendors',
					chunks: 'all',
				},
			},
		},
	},
};

// --- Reporting: a real webpack module graph (single self-contained bundle). ---
const reportingConfig = {
	name: 'reporting',
	entry: {
		[REPORTING_BUNDLE]: [
			path.resolve(__dirname, src_path + '/reporting/v1/reporting-entry.js'),
		],
	},
	output,
	optimization: {
		minimize: true,
		minimizer,
		// The report templates load ONLY owa.reporting-combined-min.js, so the
		// bundle must be a single self-contained file -- do NOT split vendors out.
		splitChunks: false,
	},
	plugins: [
		// The legacy jQuery plugins (chosen, flot) and OWA's own reporting files all
		// reference a bare global `jQuery`/`$`. Under webpack module scope there is no
		// such global, so ProvidePlugin rewrites those free identifiers to
		// require('jquery') -- a single shared jQuery instance across the graph. This
		// is what lets the vendor files (which we can't edit inside node_modules) and
		// OWA's files work without an explicit jQuery import in each.
		new webpack.ProvidePlugin({
			$: 'jquery',
			jQuery: 'jquery',
		}),
	],
};

// --- Reporting CSS: emits modules/base/css/owa.reporting-css-combined.css,
// formerly concatenated by the PHP-CLI build controller (base.build /
// owa_buildController). This is a THIRD config because it is CSS-only and the two
// JS configs above must not grow a CSS pipeline they don't use.
//
// The entry is the SAME six source files in the SAME order the former PHP package
// used (jquery-ui -> jqgrid -> chosen -> owa -> owa.admin -> owa.report), so
// ordered cascade wins are preserved byte-for-source. css-loader runs with
// url:false so every url() is left EXACTLY as authored -- combined with the css/
// output dir, the relative asset paths stay valid (see REPORTING_CSS note above).
// The output is NOT minified (the artifact has no -min suffix); the goal is
// retiring the PHP build path, not shrinking bytes.
const reportingCssConfig = {
	name: 'reporting-css',
	entry: {
		// The .js key is a throwaway: a CSS-only entry still emits a (near-empty)
		// JS chunk, which RemoveEmptyScriptsPlugin deletes below. MiniCssExtractPlugin
		// names the actual stylesheet via filename, keyed off this entry name.
		[REPORTING_CSS]: [
			path.resolve(css_path, 'jquery-ui.css'),
			path.resolve(css_path, 'ui.jqgrid.css'),
			path.resolve(css_path, 'chosen.css'),
			path.resolve(css_path, 'owa.css'),
			path.resolve(css_path, 'owa.admin.css'),
			path.resolve(css_path, 'owa.report.css'),
		],
	},
	output: {
		path: css_path,
	},
	module: {
		rules: [
			{
				test: /\.css$/,
				use: [
					MiniCssExtractPlugin.loader,
					{
						loader: 'css-loader',
						// Leave every url() untouched -- do not try to resolve or
						// inline the referenced images. The paths are relative to the
						// css/ output dir and resolve at runtime as-is.
						options: { url: false, import: false },
					},
				],
			},
		],
	},
	plugins: [
		// A CSS-only entry still produces a stub .js file; drop it.
		new RemoveEmptyScriptsPlugin(),
		// Emit the combined stylesheet under the exact legacy filename.
		new MiniCssExtractPlugin({ filename: '[name]' }),
	],
};

module.exports = [trackerConfig, reportingConfig, reportingCssConfig];
