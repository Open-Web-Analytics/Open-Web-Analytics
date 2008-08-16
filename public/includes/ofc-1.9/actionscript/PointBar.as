class PointBar extends Point
{
	public var width:Number;
	public var bar_bottom:Number;
	
	public function PointBar( x:Number, y:Number, width:Number, bar_bottom:Number )
	{
		super( x, y );
		this.width = width;
		this.bar_bottom = bar_bottom;
	}
	
	function get_tip_pos()
	{
		return {x:this.x+(this.width/2), y:this.y};
	}
}