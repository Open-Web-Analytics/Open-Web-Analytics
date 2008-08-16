class PointCandle extends Point
{
	public var width:Number;
	public var bar_bottom:Number;
	public var high:Number;
	public var open:Number;
	public var close:Number;
	public var low:Number;
	
	public function PointCandle( x:Number, high:Number, open:Number, close:Number, low:Number, tooltip:Number, width:Number )
	{
		super( x, high, tooltip );
		
		this.width = width;
		this.high = high;
		this.open = open;
		this.close = close;
		this.low = low;
	}
	
	public function make_tooltip( tip:String, key:String, val:Object, x_legend:String, x_axis_label:String )
	{
		super.make_tooltip( tip, key, val.open, x_legend, x_axis_label );
		
		var tmp:String = this.tooltip;
		tmp = tmp.replace('#high#',_root.format(val.high));
		tmp = tmp.replace('#open#',_root.format(val.open));
		tmp = tmp.replace('#close#',_root.format(val.close));
		tmp = tmp.replace('#low#',_root.format(val.low));
		
		this.tooltip = tmp;
	}
	
	function get_tip_pos()
	{
		return {x:this.x+(this.width/2), y:this.y};
	}
}