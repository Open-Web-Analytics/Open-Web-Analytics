const path = require('path');
const dist_path = '/modules/base/dist';
const src_path = __dirname + '/modules/base/src';
const terser = require('terser');
const WebpackConcatPlugin = require('webpack-concat-files-plugin');
const TerserPlugin = require('terser-webpack-plugin');

module.exports = {

	entry: {
    
	    'owa.tracker.js': [
		    
	    	path.resolve(__dirname, src_path + '/tracker/tracker-dom.js')
	    ],
	    
	},
  
	output: {
	  
	  	path: __dirname + dist_path, // Output to dist directory
	  	chunkFilename: '[name].js',
	    iife: false,
	    filename: "[name]"
	},
  
	optimization: {
    
        minimize: true,
        minimizer: [new TerserPlugin({
	      extractComments: false,
	    })],
        
	    splitChunks: {
	      cacheGroups: {
	        vendor: {
	          test: /[\\/]node_modules[\\/]/,
	          name: 'owa.vendors',
	          chunks: 'all'
	        }
	      }
	    }
    },
    
    plugins: [
	    new WebpackConcatPlugin({
	      	bundles: [
		        {
		          	dest: __dirname + dist_path + '/owa.reporting-combined-min.js',
				  	src: [

			          	// jQuery core flipped 1.6.4 -> 3.x (Phase 3.2). Sourced from the
			          	// npm dep (package.json: jquery ^3.6.0) rather than a vendored copy.
			          	// jquery-migrate bridges the 1.x->3.x API removals the legacy
			          	// plugins below still rely on (andSelf, $.attrFn, event shorthands).
			          	// The owa.jquery-compat-shim.js ($.browser/$.curCSS) was DELETED once
			          	// every plugin went jQuery-3.x-clean (jQuery-UI -> 1.13.3, Flot -> 0.8.3).
			          	__dirname + '/node_modules/jquery/dist/jquery.min.js',
			          	__dirname + '/node_modules/jquery-migrate/dist/jquery-migrate.min.js',
					  	// jQuery-UI 1.8.12 (vendored custom build, needed $.browser + $.curCSS
					  	// at runtime) -> jquery-ui-dist 1.13.3 from npm (jQuery 3.x-clean,
					  	// bundles selectmenu so the separate Nagel-fork ui.selectmenu is gone).
					  	__dirname + '/node_modules/jquery-ui-dist/jquery-ui.min.js',
					  	// chosen 0.9.6 (1.6-era, read $.browser; CSS prefix .chzn-*) ->
					  	// chosen-js 1.8.7 (jQuery 3.x-clean; CSS prefix .chosen-*) from npm.
					  	// The combined reporting CSS carries chosen-js 1.8.7's stylesheet.
					  	__dirname + '/node_modules/chosen-js/chosen.jquery.min.js',
					  	// jquery.sparkline 1.2.1 (1.6-era, read $.browser.msie at LOAD) ->
					  	// jquery-sparkline 2.4.0 (jQuery 3.x-clean, same .sparkline() API) from npm.
					  	__dirname + '/node_modules/jquery-sparkline/jquery.sparkline.min.js',
					  	// jqGrid 3.6.5 (1.6-era, throws on $.browser) -> free-jqgrid 4.15.5
					  	// (maintained fork, jQuery 3.x-compatible) from the npm dep.
					  	__dirname + '/node_modules/free-jqgrid/dist/jquery.jqgrid.min.js',
					  	// Flot 0.7 (vendored, read $.browser.msie in the pie plugin -- the
					  	// LAST compat-shim consumer) -> jquery.flot 0.8.3 from npm (jQuery
					  	// 3.x-clean, no $.browser, and ships the empty-legend `|| 0` guard
					  	// that OWA had to hand-patch onto 0.7's pie). NOTE: 0.8 EXTRACTED
					  	// time-axis support out of core into a separate jquery.flot.time.js
					  	// plugin (it was built into 0.7's core); owa.areachart uses
					  	// xaxis.mode:"time", so time.js MUST be concatenated or the area
					  	// chart's date axis breaks. npm ships readable (non-min) files; the
					  	// terser `after` transform below minifies the whole concat anyway.
					  	__dirname + '/node_modules/jquery.flot/jquery.flot.js',
					  	__dirname + '/node_modules/jquery.flot/jquery.flot.time.js',
					  	__dirname + '/node_modules/jquery.flot/jquery.flot.resize.js',
					  	__dirname + '/node_modules/jquery.flot/jquery.flot.pie.js',
					  	src_path + '/reporting/v1/includes/jquery/jQote2/jquery.jqote2.min.js',
					  	src_path + '/reporting/v1/owa.js',
					  	src_path + '/reporting/v1/owa.report.js',
					  	src_path + '/reporting/v1/owa.resultSetExplorer.js',
					  	src_path + '/reporting/v1/owa.sparkline.js',
					  	src_path + '/reporting/v1/owa.areachart.js',
					  	src_path + '/reporting/v1/owa.piechart.js',
					  	src_path + '/reporting/v1/owa.kpibox.js',
					],
					
					transforms: {
		            	after: async (code) => {
							const minifiedCode = await terser.minify(code);
							return minifiedCode.code;
		            	},
		          	},
		        },
		    ],
	    }),
	],
        
};