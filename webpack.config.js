const path = require('path');
const dist_path = '/modules/base/dist';
const reporting_src_path = __dirname + '/modules/base/js/src/reporting/v1/';
const terser = require('terser');
const WebpackConcatPlugin = require('webpack-concat-files-plugin');
const TerserPlugin = require('terser-webpack-plugin');

module.exports = {

	entry: {
    
	    'owa.tracker.js': [
		    
	    	path.resolve(__dirname, '/modules/base/js/src/tracker/tracker-dom.js')
	    ],
	    
	},
  
	output: {
	  
	  	path: __dirname + dist_path + '/js', // Output to dist directory
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
		          	dest: __dirname + dist_path + '/js/owa.reporting-combined-min.js',
				  	src: [
			          
			          	reporting_src_path + 'includes/jquery/jquery-1.6.4.min.js',
					  	reporting_src_path + 'includes/jquery/jquery.sprintf.js',
					  	reporting_src_path + 'includes/jquery/jquery-ui-1.8.12.custom.min.js',
					  	reporting_src_path + 'includes/jquery/jquery.ui.selectmenu.js',
					  	reporting_src_path + 'includes/jquery/chosen.jquery.js',
					  	reporting_src_path + 'includes/jquery/jquery.sparkline.min.js',
					  	reporting_src_path + 'includes/jquery/jquery.jqGrid.min.js',
					  	reporting_src_path + 'includes/jquery/flot_v0.7/jquery.flot.min.js',
					  	reporting_src_path + 'includes/jquery/flot_v0.7/jquery.flot.resize.min.js',
					  	reporting_src_path + 'includes/jquery/flot_v0.7/jquery.flot.pie.min.js',
					  	reporting_src_path + 'includes/jquery/jQote2/jquery.jqote2.min.js',
					  	reporting_src_path + 'owa.js',
					  	reporting_src_path + 'owa.report.js',
					  	reporting_src_path + 'owa.resultSetExplorer.js',
					  	reporting_src_path + 'owa.sparkline.js',
					  	reporting_src_path + 'owa.areachart.js',
					  	reporting_src_path + 'owa.piechart.js',
					  	reporting_src_path + 'owa.kpibox.js',
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