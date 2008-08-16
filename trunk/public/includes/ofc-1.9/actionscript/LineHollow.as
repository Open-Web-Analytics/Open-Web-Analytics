class LineHollow extends LineStyle
{
	public var bgColour:Number=0;
	public var mcs:Array;
	
	public function LineHollow( lv:Object, name:String )
	{
		this.mcs=[];
		this.values = [];
		
		this.bgColour =  _root.get_background_colour();
		this.name = 'line_hollow'+name;
		
		var vals:Array = lv[this.name].split(",");
		this.line_width = Number( vals[0] );
		this.colour = _root.get_colour( vals[1] );
		
		if( vals.length > 2 )
			this.key = vals[2];
			
		if( vals.length > 3 )
			this.font_size = Number( vals[3] );
		
		if( length( vals ) > 4 )
			this.circle_size = Number( vals[4] );
		
		this.mc2 = _root.createEmptyMovieClip( name+'_hightlight', _root.getNextHighestDepth());
		this.mc2.lineStyle( 0, 0, 0);
		this.mc2.fillCircle( 0, 0, this.circle_size+2, 15, this.colour );
		this.mc2.fillCircle( 0, 0, this.circle_size-this.line_width+2, 15, this.bgColour);
		this.mc2._visible = false;
		// we need to remeber if the mouse
		// is over this movie clip
		this.mc2._is_over = false;
		
		this.set_values( lv['values'+name].split(",") );
		this.set_links( lv['links'+name] );
		this.set_tooltips( lv['tool_tips_set'+name] );
	}
	
	public function draw()
	{
		super.draw();
		
		if( this.circle_size == 0 )
			return;
		
		for( var i:Number=0; i < this.ExPoints.length; i++ )
		{
			var val:Point = this.ExPoints[i];
			this.mc.lineStyle( 0, 0, 0);
			this.mc.fillCircle( val.x, val.y, this.circle_size, 15, this.colour );
			this.mc.fillCircle( val.x, val.y, this.circle_size-this.line_width, 15, this.bgColour );
		}
	}
}