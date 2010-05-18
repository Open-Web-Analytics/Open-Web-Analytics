OWA.resultSetExplorer = function(dom_id, options) {
	
	if (options) {
		this.options = options;
	}
	
	if (dom_id) {
		this.dom_id = dom_id;
	}
	
};

OWA.resultSetExplorer.prototype = {
	
	dom_id: '',
	gridInit: false,
	columnLinks: '',
	_columnLinksCount: 0,
	options: {},
	resultSet: [],
	
	getOption : function(name) {
		
		return this.options[name];
	},
	
	getRowValues : function(old) {
		
		var row = {};
		
		for (var item in old) {
			row[item] = old[item].value;
		}
	
		return row;
	},
	
	// makesa unqiue idfor each row
	makeRowGuid : function(row) {
		
	},
	
	loadFromArray : function(json, view) {
	
		view = view || 'grid';
		
		this.injectDomElements();
		this.setResultSet(json);
		this.displayGrid();
	},
	
	load : function(url, view) {
	
		view = view || 'grid';
		
		this.injectDomElements();
		this.getResultSet(url);
	},
	
	displayGrid : function () {
		this.setGridOptions();
		this.addAllRowsToGrid();
		this.makeGridPagination();
		this.gridInit = true;
	},
	
	refreshGrid : function(data) {
		
		if (data) {
			this.setResultSet(data);
		}
		
		var that = this;
		
		if (this.resultSet.resultsReturned > 0) {
		
			// happens with first results set when loading from URL.
			if (this.gridInit != true) {
				this.displayGrid();
			}
			
			
			jQuery("#"+that.dom_id + ' _grid').jqGrid('clearGridData',true);
			this.addAllRowsToGrid();	
			
			// hide the built in jqgrid loading divs. 
			jQuery("#load_"+that.dom_id+"_grid").hide(); 
			jQuery("#load_"+that.dom_id+"_grid").css("z-index", 101);
			
			// check to see if we need ot hide the previous page control.
			if (this.resultSet.page == 1) {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
			} else {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').show();
			}
			
		} else {
			jQuery("#"+that.dom_id).html("No data is available for this time period.");
		}		
	},
	
	// fetch the result set from the server
	getResultSet : function(url) {
		
		var that = this;
		
		// uses the built in jqgrid loading divs. just giveit a message and show it.
		jQuery("#load_"+that.dom_id+"_grid").html('Loading...');
		jQuery("#load_"+that.dom_id+"_grid").show(); 
		jQuery("#load_"+that.dom_id+"_grid").css("z-index", 1000);
		jQuery.getJSON(url, '', function (data) {that.refreshGrid(data)});
	},
	
	injectDomElements : function() {
	
		var p = '';
		
		p += '<table id="'+ this.dom_id + '_grid"></table>';
		p += '<div class="owa_genericHorizontalList owa_resultsExplorerBottomControls"><ul></ul></div>';
		
		var that = this;
		jQuery('#'+that.dom_id).append(p);
	},
	
	setGridOptions : function() {
	
		var that = this;
		
		var columns = [];
		
		for (var column in this.resultSet.resultsRows[0]) {
		
			var _sort_type = '';
			var _align = '';
			var _format = '';
			var _class = '';
			var _width = '';
			var _resizable = true;
			var _fixed = false;
			
			if (this.resultSet.resultsRows[0][column].result_type === 'dimension') {
				_align = 'left';
				_class = 'owa_dimensionGridCell';
			} else {
				_align = 'right';
				_class = 'owa_metricGridCell';
				_width = 100;
				_resizable = false;
				_fixed = true;
			}
			
			if (this.resultSet.resultsRows[0][column].data_type === 'string') {
				_sort_type = 'text';
			} else {
				_sort_type = 'number';
			}
			
			if (this.resultSet.resultsRows[0][column].link) {
				_format = 'urlFormatter';
			}
					
		 columns.push({name: this.resultSet.resultsRows[0][column].name +'.value', 
		 				index: this.resultSet.resultsRows[0][column].name +'.value', 
		 				label: this.resultSet.resultsRows[0][column].label, 
		 				sorttype: _sort_type, 
		 				align: _align, 
		 				formatter: _format, 
		 				classes: _class, 
		 				width: _width, 
		 				resizable: _resizable,
		 				fixed: _fixed,
		 				realColName: this.resultSet.resultsRows[0][column].name
		 			});
		
		}
		
		// custom formattter functions.
		jQuery.extend(jQuery.fn.fmatter , {
			// urlFormatter allows for a single param substitution.
    		urlFormatter : function(cellvalue, options, rowdata) {
    			var sub_value = options.rowId;
    			var name = options.colModel.realColName; 
    			//var name = 'actionName';
    			//alert(that.columnLinks[name].template);
    			var new_url = that.columnLinks[name].template.replace('%s', escape(sub_value)); 
    			var link =  '<a href="' + new_url + '">' + cellvalue + '</a>';
    			return link;
			}
		});
		
		
		jQuery("#"+ that.dom_id + '_grid').jqGrid({        
			jsonReader: {
		   		repeatitems: false,
		   		root: "resultsRows",
		   		cell: '',
		   		id: 0,
		   		page: 'page',
		   		total: 'total_pages',
		   		records: 'resultsReturned'
			},
			afterInsertRow: function(rowid, rowdata, rowelem) {
				
			},
			datatype: "local",
		   	colModel: columns,
		   	rownumbers: true,
		   	viewrecords: true,
		   	rowNum:that.resultsReturned,
		   	//caption: "My Caption",
		   	height: '100%',
		   	autowidth: true,
		   	hoverrows:false
		});
	},
	
	makeColumnDefinitions : function() {
	
	},
	
		
	addRowToGrid : function(row, id) {
	
		var that = this;
		
		rowid = id || that.makeRowGuid(row);
		
		jQuery("#"+that.dom_id + '_grid').jqGrid('addRowData',rowid,that.getRowValues(row));
	},
	
	addAllRowsToGrid :function() {
		
		var that = this; 
		jQuery("#"+that.dom_id + '_grid')[0].addJSONData(that.resultSet);
		this.displayRowCount();
	},
	
	displayRowCount : function() {
		
		if (this.resultSet.more) {

			var start = '';
			var end = '';
			if (this.resultSet.page === 1) {
				start = 1;
				end = this.resultSet.resultsReturned;
			} else {
				start = ((this.resultSet.page -1)  * this.resultSet.resultsPerPage) + 1;
				end = ((this.resultSet.page -1) * this.resultSet.resultsPerPage) + this.resultSet.resultsReturned; 	
			}
			
			var p = '<li class="owa_rowCount">';
			p += 'Results: '+ start + ' - ' + end;
			p = p + '</li>';
		
			var that = this;
			//alert ("#"+that.dom_id + '_grid' + ' > .owa_rowCount');
			var check = jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL > .owa_rowCount').html();
			//alert(check);
			if (check === null)	{
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL').append(p);
			} else {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_rowCount').html(p);			
			}
		}
	},
		
	makeGridPagination : function() {
		
		if (this.resultSet.more) {
			
			var that = this;
			
			var p = '';
			p = p + '<LI class="owa_previousPageControl">';
			p = p + '<span>Previous Page</span></LI>';
			jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL').append(p);
			jQuery(".owa_previousPageControl").bind('click', function() {that.pageGrid(that.resultSet.previous)});	
			
			var p = '';
			p = p + '<LI class="owa_nextPageControl">';
			p = p + '<span>Next Page</span></LI>';
			
			jQuery("#"+that.dom_id + ' > .owa_resultsExplorerBottomControls > UL').append(p);
			jQuery(".owa_nextPageControl").bind('click', function() {that.pageGrid(that.resultSet.next)});
			
			if (this.resultSet.page == 1) {
				jQuery("#"+that.dom_id +' > .owa_resultsExplorerBottomControls > UL > .owa_previousPageControl').hide();
			}
		}
	},
	
	pageGrid : function (url) {
	
		this.getResultSet(url);
		var that = this;
			
	},
	
	addLinkToColumn : function(col_name, link_template, sub_params) {
		this.columnLinks = {};
		if (col_name) {
			var item = {};
			item.name = col_name
			item.template = link_template
			item.params = sub_params;
			
			this.columnLinks[col_name] = item;
			item = '';
		}
		
		this._columnLinksCount++
		//alert(this.dom_id);
	},
	
	setResultSet : function(rs) {
		this.resultSet = rs;
		this.applyLinks();
	},
	
	applyLinks : function() {
		
		var p = '';
		
		if (this.resultSet.resultsRows.length > 0) {
			
			if (this._columnLinksCount > 0) {
			
				for(var i=0;i<=this.resultSet.resultsRows.length - 1;i++) {
					
					for (var y in this.columnLinks) {
						
						//alert(this.dom_id + ' : '+y);
						//alert(this.columnLinks[y]);
						if (this.resultSet.resultsRows[i][y].name.length > 0) {
							//if (this.resultSet.resultsRows[i][this.columnLinks[y]].name.length > 0) {
							for (var z in this.columnLinks[y].params) {
								
								var template = this.columnLinks[y].template.replace(this.columnLinks[y].params[z] + '=%s', this.columnLinks[y].params[z] + '=' + this.resultSet.resultsRows[i][this.columnLinks[y].params[z]].value); 
							}
							
							
							this.resultSet.resultsRows[i][this.columnLinks[y].name].link = template;
						}						
						
					}
				}
			}
		}
	}	
};