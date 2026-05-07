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
			          
			          	src_path + '/reporting/v1/includes/jquery/jquery-1.6.4.min.js',
					  	src_path + '/reporting/v1/includes/jquery/jquery.sprintf.js',
					  	src_path + '/reporting/v1/includes/jquery/jquery-ui-1.8.12.custom.min.js',
					  	src_path + '/reporting/v1/includes/jquery/jquery.ui.selectmenu.js',
					  	src_path + '/reporting/v1/includes/jquery/chosen.jquery.js',
					  	src_path + '/reporting/v1/includes/jquery/jquery.sparkline.min.js',
					  	src_path + '/reporting/v1/includes/jquery/jquery.jqGrid.min.js',
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