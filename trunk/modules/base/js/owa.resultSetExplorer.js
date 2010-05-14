OWA.resultSetExplorer = function(options) {
	
	if (options) {
		this.options = options;
	}
	
};

OWA.resultSetExplorer.prototype = {
	
	dom_id: '',
	options: {
		url:'/wp-content/plugins/owa/api.php?owa_do=getResultSet&owa_metrics=actions&owa_dimensions=actionName&owa_period=this_week&owa_format=json'
	},
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
	
	setGridOptions : function() {
	
		var that = this;
		
		var columns = [];
		
		for (var column in this.resultSet.resultsRows[0]) {
		
			var _sort_type = '';
			var _align = '';
			
			if (this.resultSet.resultsRows[0][column].result_type === 'dimension') {
				_align = 'left';
			} else {
				_align = 'right';
			}
			
			if (this.resultSet.resultsRows[0][column].data_type === 'string') {
				_sort_type = 'text';
			} else {
				_sort_type = 'number';
			}
			 
		
		 columns.push({name: this.resultSet.resultsRows[0][column].name +'.value', index: this.resultSet.resultsRows[0][column].name +'.value', label: this.resultSet.resultsRows[0][column].label, sorttype: _sort_type, align: _align});
		
		}
		
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
			datatype: "local",
		   	colModel:
		   		columns
		   	//[
		   	//	{name:'actionName.value', index:'actionName.value',label:'Action Name', sorttype:'text', align:'left' },
		   	//	{name:'actions.value', index:'actions.value', label:'Actions', sorttype:'int', align:'right'}		
			//]
			,
		   	rownumbers: true,
		   	viewrecords: true,
		   	rowNum:5,
		   	//caption: "My Caption",
		   	height: '100%',
		   	autowidth: true
		});
	},
	
	makeColumnDefinitions : function() {
	
	},
	
	setResultSet : function(rs) {
		this.resultSet = rs;
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
		
		/*
if (this.resultSet.resultsRows.length > 0) {
			
			for(var i=0;i<=this.resultSet.resultsRows.length - 1;i++) {
				
				this.addRowToGrid(this.resultSet.resultsRows[i], i+this.resultSet.offset+1);
			}
		
		}
*/
	},
	
	displayRowCount : function() {
		
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
	
	refreshGrid : function(data) {
		this.setResultSet(data);
		var that = this;
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
		
	},
	
	displayGrid : function () {
		this.setGridOptions();
		this.addAllRowsToGrid();
		this.makeGridPagination();
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
			
	}	
	
	
};