import mx.transitions.Tween;
import mx.transitions.easing.*;

class Tooltip
{
	
	private var mc:MovieClip;
	private var mc2:MovieClip;
	
	private var x:Number;
	private var y:Number;
	//private var myTween:Tween;
	
	public function Tooltip()
	{
		this.mc = _root.createEmptyMovieClip( "tooltipX", _root.getNextHighestDepth() );
		this.mc.rect2( 0, 0, 10, 10, 0, 50 );
		this.mc._visible = false;
		
		// create the title:
		this.mc.createTextField( "txt_title", this.mc.getNextHighestDepth(), 5, 5, 100, 100);
		
		// the tooltip body text:
		this.mc.createTextField( "txt", this.mc.getNextHighestDepth(), 5, 5, 100, 100);
		
		// NetVicious, June, 2007
		// create shadow filter
		var dropShadow = new flash.filters.DropShadowFilter();
		dropShadow.blurX = 4;
		dropShadow.blurY = 4;
		dropShadow.distance = 4;
		dropShadow.angle = 45;
		dropShadow.quality = 2;
		dropShadow.alpha = 0.5;
		// apply shadow filter
		this.mc.filters = [dropShadow];
		
		this.mc._alpha_original = 100;
		this.mc._hide = function() {trace(typeof(this));ChartUtil.FadeOut(this); };
	}
	
	public function draw( p:Point )
	{
		var pos:Object = p.get_tip_pos();
		
		if( this.mc._visible && ( this.x == pos.x ) && ( this.y == pos.y ) )
			return;	// <-- not a new tooltip, do nothing
		
		this.x = pos.x;
		this.y = pos.y;

		this.mc.clear();
		
		var old_x:Number = this.mc._x;
		var old_y:Number = this.mc._y;
		
		this.mc._x = pos.x;
		this.mc._y = pos.y-20;
		
		var lines:Array = p.tooltip.split( '<br>' );
		
		if( lines.length > 1 )
			this.mc.txt_title.text = lines.shift();
		else
			this.mc.txt_title.text = '';
			
		var fmt:TextFormat = new TextFormat();
		fmt.color = 0x0000F0;
		fmt.font = "Verdana";
		// this needs to be an option:
		fmt.bold = true;
		fmt.size = 12;
		fmt.align = "right";
		this.mc.txt_title.setTextFormat(fmt);
		this.mc.txt_title.autoSize="left";
	
		this.mc.txt._y = this.mc.txt_title._height;
		this.mc.txt.text = lines.join( '\n' );
		var fmt2:TextFormat = new TextFormat();
		fmt2.color = 0x000000;
		fmt2.font = "Verdana";
		fmt2.size = 12;
		fmt2.align = "left";
		this.mc.txt.setTextFormat(fmt2);
		this.mc.txt.autoSize="left";
		
		var max_width:Number = Math.max( this.mc.txt_title._width, this.mc.txt._width );
		var y_pos:Number = this.mc._y - this.mc.txt_title._height - this.mc.txt._height;
		
		if( y_pos < 0 )
		{
			// the tooltip has drifted off the top of the screen, move it down:
			y_pos = this.mc._y + this.mc.txt_title._height + this.mc.txt._height;
		}
		
		var cstroke = {width:2, color:0x808080, alpha:100};
		var ccolor = {color:0xf0f0f0, alpha:100};

		ChartUtil.rrectangle(
			this.mc,
			max_width+10,
			this.mc.txt_title._height + this.mc.txt._height + 5,
			6,
			((x+max_width+16) > Stage.width ) ? (Stage.width-max_width-16) : x,
			y_pos,
			cstroke,
			ccolor);

		this.mc._visible = true;
		var t:Tween = new Tween( this.mc, "_x", Strong.easeOut, old_x, this.mc._x, 10, false );
		var u:Tween = new Tween( this.mc, "_y", Strong.easeOut, old_y, this.mc._y, 10, false );
	}
	
	public function hide()
	{
		//this.myTween = new Tween( this.mc, "_alpha", mx.transitions.easing.Regular.easeOut, this.mc._x, x, 10, false );
		var t:Tween = new Tween( this.mc, "_alpha", Regular.easeOut, 100, 0, 20, false);
		var tmp = this;
		t.onMotionFinished = function() {
			tmp.mc._visible = false;
			tmp.mc._alpha = 100;
		};
	}
/*
	public function hide__()
	{
		this.mc.onEnterFrame = function ()
		{
			this._alpha -= 5;
			if( this._alpha < 0 )
			{
				this._visible = false;
				this._alpha = 100;
				delete this.onEnterFrame;
			}
		};
	}
*/
}