const path = require('path');
const webpack = require('webpack');
const dist_path = '/modules/base/dist';
const src_path = __dirname + '/modules/base/src';
const TerserPlugin = require('terser-webpack-plugin');

// Filename of the reporting bundle. Kept IDENTICAL to the previously-emitted
// output so report templates keep loading one file and no PHP path changes (the
// ?version=OWA_VERSION cache-busting is preserved).
const REPORTING_BUNDLE = 'owa.reporting-combined-min.js';

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

// --- Tracker: unchanged from the pre-3.3a build (vendors split + no ProvidePlugin). ---
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

// --- Reporting: Phase 3.3a. Was a flat file-concat plugin output minified by a
// standalone terser transform; now a real webpack module graph. ---
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

module.exports = [trackerConfig, reportingConfig];
