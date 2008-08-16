class BarZebra extends BarStyle
{
	public function BarZebra( val:String, name:String )
	{
		super( val, name );
	}
	
	private function bg( mc:MovieClip, val:PointBar )
	{
		//
		var w:Number = val.width;
		var h:Number = val.bar_bottom-val.y;
		var x:Number = val.x;
		var y:Number = val.y;
		var rad:Number = 10;
		
		mc.lineStyle( undefined, 0xFFFFFF, 100);
		mc.beginFill( 0xFFFFFF, 100);
		mc.moveTo(0+rad, 0);
		mc.lineTo(w-rad, 0);
		mc.curveTo(w, 0, w, rad);
		mc.lineTo(w, h);
		mc.lineTo(0, h);
		mc.lineTo(0, 0+rad);
		mc.curveTo(0, 0, 0+rad, 0);
		mc.endFill();
		mc._x = x;
		mc._y = y;
	};
	
	private function bg2( mc:MovieClip, val:PointBar )
	{
		//
		var w:Number = val.width;
		var h:Number = val.bar_bottom-val.y;
		var x:Number = val.x;
		var y:Number = val.y;
		var rad:Number = 10;
		
		mc.lineStyle( undefined, 0xFFFFFF, 100);
		mc.beginFill( 0xFF0000, 50);
		mc.moveTo(0, 0);
		mc.lineTo(w, 20);
		mc.lineTo(0, 20);
		mc.lineTo(0, 0);
		mc.endFill();
		mc._x = x;
		mc._y = y;
	};
	
	public function draw_bar( val:PointBar, i:Number )
	{
		var mc:MovieClip = this.bar_mcs[i];
		mc.clear();
		this.bg( mc, val );
		
		var mc_o = mc.createEmptyMovieClip('overlay', mc.getNextHighestDepth());
		
		this.bg2( mc_o, val );
		
		var dropShadow = new flash.filters.DropShadowFilter();
		dropShadow.blurX = 5;
		dropShadow.blurY = 5;
		dropShadow.distance = 3;
		dropShadow.angle = 45;
		dropShadow.quality = 2;
		dropShadow.alpha = 0.4;
		mc.filters = [dropShadow];
		
		return;
		var top:Number;
		var height:Number;
		
		if(val.bar_bottom<val.y)
		{
			top = val.bar_bottom;
			height = val.y-val.bar_bottom;
		}
		else
		{
			top = val.y
			height = val.bar_bottom-val.y;
		}
		
		//set gradient fill
		var colors:Array = [this.colour,0xFFFFFF];
		var alphas:Array = [100,0];
		var ratios:Array = [0,255];
		var matrix:Object = { matrixType:"box", x:0, y:0, w:val.width, h:height, r:(90/180)*Math.PI };
		mc.beginGradientFill("linear", colors, alphas, ratios, matrix);
		
		
		//mc.beginFill( this.colour, 100 );
		mc.moveTo( 0, 0 );
    	mc.lineTo( val.width, 0 );
    	mc.lineTo( val.width, height );
    	mc.lineTo( 0, height );
		mc.lineTo( 0, 0 );
    	mc.endFill();
		
		mc._x = val.x;
		mc._y = top;
		
		mc._alpha = this.alpha;
		mc._alpha_original = this.alpha;	// <-- remember our original alpha while tweening
		
		// this is used in _root.FadeIn and _root.FadeOut
		//mc.val = val;
		
		// we return this MovieClip to FilledBarStyle
		return mc;
	}
}
