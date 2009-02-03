package charts.Elements {
	import flash.display.Sprite;
	import flash.display.BlendMode;
	
	
	public class Point extends PointDotBase {
		
		public function Point( index:Number, style:Object )
		{
			super( index, style );
			
			this.graphics.lineStyle( 0, 0, 0 );
			this.graphics.beginFill( style.colour, 1 );
			this.graphics.drawCircle( 0, 0, style['dot-size'] );
			this.attach_events();
			
			var s:Sprite = new Sprite();
			s.graphics.lineStyle( 0, 0, 0 );
			s.graphics.beginFill( 0, 1 );
			s.graphics.drawCircle( 0, 0, style['dot-size']+style['halo-size'] );
			s.blendMode = BlendMode.ERASE;
			s.visible = false;
			
			this.line_mask = s;
		}
		
		public override function set_tip( b:Boolean ):void {
			this.visible = b;
			this.line_mask.visible = b;
		}
		
		/*
		 *
		public override function make_tooltip( key:String ):void
		{
			super.make_tooltip( key );
			
			var tmp:String = this.tooltip.replace('#val#',NumberUtils.formatNumber( this._y ));
			this.tooltip = tmp;
		}
		
		public function mask_parent( s:Sprite ):void {
		}
		
		//
		// is the mouse above, inside or below this point?
		//
		public override function inside( x:Number ):Boolean {
			return (x > (this.x-(this.radius/2))) && (x < (this.x+(this.radius/2)));
		}
		*/
	}
}