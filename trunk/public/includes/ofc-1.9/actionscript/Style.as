class Style
{
	public var key:String = '';
	public var font_size:Number = -1;
	public var colour:Number = 0x000000;
	public var line_width:Number = 1;
	public var circle_size:Number = 0;
	
	//
	public var is_bar:Boolean = false;
	public var alpha:Number = 50;		// <- transparancy
	
	public var values:Array;
	public var ExPoints:Array;
	
	// array to hold the on_click links
	private var links:Array;
	// array to hold the extra tool tip info
	private var tooltips:Array;
	
	public function Style( val:String, bar:Boolean )
	{
	}
	
	function set_values( v:Array )
	{
		this.values = v;
	}
	
	// called from external interface (JS)
	public function add( val:String, tool_tip:String )
	{
		this.values.push( val );
	}
	
	// called from external interface (JS)
	public function del()
	{
		this.values.shift();
	}

	public function draw( val, mc )
	{}
	
	public function highlight_value()
	{}
	
	public function closest( x:Number, y:Number )
	{}
	
	private function set_links( links:String )
	{
		if( links != undefined )
		{
			this.links = links.split(",");
			for( var i=0; i<this.links.length; i++ )
				this.links[i] = this.links[i].replace('#comma#',',');
		}
		else
			this.links = Array();
	}
	
	// remember the extra tool tip info:
	private function set_tooltips( tooltips:String )
	{
		if( tooltips != undefined )
		{
			this.tooltips = tooltips.split(",");
			for( var i=0; i<this.tooltips.length; i++ )
				this.tooltips[i] = this.tooltips[i].replace('#comma#',',');
		}
		else
			this.tooltips = Array();
		
	}

}