package charts.Elements {
	import charts.Elements.PointDotBase;
	import flash.display.BlendMode;
	import flash.display.Sprite;
	
	public class PointDot extends PointDotBase {
		
		public function PointDot( index:Number, style:Object ) {
			
			super( index, style );
			
			this.visible = true;
			
			this.graphics.lineStyle( 0, 0, 0 );
			this.graphics.beginFill( style.colour, 1 );
			this.graphics.drawCircle( 0, 0, style['dot-size'] );
			this.graphics.endFill();
			
			var s:Sprite = new Sprite();
			s.graphics.lineStyle( 0, 0, 0 );
			s.graphics.beginFill( 0, 1 );
			s.graphics.drawCircle( 0, 0, style['dot-size']+style['halo-size'] );
			s.blendMode = BlendMode.ERASE;
			
			this.line_mask = s;
			
			
			this.attach_events();
		}
	}
}

