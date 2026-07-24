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
			          	// plugins below still rely on (andSelf, $.attrFn, event shorthands);
			          	// owa.jquery-compat-shim.js adds back $.browser, which migrate 3.x
			          	// does not restore but sparkline + jQuery-UI 1.8.12 need.
			          	__dirname + '/node_modules/jquery/dist/jquery.min.js',
			          	__dirname + '/node_modules/jquery-migrate/dist/jquery-migrate.min.js',
					  	src_path + '/reporting/v1/includes/jquery/owa.jquery-compat-shim.js',
					  	src_path + '/reporting/v1/includes/jquery/jquery-ui-1.8.12.custom.min.js',
					  	src_path + '/reporting/v1/includes/jquery/jquery.ui.selectmenu.js',
					  	src_path + '/reporting/v1/includes/jquery/chosen.jquery.js',
					  	// jquery.sparkline 1.2.1 (1.6-era, read $.browser.msie at LOAD) ->
					  	// jquery-sparkline 2.4.0 (jQuery 3.x-clean, same .sparkline() API) from npm.
					  	__dirname + '/node_modules/jquery-sparkline/jquery.sparkline.min.js',
					  	// jqGrid 3.6.5 (1.6-era, throws on $.browser) -> free-jqgrid 4.15.5
					  	// (maintained fork, jQuery 3.x-compatible) from the npm dep.
					  	__dirname + '/node_modules/free-jqgrid/dist/jquery.jqgrid.min.js',
					  	src_path + '/reporting/v1/includes/jquery/flot_v0.7/jquery.flot.min.js',
					  	src_path + '/reporting/v1/includes/jquery/flot_v0.7/jquery.flot.resize.min.js',
					  	src_path + '/reporting/v1/includes/jquery/flot_v0.7/jquery.flot.pie.min.js',
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