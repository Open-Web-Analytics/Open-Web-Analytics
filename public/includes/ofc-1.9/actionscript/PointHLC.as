class PointHLC extends Point
{
//	private var numDecimals:Number =5;
//	private var isFixedNumDecimalsForced:Boolean =true;
//	private var isDecimalSeparatorComma:Boolean =true;
	
	public var width:Number;
	public var bar_bottom:Number;
	public var high:Number;
	public var close:Number;
	public var low:Number;
	
	public function PointHLC( x:Number, high:Number, close:Number, low:Number, tooltip:Number, width:Number )
	{
		super( x, high, tooltip );
		
		this.width = width;
		this.high = high;
		this.close = close;
		this.low = low;
	}
	
	public function make_tooltip( tip:String, key:String, val:Object, x_legend:String, x_axis_label:String )
	{
		super.make_tooltip( tip, key, val.close, x_legend, x_axis_label );
		
		var tmp:String = this.tooltip;
		tmp = tmp.replace('#high#',NumberUtils.formatNumber(val.high));
		tmp = tmp.replace('#close#',NumberUtils.formatNumber(val.close));
		tmp = tmp.replace('#low#',NumberUtils.formatNumber(val.low));
		
		this.tooltip = tmp;
	}
	
	function get_tip_pos()
	{
		return {x:this.x+(this.width/2), y:this.y};
	}
}