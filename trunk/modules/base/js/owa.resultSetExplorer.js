OWA.resultSetExplorer = function(options) {
	
	if (options) {
		this.options = options;
	}
	
}

OWA.resultSetExplorer.prototype = {
	
	dom_id: '',
	options: {
		url:'/wp-content/plugins/owa/api.php?owa_do=getResultSet&owa_metrics=actions&owa_dimensions=actionName&owa_period=this_week&owa_format=json'
	},
	resultSet: new Array(),
	
	getOption : function(name) {
		
		return this.options[name];
	},
	
	getRowValues : function(old) {
		
		var row = new Object();
		
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
		
		jQuery(that.dom_id).jqGrid({        
		   	//url:'/wp-content/plugins/owa/api.php?owa_do=getResultSet&owa_metrics=actions&owa_dimensions=actionName&owa_period=this_week&owa_format=json',
		   	jsonReader: {
		   		repeatitems: false
		   		//root: "resultsRows",
		   		//cell: ''
		   	},
			datatype: "local",
		   	colModel:[
		   		{name:'actionName', index:'actionName',label:'Action Name', sorttype:'text', align:'left' },
		   		{name:'actions', index:'actions', label:'Actions', sorttype:'int', align:'right'}		
		   	],
		   	rowNum:2,
		   	rownumbers: true,
		    viewrecords: true,
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
		
		jQuery(that.dom_id).jqGrid('addRowData',rowid,that.getRowValues(row));
	},
	
	addAllRowsToGrid :function() {
	
		this.setGridOptions();
	
		if (this.resultSet.resultsRows.length > 0) {
			
			for(var i=0;i<=this.resultSet.resultsRows.length - 1;i++) {
				
				this.addRowToGrid(this.resultSet.resultsRows[i], i+1);
			}
		
		}
	},
	
	// fetch the result set from the server
	getResultSet : function() {
		
		var that = this;
		
		jQuery.getJSON(that.getOption('url'), that.setResultSet(data));
	},
	
	refreshGrid : function() {
		
		var that = this;
		jQuery(that.dom_id).jqGrid('clearGridData',true);
		this.addAllRowsToGrid();	
	}
}