class YAxis
{
	private var _width:Number=0;
	private var ticks:YTicks;
	private var grid_colour:Number;
	private var axis_colour:Number;
	//private var count:Number;
	private var mc:MovieClip;
	private var line_width:Number = 2;
	
	private var min:Number;
	private var max:Number;
	private var steps:Number;
	
	private var right:Boolean;
	
	function YAxis( y_ticks:YTicks, lv:LoadVars, min:Number, max:Number, steps:Number, nr:Number )
	{
		// ticks: thin and wide ticks
		this.ticks = y_ticks;
		
		if( lv.y_grid_colour != undefined )
			this.grid_colour = _root.get_colour( lv.y_grid_colour );
		else
			this.grid_colour = 0xF5E1AA;
		
		this.right = (nr==2);
		if( !this.right )
		{
			if( lv.y_axis_colour != undefined )
				this.axis_colour = _root.get_colour( lv.y_axis_colour );
			else
				this.axis_colour = 0x784016;
		}
		else
		{
			if( lv.y2_axis_colour != undefined )
				this.axis_colour = _root.get_colour( lv.y2_axis_colour );
			else
				this.axis_colour = 0x784016;
		}
	
		//this.count = count;
		this.min = min;
		this.max = max;
		this.steps = steps;
		
		if( !this.right ) 
			this.mc = _root.createEmptyMovieClip( "y_axis", _root.getNextHighestDepth() );
	    else
			this.mc = _root.createEmptyMovieClip( "y_axis2", _root.getNextHighestDepth() );
	
		this._width = this.line_width + Math.max( this.ticks.small, this.ticks.big );
	}
	
	function move( box:Box )
	{
		if( this.right )
		{
			this._move_right( box );
		}
		else
		{
			this._move_left( box );
		}
	}
	
	function _move_left( box:Box )
	{
		// this should be an option:
		this.mc.clear();

		// Grid lines
		this.mc.lineStyle(1,this.grid_colour,100);

		// y axel grid lines
		var every:Number = (this.max-this.min)/this.steps;
		// Set opacity for the first line to 0 (otherwise it overlaps the x-axel line)
		//
		// Bug? Does this work on graphs with minus values?
		//
		var i2:Number=0;
		for( var i:Number=this.min; i<=this.max; i+=every )
		{
			var y:Number = box.getY(i);
			if(i2 == 0) 
				this.mc.lineStyle(1,this.grid_colour,0);
				
			this.mc.moveTo( box.left, y );
			this.mc.lineTo( box.right, y );

			if(i2 == 0) 
				this.mc.lineStyle(1,this.grid_colour,100);
			i2 +=1;
		}
		
		
		this.mc.lineStyle(this.line_width,this.axis_colour,100);
			
		this.mc.moveTo( box.left, box.top );
		this.mc.lineTo( box.left, box.bottom );	
		
		// ticks..
		var every:Number = (this.max-this.min)/this.steps;
		for( var i:Number=this.min; i<=this.max; i+=every )
		{
			// start at the bottom and work up:
			var y:Number = box.getY(i,false);

			this.mc.moveTo( box.left, y );
			if( i % this.ticks.steps == 0 )
				this.mc.lineTo( box.left-this.ticks.big, y );
			else
				this.mc.lineTo( box.left-this.ticks.small, y );
				
		}
	}
	
	function _move_right( box:Box )
	{
		// Create the new axel
		this.mc.clear();
		this.mc.lineStyle( this.line_width, this.axis_colour, 100 );
	
		this.mc.moveTo( box.right, box.top );
		this.mc.lineTo( box.right, box.bottom );	
		
		// create new ticks.. 
		var every:Number = (this.max-this.min)/this.steps;
		for( var i:Number=this.min; i<=this.max; i+=every )
		{
			// start at the bottom and work up:
			var y:Number = box.getY(i);
			this.mc.moveTo( box.right, y );
			if( i % this.ticks.steps == 0 )
				this.mc.lineTo( box.right+this.ticks.big, y );
			else
				this.mc.lineTo( box.right+this.ticks.small, y );
		}
	}
	
	function width()
	{
		return this._width;
	}
	
}