class Point
{
	//
	// for line data
	//
	public var x:Number;
	public var y:Number;
	public var tooltip:String;
	
	public var is_tip:Boolean;
	
	public function Point( x:Number, y:Number )
	{
		this.x = x;
		this.y = y;
		this.is_tip = false;
	}
	
	public function make_tooltip( tip:String, key:String, val:Number, x_legend:String, x_axis_label:String, tip_set:String )
	{
		var tmp:String;
		
		var tip_obj = {x_label:this.tooltip, value:'-99', key:'moo'};
		//
		// Dirty hack. Takes a tool_tip_wrapper, and replaces the #val# with the
		// tool_tip text, so noew you can do: "My Val = $#val#%", which turns into:
		// "My Val = $12.00%"
		//
		if( _root.tool_tip_wrapper != undefined )
		{
			tmp = tip.replace('#val#',_root.format(val));
			tmp = tmp.replace('#val:number#', NumberUtils.formatNumber (Number(val)));
			tmp = tmp.replace('#key#',key);
			tmp = tmp.replace('#x_label#',x_axis_label);
			tmp = tmp.replace('#val:time#',_root.formatTime(val));
			tmp = tmp.replace('#x_legend#',x_legend);
			
			// the user can add extra tooltips per
			// data set (may be undefined):
			if( tip_set != undefined )
				tmp = tmp.replace('#tip#',tip_set);
		}
		else
		{
			if( x_axis_label.length == 0 )
				tmp = _root.format(val);
			else
				tmp = x_axis_label+'<br>'+_root.format(val);
		}
			
		this.tooltip = tmp;
	}
	
	function get_tip_pos()
	{
		return {x:this.x, y:this.y};
	}
	
	public function toString()
	{
		return "x :"+ this.x;
	}
}