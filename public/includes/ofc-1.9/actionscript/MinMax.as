class MinMax
{
	public var y_min:Number;
	public var y_max:Number;
	public var y2_min:Number;
	public var y2_max:Number;
	public var x_min:Number;
	public var x_max:Number;
	
	// have we been given x_min and x_max?
	public var has_x_range:Boolean;
	
	function MinMax( lv:LoadVars )
	{
		if( lv.y_max == undefined )
			this.y_max = 10;
		else
			this.y_max = Number(lv.y_max)
			
		if( lv.y_min == undefined )
			this.y_min = 0;
		else
			this.y_min = Number(lv.y_min)
		
		// y 2
		if( lv.y2_max == undefined )
			this.y2_max = 10;
		else
			this.y2_max = Number(lv.y2_max)
			
		if( lv.y2_min == undefined )
			this.y2_min = 0;
		else
			this.y2_min = Number(lv.y2_min)
			
		//
		// what do you do if Y min=0 and Y max = 0?
		//
		if( this.y_min == this.y_max )
			this.y_max+=1;
		
		if( this.y2_min == this.y2_max )
			this.y2_max+=1;
		
		this.has_x_range = false;
		if( lv.x_max == undefined )
			this.x_max = 10;
		else
		{
			this.has_x_range = true;
			this.x_max = Number(lv.x_max)
		}
			
		if( lv.x_min == undefined )
			this.x_min = 0;
		else
		{
			this.has_x_range = true;
			this.x_min = Number(lv.x_min)
		}
	}
	
	function range( right:Boolean )
	{
		if( right )
			return this.y2_max-this.y2_min;
		else
			return this.y_max-this.y_min;
	}
	
	function min( right:Boolean )
	{
		if( right )
			return this.y2_min;
		else
			return this.y_min;
	}
}